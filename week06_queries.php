<?php
session_start();
require_once "../includes/db.php";

function e($value): string
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function formatLabel(string $value): string
{
    return ucwords(str_replace("_", " ", $value));
}

function statusClass(string $status): string
{
    return match (strtolower($status)) {
        "available", "returned", "completed" => "success",
        "borrowed" => "primary",
        "cancelled", "unavailable" => "danger",
        "pending" => "warning",
        default => "secondary"
    };
}

/* =========================================================
   MODULE CONFIGURATION
========================================================= */

$view = $_GET["view"] ?? "borrowers";

$allowedViews = [
    "borrowers", "equipment", "transactions", "items", "grouped"
];

if (!in_array($view, $allowedViews, true)) {
    $view = "borrowers";
}

$search = trim($_GET["search"] ?? "");
$filter = $_GET["filter"] ?? "";
$sort = $_GET["sort"] ?? "default";

$allowedFilters = [
    "borrowers" => ["", "Student", "Faculty", "Staff"],
    "equipment" => ["", "available", "unavailable"],
    "transactions" => ["", "Borrowed", "Returned", "Completed", "Cancelled"],
    "items" => [""],
    "grouped" => [""]
];

if (!in_array($filter, $allowedFilters[$view], true)) {
    $filter = "";
}

/* =========================================================
   SORTING CONFIGURATION
   Only predefined SQL expressions are permitted.
========================================================= */

$sortOptions = [

    "borrowers" => [
        "default" => "b.full_name ASC",
        "name_desc" => "b.full_name DESC",
        "number" => "b.borrower_no ASC",
        "type" => "b.borrower_type ASC"
    ],

    "equipment" => [
        "default" => "e.equipment_name ASC",
        "name_desc" => "e.equipment_name DESC",
        "property" => "e.property_no ASC",
        "quantity_asc" => "e.quantity_available ASC",
        "quantity_desc" => "e.quantity_available DESC"
    ],

    "transactions" => [
        "default" => "bt.borrow_date DESC, bt.borrow_id DESC",
        "oldest" => "bt.borrow_date ASC",
        "newest" => "bt.borrow_date DESC",
        "due_date" => "bt.due_date ASC"
    ],

    "items" => [
        "default" => "bi.borrow_item_id DESC",
        "quantity_asc" => "bi.quantity ASC",
        "quantity_desc" => "bi.quantity DESC"
    ],

    "grouped" => [
        "default" => "bt.status ASC",
        "transactions_desc" => "total_transactions DESC",
        "transactions_asc" => "total_transactions ASC",
        "quantity_desc" => "total_quantity_borrowed DESC",
        "quantity_asc" => "total_quantity_borrowed ASC"
    ]

];

if (!isset($sortOptions[$view][$sort])) {
    $sort = "default";
}

/* =========================================================
   MODULE INFORMATION
========================================================= */

$moduleInfo = [

    "borrowers" => [
        "title" => "Borrower Records",
        "subtitle" => "Retrieve, search, filter, and organize registered borrowers.",
        "icon" => "bi-people",
        "concept" => "SELECT · WHERE · ILIKE · ORDER BY",
        "purpose" => "Retrieve borrower information based on selected conditions.",
        "explanation" => "This query demonstrates how SELECT retrieves specific columns from a table. WHERE limits the records based on conditions, ILIKE performs case-insensitive searches, and ORDER BY arranges the results.",
        "expected" => "A list of registered borrowers containing their borrower number, full name, and borrower type."
    ],

    "equipment" => [
        "title" => "Equipment Inventory",
        "subtitle" => "Monitor equipment records and available inventory quantities.",
        "icon" => "bi-laptop",
        "concept" => "SELECT · WHERE · ILIKE · ORDER BY",
        "purpose" => "Retrieve equipment records and determine their availability.",
        "explanation" => "This query retrieves equipment details and applies conditions to identify available or unavailable equipment. Sorting can be performed using equipment names, property numbers, and available quantities.",
        "expected" => "An inventory list showing equipment names, property numbers, and available quantities."
    ],

    "transactions" => [
        "title" => "Borrowing Transactions",
        "subtitle" => "Examine borrowing activity and its relationship with borrower records.",
        "icon" => "bi-journal-check",
        "concept" => "INNER JOIN · LEFT JOIN · GROUP BY · COUNT · SUM",
        "purpose" => "Retrieve transaction information together with borrower details and borrowed item totals.",
        "explanation" => "INNER JOIN connects borrowing transactions to their corresponding borrowers. LEFT JOIN includes transactions even when no borrowed item records exist. COUNT calculates the number of item records, while SUM calculates the total borrowed quantity.",
        "expected" => "A transaction report containing borrower details, transaction dates, status, number of item records, and total quantity."
    ],

    "items" => [
        "title" => "Borrowed Item Records",
        "subtitle" => "Inspect individual borrowed equipment records and their related transactions.",
        "icon" => "bi-box-seam",
        "concept" => "INNER JOIN · WHERE · ILIKE · ORDER BY",
        "purpose" => "Retrieve individual borrowed equipment records using related database tables.",
        "explanation" => "This query connects borrowed items, transactions, borrowers, and equipment. INNER JOIN retrieves records with matching relationships across the tables.",
        "expected" => "A detailed list of borrowed equipment showing the borrower, equipment, quantity, borrowing date, due date, and transaction status."
    ],

    "grouped" => [
        "title" => "Grouped Transaction Summary",
        "subtitle" => "Analyze borrowing activity through aggregate functions and grouping.",
        "icon" => "bi-bar-chart",
        "concept" => "GROUP BY · COUNT · SUM · ORDER BY",
        "purpose" => "Summarize borrowing activity according to transaction status.",
        "explanation" => "GROUP BY combines transactions with the same status. COUNT calculates transaction, borrower, and item-record totals. SUM calculates the total quantity borrowed. ORDER BY sorts the aggregated results.",
        "expected" => "A summarized report displaying the number of transactions, distinct borrowers, item records, and borrowed quantities for each status."
    ]

];

$info = $moduleInfo[$view];

/* =========================================================
   INITIAL VALUES
========================================================= */

$stats = [
    "total_borrowers" => 0,
    "total_equipment" => 0,
    "total_transactions" => 0,
    "total_available" => 0
];

$records = [];
$columns = [];
$params = [];
$sql = "";
$errorMessage = "";
$executionTime = 0;
$hasExecuted = false;

try {

    /* =====================================================
       DASHBOARD STATISTICS
    ===================================================== */

    $statsQuery = $pdo->query("
        SELECT

            (SELECT COUNT(*)
             FROM public.borrowers)
             AS total_borrowers,

            (SELECT COUNT(*)
             FROM public.equipment)
             AS total_equipment,

            (SELECT COUNT(*)
             FROM public.borrow_transactions)
             AS total_transactions,

            (SELECT COALESCE(SUM(quantity_available), 0)
             FROM public.equipment)
             AS total_available

    ");

    $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

    /* =====================================================
       BORROWER RECORDS
    ===================================================== */

    if ($view === "borrowers") {

        $sql = "
            SELECT
                b.borrower_id,
                b.borrower_no,
                b.full_name,
                b.borrower_type

            FROM public.borrowers AS b

            WHERE 1 = 1
        ";

        if ($search !== "") {

            $sql .= "
                AND (
                    b.borrower_no ILIKE :search1
                    OR b.full_name ILIKE :search2
                )
            ";

            $keyword = "%{$search}%";

            $params["search1"] = $keyword;
            $params["search2"] = $keyword;
        }

        if ($filter !== "") {

            $sql .= " AND b.borrower_type = :borrower_type";

            $params["borrower_type"] = $filter;
        }

    }

    /* =====================================================
       EQUIPMENT INVENTORY
    ===================================================== */

    elseif ($view === "equipment") {

        $sql = "
            SELECT
                e.equipment_id,
                e.property_no,
                e.equipment_name,
                e.quantity_available

            FROM public.equipment AS e

            WHERE 1 = 1
        ";

        if ($search !== "") {

            $sql .= "
                AND (
                    e.property_no ILIKE :search1
                    OR e.equipment_name ILIKE :search2
                )
            ";

            $keyword = "%{$search}%";

            $params["search1"] = $keyword;
            $params["search2"] = $keyword;
        }

        if ($filter === "available") {

            $sql .= " AND e.quantity_available > 0";

        } elseif ($filter === "unavailable") {

            $sql .= " AND e.quantity_available = 0";
        }

    }

    /* =====================================================
       BORROWING TRANSACTIONS
    ===================================================== */

    elseif ($view === "transactions") {

        $sql = "
            SELECT
                bt.borrow_id,
                bt.borrow_date,
                bt.due_date,
                bt.status,

                b.borrower_no,
                b.full_name,
                b.borrower_type,

                COUNT(bi.borrow_item_id) AS total_item_records,

                COALESCE(SUM(bi.quantity), 0) AS total_quantity

            FROM public.borrow_transactions AS bt

            INNER JOIN public.borrowers AS b
                ON b.borrower_id = bt.borrower_id

            LEFT JOIN public.borrow_items AS bi
                ON bi.borrow_id = bt.borrow_id

            WHERE 1 = 1
        ";

        if ($search !== "") {

            $sql .= "
                AND (
                    b.borrower_no ILIKE :search1
                    OR b.full_name ILIKE :search2
                    OR CAST(bt.borrow_id AS TEXT) ILIKE :search3
                )
            ";

            $keyword = "%{$search}%";

            $params["search1"] = $keyword;
            $params["search2"] = $keyword;
            $params["search3"] = $keyword;
        }

        if ($filter !== "") {

            $sql .= " AND bt.status = :status";

            $params["status"] = $filter;
        }

        $sql .= "
            GROUP BY
                bt.borrow_id,
                bt.borrow_date,
                bt.due_date,
                bt.status,
                b.borrower_no,
                b.full_name,
                b.borrower_type
        ";

    }

    /* =====================================================
       BORROWED ITEM RECORDS
    ===================================================== */

    elseif ($view === "items") {

        $sql = "
            SELECT
                bi.borrow_item_id,
                bi.borrow_id,

                b.borrower_no,
                b.full_name,

                e.property_no,
                e.equipment_name,

                bi.quantity,

                bt.borrow_date,
                bt.due_date,
                bt.status

            FROM public.borrow_items AS bi

            INNER JOIN public.borrow_transactions AS bt
                ON bt.borrow_id = bi.borrow_id

            INNER JOIN public.borrowers AS b
                ON b.borrower_id = bt.borrower_id

            INNER JOIN public.equipment AS e
                ON e.equipment_id = bi.equipment_id

            WHERE 1 = 1
        ";

        if ($search !== "") {

            $sql .= "
                AND (
                    b.borrower_no ILIKE :search1
                    OR b.full_name ILIKE :search2
                    OR e.property_no ILIKE :search3
                    OR e.equipment_name ILIKE :search4
                )
            ";

            $keyword = "%{$search}%";

            $params["search1"] = $keyword;
            $params["search2"] = $keyword;
            $params["search3"] = $keyword;
            $params["search4"] = $keyword;
        }

    }

    /* =====================================================
       GROUPED TRANSACTION SUMMARY
    ===================================================== */

    elseif ($view === "grouped") {

        $sql = "
            SELECT

                bt.status,

                COUNT(DISTINCT bt.borrow_id)
                    AS total_transactions,

                COUNT(DISTINCT bt.borrower_id)
                    AS total_borrowers,

                COUNT(bi.borrow_item_id)
                    AS total_item_records,

                COALESCE(SUM(bi.quantity), 0)
                    AS total_quantity_borrowed

            FROM public.borrow_transactions AS bt

            LEFT JOIN public.borrow_items AS bi
                ON bi.borrow_id = bt.borrow_id

            GROUP BY bt.status
        ";

    }

    /* =====================================================
       SAFE SORTING
    ===================================================== */

    $sql .= " ORDER BY " . $sortOptions[$view][$sort];

    /* =====================================================
       QUERY EXECUTION AND PERFORMANCE
    ===================================================== */

    $startTime = microtime(true);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $executionTime = (microtime(true) - $startTime) * 1000;

    $hasExecuted = true;

    if (!empty($records)) {
        $columns = array_keys($records[0]);
    }

} catch (PDOException $exception) {

    error_log("Week 6 SQL error: " . $exception->getMessage());

    $errorMessage = "The database query could not be completed. Please verify the database connection, table structure, and permissions.";

}

/* =========================================================
   RESULT ANALYSIS
========================================================= */

$resultCount = count($records);

$totalDisplayedQuantity = 0;
$totalGroupedTransactions = 0;

foreach ($records as $record) {

    if (isset($record["quantity"])) {
        $totalDisplayedQuantity += (int)$record["quantity"];
    }

    if (isset($record["total_quantity"])) {
        $totalDisplayedQuantity += (int)$record["total_quantity"];
    }

    if (isset($record["total_quantity_borrowed"])) {
        $totalDisplayedQuantity += (int)$record["total_quantity_borrowed"];
    }

    if (isset($record["total_transactions"])) {
        $totalGroupedTransactions += (int)$record["total_transactions"];
    }
}

/* =========================================================
   CHART DATA
========================================================= */

$chartLabels = [];
$chartValues = [];

if ($view === "grouped") {

    foreach ($records as $record) {

        $chartLabels[] = $record["status"];

        $chartValues[] = (int)$record["total_transactions"];
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Week 6 SQL Operations | Database Administration</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

        :root {
            --week-primary: #315cc5;
            --week-dark: #172b50;
            --week-muted: #8190a7;
            --week-border: #e9edf4;
            --week-light: #f5f8fd;
        }

        .content-area {
            padding-bottom: 35px;
        }

        .stat-card,
        .table-card,
        .query-card,
        .concept-card {
            background: #fff;
            border: 1px solid var(--week-border);
            border-radius: 16px;
            padding: 21px;
            height: 100%;
        }

        .stat-card {
            min-height: 145px;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(24, 50, 90, .07);
        }

        .stat-icon {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .stat-number {
            font-size: 29px;
            font-weight: 800;
            color: var(--week-dark);
            margin-top: 12px;
            line-height: 1.2;
        }

        .stat-label,
        .query-label {
            font-size: 10px;
            font-weight: 800;
            color: var(--week-muted);
            letter-spacing: .7px;
            text-transform: uppercase;
        }

        .welcome-section {
            margin-bottom: 25px;
        }

        .module-nav {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }

        .module-nav a {
            text-decoration: none;
            border: 1px solid var(--week-border);
            padding: 10px 15px;
            border-radius: 10px;
            color: #52617a;
            font-size: 12px;
            font-weight: 700;
            background: white;
            transition: .2s ease;
        }

        .module-nav a:hover {
            border-color: var(--week-primary);
            color: var(--week-primary);
        }

        .module-nav a.active {
            background: var(--week-primary);
            color: white;
            border-color: var(--week-primary);
            box-shadow: 0 4px 12px rgba(49, 92, 197, .2);
        }

        .module-heading-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #edf3ff;
            color: var(--week-primary);
            flex-shrink: 0;
        }

        .concept-card {
            padding: 17px;
            min-height: 145px;
        }

        .concept-card h6 {
            color: var(--week-dark);
            font-weight: 800;
        }

        .query-description {
            color: var(--week-muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .sql-preview {
            background: #111d36;
            color: #d9e6ff;
            border-radius: 12px;
            padding: 20px;
            overflow-x: auto;
            font-size: 11px;
            line-height: 1.9;
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
            max-height: 400px;
        }

        .sql-preview::-webkit-scrollbar {
            height: 6px;
        }

        .sql-preview::-webkit-scrollbar-thumb {
            background: #50658c;
            border-radius: 10px;
        }

        .parameter-box {
            background: #f5f8fd;
            border: 1px solid var(--week-border);
            border-radius: 10px;
            padding: 13px;
            font-size: 11px;
        }

        .parameter-box code {
            color: #315cc5;
        }

        .table {
            font-size: 11px;
            margin-bottom: 0;
        }

        .table thead th {
            background: #f5f7fb;
            color: #75849b;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
            white-space: nowrap;
            padding: 15px 12px;
            border-bottom: 1px solid var(--week-border);
        }

        .table tbody td {
            padding: 13px 12px;
            color: #465671;
            vertical-align: middle;
            white-space: nowrap;
        }

        .table tbody tr:hover {
            background: #f8faff;
        }

        .record-count {
            font-size: 11px;
            font-weight: 800;
            background: #edf3ff;
            color: #315cc5;
            padding: 8px 13px;
            border-radius: 30px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
            color: var(--week-muted);
        }

        .empty-state i {
            font-size: 43px;
        }

        .explanation-box {
            background: #f5f8fd;
            border-left: 4px solid var(--week-primary);
            border-radius: 8px;
            padding: 16px;
        }

        .explanation-box p {
            font-size: 12px;
            color: #52617a;
            line-height: 1.8;
        }

        .insight-item {
            border: 1px solid var(--week-border);
            border-radius: 11px;
            padding: 14px;
            height: 100%;
        }

        .insight-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--week-dark);
        }

        .insight-label {
            font-size: 10px;
            font-weight: 700;
            color: var(--week-muted);
            text-transform: uppercase;
        }

        .execution-badge {
            background: #eaf8f0;
            color: #16834a;
            padding: 7px 11px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
        }

        .guide-table td {
            font-size: 11px;
            padding: 12px;
        }

        .guide-table th {
            font-size: 10px;
            text-transform: uppercase;
        }

        .form-control,
        .form-select {
            font-size: 12px;
            min-height: 41px;
            border-color: var(--week-border);
        }

        .btn {
            font-size: 12px;
            font-weight: 700;
        }

        .btn-sm {
            font-size: 11px;
        }

        .chart-container {
            position: relative;
            min-height: 260px;
        }

        .footer {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 10px;
            color: var(--week-muted);
            padding: 20px 0;
            border-top: 1px solid var(--week-border);
        }

        @media(max-width: 768px) {

            .stat-card,
            .table-card,
            .query-card,
            .concept-card {
                padding: 16px;
            }

            .module-nav {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
            }

            .module-nav a {
                text-align: center;
                padding: 11px 7px;
            }

            .sql-preview {
                font-size: 10px;
            }

            .stat-number {
                font-size: 24px;
            }

            .footer {
                flex-direction: column;
            }

        }

        @media print {

            .sidebar,
            .topbar,
            .module-nav,
            .search-controls,
            .no-print,
            .footer,
            .btn {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                width: 100% !important;
            }

            .app-layout {
                display: block !important;
            }

            .content-area {
                padding: 0 !important;
            }

            .table-card,
            .stat-card,
            .concept-card {
                break-inside: avoid;
                border: 1px solid #ddd !important;
                box-shadow: none !important;
            }

            .sql-preview {
                background: #f1f1f1 !important;
                color: #111 !important;
                border: 1px solid #ddd;
            }

            body {
                background: white !important;
            }

        }

    </style>

</head>

<body>

<div class="app-layout">

    <?php
    $basePath = "../";
    require "../includes/sidebar.php";
    ?>

    <main class="main-content">

        <header class="topbar no-print">

            <div>
                <div class="breadcrumb-text">PART 2 / WEEK 6</div>
                <h4>Retrieve, Filter, Sort & Group</h4>
            </div>

            <div class="d-flex gap-2 align-items-center">
                <span class="execution-badge">
                    <i class="bi bi-database-check me-1"></i>
                    PostgreSQL Connected
                </span>
            </div>

        </header>

        <div class="content-area">

            <!-- PAGE INTRODUCTION -->

            <section class="welcome-section d-flex justify-content-between align-items-start gap-3 flex-wrap">

                <div>
                    <div class="query-label mb-2">DATABASE ADMINISTRATION · PRACTICAL TASKS</div>

                    <h2 class="fw-bold mb-2">Week 6 SQL Operations</h2>

                    <p class="text-muted small mb-0">
                        A practical environment for retrieving, filtering, sorting,
                        joining, and summarizing database records using PostgreSQL.
                    </p>
                </div>

                <div class="d-flex gap-2 no-print">
                    <button class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>

            </section>

            <!-- DATABASE OVERVIEW -->

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Database Overview</h6>
                <span class="query-label">LIVE DATABASE COUNTS</span>
            </div>

            <div class="row g-3 mb-4">

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-primary-subtle text-primary">
                            <i class="bi bi-people"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["total_borrowers"]) ?>
                        </div>

                        <div class="stat-label">REGISTERED BORROWERS</div>

                        <div class="query-description mt-2">All borrower records</div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-info-subtle text-info">
                            <i class="bi bi-laptop"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["total_equipment"]) ?>
                        </div>

                        <div class="stat-label">EQUIPMENT RECORDS</div>

                        <div class="query-description mt-2">Registered equipment types</div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-success-subtle text-success">
                            <i class="bi bi-journal-check"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["total_transactions"]) ?>
                        </div>

                        <div class="stat-label">TOTAL TRANSACTIONS</div>

                        <div class="query-description mt-2">All borrowing transactions</div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-warning-subtle text-warning">
                            <i class="bi bi-box-seam"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["total_available"]) ?>
                        </div>

                        <div class="stat-label">AVAILABLE QUANTITY</div>

                        <div class="query-description mt-2">Total available equipment units</div>

                    </div>
                </div>

            </div>

            <!-- SQL CONCEPTS -->

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">SQL Operations Covered</h6>
                <span class="query-label">WEEK 6 LEARNING OBJECTIVES</span>
            </div>

            <div class="row g-3 mb-4">

                <div class="col-6 col-xl-3">
                    <div class="concept-card">
                        <div class="query-label">01 · RETRIEVAL</div>
                        <h6 class="mt-2">SELECT</h6>
                        <p class="query-description mb-0">
                            Retrieves specific columns and records from database tables.
                        </p>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="concept-card">
                        <div class="query-label">02 · FILTERING</div>
                        <h6 class="mt-2">WHERE</h6>
                        <p class="query-description mb-0">
                            Restricts query results according to specified conditions.
                        </p>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="concept-card">
                        <div class="query-label">03 · SORTING</div>
                        <h6 class="mt-2">ORDER BY</h6>
                        <p class="query-description mb-0">
                            Arranges retrieved records in ascending or descending order.
                        </p>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="concept-card">
                        <div class="query-label">04 · AGGREGATION</div>
                        <h6 class="mt-2">GROUP BY</h6>
                        <p class="query-description mb-0">
                            Groups records and calculates summary values using aggregate functions.
                        </p>
                    </div>
                </div>

            </div>

            <!-- MODULE NAVIGATION -->

            <section class="table-card mb-4">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">SQL Practice Modules</h5>
                        <p class="query-description mb-0">
                            Select a module to explore its corresponding SQL operations.
                        </p>
                    </div>

                    <span class="query-label">5 AVAILABLE MODULES</span>
                </div>

                <div class="module-nav">

                    <a href="?view=borrowers" class="<?= $view === "borrowers" ? "active" : "" ?>">
                        <i class="bi bi-people me-1"></i> Borrowers
                    </a>

                    <a href="?view=equipment" class="<?= $view === "equipment" ? "active" : "" ?>">
                        <i class="bi bi-laptop me-1"></i> Equipment
                    </a>

                    <a href="?view=transactions" class="<?= $view === "transactions" ? "active" : "" ?>">
                        <i class="bi bi-journal-check me-1"></i> Transactions
                    </a>

                    <a href="?view=items" class="<?= $view === "items" ? "active" : "" ?>">
                        <i class="bi bi-box-seam me-1"></i> Borrowed Items
                    </a>

                    <a href="?view=grouped" class="<?= $view === "grouped" ? "active" : "" ?>">
                        <i class="bi bi-bar-chart me-1"></i> Grouped Summary
                    </a>

                </div>

            </section>

            <!-- MODULE DESCRIPTION -->

            <section class="table-card mb-4">

                <div class="d-flex align-items-center gap-3 mb-3">

                    <div class="module-heading-icon">
                        <i class="bi <?= e($info["icon"]) ?>"></i>
                    </div>

                    <div>
                        <div class="query-label"><?= e($info["concept"]) ?></div>
                        <h5 class="fw-bold mb-1"><?= e($info["title"]) ?></h5>
                        <p class="query-description mb-0"><?= e($info["subtitle"]) ?></p>
                    </div>

                </div>

                <div class="explanation-box">

                    <div class="fw-bold small mb-2">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Purpose of this SQL operation
                    </div>

                    <p class="mb-2"><?= e($info["purpose"]) ?></p>

                    <div class="fw-bold small mb-2">How it works</div>

                    <p class="mb-2"><?= e($info["explanation"]) ?></p>

                    <div class="fw-bold small mb-2">Expected query output</div>

                    <p class="mb-0"><?= e($info["expected"]) ?></p>

                </div>

            </section>

            <!-- RESULTS SECTION -->

            <section class="table-card mb-4">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                    <div>
                        <h5 class="fw-bold mb-1">Query Results</h5>
                        <p class="query-description mb-0">
                            Live results retrieved from the PostgreSQL database.
                        </p>
                    </div>

                    <div class="d-flex align-items-center gap-2">

                        <span class="record-count">
                            <?= number_format($resultCount) ?> records
                        </span>

                        <?php if ($hasExecuted): ?>
                            <span class="execution-badge">
                                <?= number_format($executionTime, 2) ?> ms
                            </span>
                        <?php endif; ?>

                    </div>

                </div>

                <!-- SEARCH AND FILTER -->

                <form method="GET" class="row g-2 mb-4 search-controls">

                    <input type="hidden" name="view" value="<?= e($view) ?>">

                    <?php if ($view !== "grouped"): ?>

                        <div class="col-lg-4">

                            <label class="query-label mb-2">Search records</label>

                            <input
                                type="search"
                                name="search"
                                class="form-control"
                                placeholder="Enter a name, number, or keyword..."
                                value="<?= e($search) ?>"
                            >

                        </div>

                    <?php endif; ?>

                    <?php if ($view === "borrowers"): ?>

                        <div class="col-lg-3">
                            <label class="query-label mb-2">Borrower type</label>

                            <select name="filter" class="form-select">
                                <option value="">All borrower types</option>

                                <?php foreach (["Student", "Faculty", "Staff"] as $type): ?>
                                    <option value="<?= e($type) ?>" <?= $filter === $type ? "selected" : "" ?>>
                                        <?= e($type) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                    <?php elseif ($view === "equipment"): ?>

                        <div class="col-lg-3">
                            <label class="query-label mb-2">Availability</label>

                            <select name="filter" class="form-select">
                                <option value="">All equipment</option>
                                <option value="available" <?= $filter === "available" ? "selected" : "" ?>>
                                    Available
                                </option>
                                <option value="unavailable" <?= $filter === "unavailable" ? "selected" : "" ?>>
                                    Out of stock
                                </option>
                            </select>
                        </div>

                    <?php elseif ($view === "transactions"): ?>

                        <div class="col-lg-3">
                            <label class="query-label mb-2">Transaction status</label>

                            <select name="filter" class="form-select">
                                <option value="">All statuses</option>

                                <?php foreach (["Borrowed", "Returned", "Completed", "Cancelled"] as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $filter === $status ? "selected" : "" ?>>
                                        <?= e($status) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                    <?php endif; ?>

                    <div class="<?= $view === "grouped" ? "col-lg-9" : "col-lg-3" ?>">

                        <label class="query-label mb-2">Sort results</label>

                        <select name="sort" class="form-select">

                            <?php
                            $sortLabels = [
                                "borrowers" => [
                                    "default" => "Name A–Z",
                                    "name_desc" => "Name Z–A",
                                    "number" => "Borrower Number",
                                    "type" => "Borrower Type"
                                ],
                                "equipment" => [
                                    "default" => "Equipment Name A–Z",
                                    "name_desc" => "Equipment Name Z–A",
                                    "property" => "Property Number",
                                    "quantity_asc" => "Lowest Quantity",
                                    "quantity_desc" => "Highest Quantity"
                                ],
                                "transactions" => [
                                    "default" => "Newest Transaction",
                                    "oldest" => "Oldest Transaction",
                                    "newest" => "Latest Borrow Date",
                                    "due_date" => "Due Date"
                                ],
                                "items" => [
                                    "default" => "Latest Item Record",
                                    "quantity_asc" => "Lowest Quantity",
                                    "quantity_desc" => "Highest Quantity"
                                ],
                                "grouped" => [
                                    "default" => "Status A–Z",
                                    "transactions_desc" => "Most Transactions",
                                    "transactions_asc" => "Fewest Transactions",
                                    "quantity_desc" => "Highest Borrowed Quantity",
                                    "quantity_asc" => "Lowest Borrowed Quantity"
                                ]
                            ];
                            ?>

                            <?php foreach ($sortLabels[$view] as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $sort === $key ? "selected" : "" ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-2 d-flex align-items-end gap-2">

                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-search me-1"></i> Apply
                        </button>

                        <a href="?view=<?= e($view) ?>" class="btn btn-light">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>

                    </div>

                </form>

                <!-- RESULT INSIGHTS -->

                <div class="row g-2 mb-4">

                    <div class="col-6 col-md-4">

                        <div class="insight-item">
                            <div class="insight-value">
                                <?= number_format($resultCount) ?>
                            </div>
                            <div class="insight-label">Retrieved Records</div>
                        </div>

                    </div>

                    <div class="col-6 col-md-4">

                        <div class="insight-item">
                            <div class="insight-value">
                                <?= number_format($totalDisplayedQuantity) ?>
                            </div>
                            <div class="insight-label">Quantity in Results</div>
                        </div>

                    </div>

                    <div class="col-6 col-md-4">

                        <div class="insight-item">
                            <div class="insight-value">
                                <?= number_format($executionTime, 2) ?>
                                <small class="fs-6">ms</small>
                            </div>
                            <div class="insight-label">Query Execution Time</div>
                        </div>

                    </div>

                </div>

                <!-- CHART: GROUPED SUMMARY ONLY -->

                <?php if ($view === "grouped" && !empty($records)): ?>

                    <div class="border rounded-3 p-3 mb-4">

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h6 class="fw-bold mb-1">Transaction Distribution</h6>
                                <p class="query-description mb-0">
                                    Number of transactions grouped by status.
                                </p>
                            </div>

                            <span class="query-label">VISUAL ANALYSIS</span>
                        </div>

                        <div class="chart-container">
                            <canvas id="transactionChart"></canvas>
                        </div>

                    </div>

                <?php endif; ?>

                <!-- EXECUTED SQL -->

                <div class="mb-4">

                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">

                        <h6 class="fw-bold mb-0">
                            <i class="bi bi-code-slash me-2 text-primary"></i>
                            Executed SQL Statement
                        </h6>

                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary no-print"
                            onclick="copySQL()"
                        >
                            <i class="bi bi-clipboard me-1"></i>
                            Copy SQL
                        </button>

                    </div>

                    <pre class="sql-preview" id="sqlPreview"><?= e(trim($sql)) ?></pre>

                    <?php if (!empty($params)): ?>

                        <div class="parameter-box mt-2">

                            <div class="fw-bold mb-2">
                                <i class="bi bi-sliders me-1"></i>
                                Bound Query Parameters
                            </div>

                            <?php foreach ($params as $key => $value): ?>

                                <div class="mb-1">
                                    <code>:<?= e($key) ?></code>
                                    <span class="text-muted">=</span>
                                    <strong><?= e($value) ?></strong>
                                </div>

                            <?php endforeach; ?>

                            <div class="text-muted mt-2">
                                These values are passed separately through PDO prepared statements.
                            </div>

                        </div>

                    <?php else: ?>

                        <div class="parameter-box mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            No dynamic search or filter parameters were supplied.
                        </div>

                    <?php endif; ?>

                </div>

                <!-- DATABASE ERROR -->

                <?php if ($errorMessage !== ""): ?>

                    <div class="alert alert-danger">

                        <h6 class="fw-bold">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Query Execution Error
                        </h6>

                        <?= e($errorMessage) ?>

                    </div>

                <?php elseif (empty($records)): ?>

                    <div class="empty-state">

                        <i class="bi bi-database-x d-block mb-3"></i>

                        <h6 class="fw-bold">No Records Found</h6>

                        <p class="small mb-3">
                            No database records match the current search or filter conditions.
                        </p>

                        <a href="?view=<?= e($view) ?>" class="btn btn-outline-primary btn-sm">
                            Reset Search and Filters
                        </a>

                    </div>

                <?php else: ?>

                    <!-- DATA TABLE -->

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                        <div>
                            <h6 class="fw-bold mb-1">Retrieved Database Records</h6>
                            <p class="query-description mb-0">
                                Showing all <?= number_format($resultCount) ?> matching records.
                            </p>
                        </div>

                        <div class="d-flex gap-2 no-print">

                            <button class="btn btn-sm btn-outline-success" onclick="exportCSV()">
                                <i class="bi bi-filetype-csv me-1"></i> Export CSV
                            </button>

                            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                                <i class="bi bi-printer me-1"></i> Print Results
                            </button>

                        </div>

                    </div>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle" id="resultsTable">

                            <thead>
                                <tr>
                                    <?php foreach ($columns as $column): ?>
                                        <th><?= e(formatLabel($column)) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($records as $record): ?>

                                    <tr>

                                        <?php foreach ($columns as $column): ?>

                                            <td>

                                                <?php if ($record[$column] === null): ?>

                                                    <span class="text-muted fst-italic">NULL</span>

                                                <?php elseif ($column === "status" || $column === "borrower_type"): ?>

                                                    <span class="status-badge bg-<?= e(statusClass((string)$record[$column])) ?>-subtle text-<?= e(statusClass((string)$record[$column])) ?>">
                                                        <?= e($record[$column]) ?>
                                                    </span>

                                                <?php elseif (is_numeric($record[$column]) && str_contains($column, "quantity")): ?>

                                                    <strong><?= number_format((float)$record[$column]) ?></strong>

                                                <?php else: ?>

                                                    <?= e($record[$column]) ?>

                                                <?php endif; ?>

                                            </td>

                                        <?php endforeach; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

            <!-- SQL EXPLANATION GUIDE -->

            <section class="table-card mb-4">

                <div class="mb-3">

                    <div class="query-label mb-2">LEARNING REFERENCE</div>

                    <h5 class="fw-bold mb-1">SQL Concepts and Their Implementation</h5>

                    <p class="query-description mb-0">
                        A reference explaining the database operations demonstrated in this module.
                    </p>

                </div>

                <div class="table-responsive">

                    <table class="table table-bordered guide-table align-middle">

                        <thead>
                            <tr>
                                <th style="width: 20%">SQL Concept</th>
                                <th>Purpose</th>
                                <th>Implementation</th>
                            </tr>
                        </thead>

                        <tbody>

                            <tr>
                                <td><strong>SELECT</strong></td>
                                <td>Retrieves specified columns from a database table.</td>
                                <td>Retrieves borrower, equipment, transaction, and item details.</td>
                            </tr>

                            <tr>
                                <td><strong>WHERE</strong></td>
                                <td>Filters records based on specified conditions.</td>
                                <td>Filters borrower types, equipment availability, and transaction statuses.</td>
                            </tr>

                            <tr>
                                <td><strong>ILIKE</strong></td>
                                <td>Performs case-insensitive pattern matching in PostgreSQL.</td>
                                <td>Searches names, borrower numbers, and equipment details.</td>
                            </tr>

                            <tr>
                                <td><strong>ORDER BY</strong></td>
                                <td>Sorts query results using selected columns or expressions.</td>
                                <td>Arranges records by names, dates, quantities, and transaction counts.</td>
                            </tr>

                            <tr>
                                <td><strong>INNER JOIN</strong></td>
                                <td>Returns records with matching values in both related tables.</td>
                                <td>Connects borrowers, transactions, equipment, and borrowed items.</td>
                            </tr>

                            <tr>
                                <td><strong>LEFT JOIN</strong></td>
                                <td>Returns all records from the left table and matching records from the right table.</td>
                                <td>Preserves transactions even if they do not have associated borrowed item records.</td>
                            </tr>

                            <tr>
                                <td><strong>GROUP BY</strong></td>
                                <td>Organizes records into groups based on common values.</td>
                                <td>Groups borrowing transactions according to their status.</td>
                            </tr>

                            <tr>
                                <td><strong>COUNT</strong></td>
                                <td>Counts the number of records or distinct values.</td>
                                <td>Counts transactions, borrowers, and borrowed item records.</td>
                            </tr>

                            <tr>
                                <td><strong>SUM</strong></td>
                                <td>Calculates the total of numerical values.</td>
                                <td>Calculates the total quantity of borrowed equipment.</td>
                            </tr>

                            <tr>
                                <td><strong>COALESCE</strong></td>
                                <td>Returns the first non-NULL expression.</td>
                                <td>Displays zero when an aggregate calculation returns NULL.</td>
                            </tr>

                            <tr>
                                <td><strong>CAST</strong></td>
                                <td>Converts a value from one data type to another.</td>
                                <td>Converts transaction IDs into text to support keyword searches.</td>
                            </tr>

                        </tbody>

                    </table>

                </div>

            </section>

            <!-- PRACTICAL EXAMINATION NOTES -->

            <section class="table-card mb-4">

                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-mortarboard fs-4 text-primary"></i>
                    <div>
                        <h5 class="fw-bold mb-1">Practical Examination Notes</h5>
                        <p class="query-description mb-0">
                            Important technical explanations for the demonstrated SQL statements.
                        </p>
                    </div>
                </div>

                <div class="accordion" id="week6Guide">

                    <div class="accordion-item">

                        <h2 class="accordion-header">
                            <button class="accordion-button fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#guideOne">
                                01. Why use table aliases?
                            </button>
                        </h2>

                        <div id="guideOne" class="accordion-collapse collapse show" data-bs-parent="#week6Guide">
                            <div class="accordion-body small text-muted">
                                Table aliases such as <code>b</code>, <code>e</code>,
                                <code>bt</code>, and <code>bi</code> provide shorter
                                references to tables. They make queries easier to read
                                and help distinguish columns when multiple tables contain
                                similarly named fields.
                            </div>
                        </div>

                    </div>

                    <div class="accordion-item">

                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#guideTwo">
                                02. What is the difference between INNER JOIN and LEFT JOIN?
                            </button>
                        </h2>

                        <div id="guideTwo" class="accordion-collapse collapse" data-bs-parent="#week6Guide">
                            <div class="accordion-body small text-muted">
                                INNER JOIN returns only records with matching relationships
                                in both tables. LEFT JOIN returns all records from the left
                                table, including those without a matching record in the
                                right table. Missing right-side values appear as NULL.
                            </div>
                        </div>

                    </div>

                    <div class="accordion-item">

                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#guideThree">
                                03. Why use COUNT(DISTINCT)?
                            </button>
                        </h2>

                        <div id="guideThree" class="accordion-collapse collapse" data-bs-parent="#week6Guide">
                            <div class="accordion-body small text-muted">
                                A JOIN may produce multiple rows for a single transaction
                                when it has multiple borrowed items. COUNT(DISTINCT)
                                counts each unique transaction or borrower only once,
                                preventing inflated totals.
                            </div>
                        </div>

                    </div>

                    <div class="accordion-item">

                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#guideFour">
                                04. Why use COALESCE with SUM?
                            </button>
                        </h2>

                        <div id="guideFour" class="accordion-collapse collapse" data-bs-parent="#week6Guide">
                            <div class="accordion-body small text-muted">
                                Aggregate functions such as SUM can return NULL when
                                there are no applicable values. COALESCE replaces that
                                NULL result with zero, making numerical summaries more
                                consistent and readable.
                            </div>
                        </div>

                    </div>

                    <div class="accordion-item">

                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#guideFive">
                                05. How does PDO protect search queries?
                            </button>
                        </h2>

                        <div id="guideFive" class="accordion-collapse collapse" data-bs-parent="#week6Guide">
                            <div class="accordion-body small text-muted">
                                PDO prepared statements separate SQL commands from
                                user-supplied parameter values. The values are bound
                                separately through execute(), reducing the risk of
                                SQL injection. The sorting expressions are also
                                restricted to predefined options.
                            </div>
                        </div>

                    </div>

                    <div class="accordion-item">

                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#guideSix">
                                06. What is the difference between COUNT and SUM?
                            </button>
                        </h2>

                        <div id="guideSix" class="accordion-collapse collapse" data-bs-parent="#week6Guide">
                            <div class="accordion-body small text-muted">
                                COUNT determines how many records or non-NULL values
                                exist, while SUM adds numerical values. For example,
                                COUNT can determine the number of borrowed item records,
                                while SUM calculates the total number of equipment units
                                borrowed.
                            </div>
                        </div>

                    </div>

                    <div class="accordion-item">

                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#guideSeven">
                                07. What is the purpose of GROUP BY?
                            </button>
                        </h2>

                        <div id="guideSeven" class="accordion-collapse collapse" data-bs-parent="#week6Guide">
                            <div class="accordion-body small text-muted">
                                GROUP BY organizes records with the same value into
                                groups. It is commonly used with aggregate functions
                                such as COUNT, SUM, AVG, MIN, and MAX to generate
                                summarized information.
                            </div>
                        </div>

                    </div>

                </div>

            </section>

            <!-- FOOTER -->

            <footer class="footer">

                <span>Database Administration Practical System</span>

                <span>PostgreSQL · SQL Operations · Week 6</span>

            </footer>

        </div>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* =========================================================
   COPY EXECUTED SQL
========================================================= */

function copySQL() {

    const sql = document.getElementById("sqlPreview").innerText;

    navigator.clipboard.writeText(sql)
        .then(() => {
            alert("SQL statement copied successfully.");
        })
        .catch(() => {
            alert("Unable to copy SQL statement.");
        });

}

/* =========================================================
   EXPORT DISPLAYED RESULTS TO CSV
========================================================= */

function exportCSV() {

    const table = document.getElementById("resultsTable");

    if (!table) {
        alert("There are no records available for export.");
        return;
    }

    const rows = Array.from(table.querySelectorAll("tr"));

    const csv = rows.map(row => {

        const cells = Array.from(row.querySelectorAll("th, td"));

        return cells.map(cell => {

            let value = cell.innerText.trim();

            value = value.replace(/"/g, '""');

            // Prevent spreadsheet formula injection.
            if (/^[=+\-@\t\r]/.test(value)) {
                value = "'" + value;
            }

            return '"' + value + '"';

        }).join(",");

    }).join("\r\n");

    const blob = new Blob(["\uFEFF" + csv], {
        type: "text/csv;charset=utf-8;"
    });

    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;
    link.download = "week6_<?= e($view) ?>_results.csv";

    document.body.appendChild(link);

    link.click();

    link.remove();

    URL.revokeObjectURL(url);

}

/* =========================================================
   GROUPED TRANSACTION CHART
========================================================= */

<?php if ($view === "grouped" && !empty($records)): ?>

const chartCanvas = document.getElementById("transactionChart");

if (chartCanvas && typeof Chart !== "undefined") {

    new Chart(chartCanvas, {

        type: "bar",

        data: {

            labels: <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,

            datasets: [{

                label: "Number of Transactions",

                data: <?= json_encode($chartValues) ?>,

                backgroundColor: [
                    "#315cc5",
                    "#24a6c7",
                    "#20a46b",
                    "#f1ae39",
                    "#e45b6b"
                ],

                borderRadius: 8,

                maxBarThickness: 55

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                },

                tooltip: {
                    enabled: true
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                },

                x: {

                    grid: {
                        display: false
                    }

                }

            }

        }

    });

}

<?php endif; ?>

</script>

</body>
</html>
