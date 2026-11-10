<?php
session_start();
require_once "../includes/db.php";

function e($value): string
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function formatNumber($value): string
{
    return number_format((float)($value ?? 0));
}

function statusClass($status): string
{
    return match (strtolower(trim((string)$status))) {
        "returned", "completed" => "success",
        "borrowed", "active" => "primary",
        "overdue" => "danger",
        "cancelled" => "secondary",
        default => "warning"
    };
}

$view = $_GET["view"] ?? "overview";

$allowedViews = [
    "overview",
    "borrowers",
    "equipment",
    "transactions",
    "audit"
];

if (!in_array($view, $allowedViews, true)) {
    $view = "overview";
}

$pageTitles = [
    "overview" => "Database Operations Overview",
    "borrowers" => "Borrower Activity Monitoring",
    "equipment" => "Equipment Utilization Monitoring",
    "transactions" => "Borrowing Transaction Monitoring",
    "audit" => "Transaction Audit Trail"
];

$pageDescriptions = [
    "overview" => "Monitor borrowing activities, evaluate function results, and understand how database triggers maintain transaction history.",
    "borrowers" => "Analyze borrower activity using a PostgreSQL function that counts each borrower's transactions.",
    "equipment" => "Examine equipment borrowing totals using a PostgreSQL function and compare them with available inventory.",
    "transactions" => "Review borrowing records, item quantities, transaction statuses, and due dates.",
    "audit" => "Examine historical status changes automatically recorded by the PostgreSQL audit trigger."
];

$pageTitle = $pageTitles[$view];
$pageDescription = $pageDescriptions[$view];

$stats = [
    "borrowers" => 0,
    "equipment" => 0,
    "transactions" => 0,
    "items" => 0,
    "audit_logs" => 0,
    "available_quantity" => 0
];

$borrowers = [];
$equipment = [];
$transactions = [];
$auditLogs = [];

$statusSummary = [];
$topBorrowers = [];
$topEquipment = [];

$errorMessage = "";
$queryTime = null;
$recordCount = 0;

try {

    // =========================================
    // GENERAL DATABASE STATISTICS
    // =========================================

    $statsResult = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM public.borrowers) AS borrowers,
            (SELECT COUNT(*) FROM public.equipment) AS equipment,
            (SELECT COUNT(*) FROM public.borrow_transactions) AS transactions,
            (SELECT COUNT(*) FROM public.borrow_items) AS items,
            (SELECT COUNT(*) FROM public.borrow_transaction_audit) AS audit_logs,
            (SELECT COALESCE(SUM(quantity_available), 0)
             FROM public.equipment) AS available_quantity
    ")->fetch(PDO::FETCH_ASSOC);

    if ($statsResult) {
        $stats = array_merge($stats, $statsResult);
    }


    // =========================================
    // OVERVIEW INSIGHTS
    // =========================================

    if ($view === "overview") {

        // Transaction status distribution
        $statusSummary = $pdo->query("
            SELECT
                status,
                COUNT(*) AS total
            FROM public.borrow_transactions
            GROUP BY status
            ORDER BY COUNT(*) DESC
        ")->fetchAll(PDO::FETCH_ASSOC);


        // Borrowers with the highest transaction counts
        $topBorrowers = $pdo->query("
            SELECT
                b.borrower_no,
                b.full_name,
                public.fn_borrower_transaction_count(
                    b.borrower_id
                ) AS transaction_count
            FROM public.borrowers b
            ORDER BY transaction_count DESC, b.full_name ASC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);


        // Equipment with the highest recorded borrowing totals
        $topEquipment = $pdo->query("
            SELECT
                e.property_no,
                e.equipment_name,
                e.quantity_available,
                public.fn_equipment_total_borrowed(
                    e.equipment_id
                ) AS total_borrowed
            FROM public.equipment e
            ORDER BY total_borrowed DESC, e.equipment_name ASC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    // =========================================
    // BORROWER FUNCTION RESULTS
    // =========================================

    if ($view === "borrowers") {

        $startTime = microtime(true);

        $borrowers = $pdo->query("
            SELECT
                b.borrower_id,
                b.borrower_no,
                b.full_name,
                b.borrower_type,
                public.fn_borrower_transaction_count(
                    b.borrower_id
                ) AS transaction_count
            FROM public.borrowers b
            ORDER BY transaction_count DESC, b.full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $queryTime = (microtime(true) - $startTime) * 1000;
        $recordCount = count($borrowers);
    }


    // =========================================
    // EQUIPMENT FUNCTION RESULTS
    // =========================================

    if ($view === "equipment") {

        $startTime = microtime(true);

        $equipment = $pdo->query("
            SELECT
                e.equipment_id,
                e.property_no,
                e.equipment_name,
                e.quantity_available,
                public.fn_equipment_total_borrowed(
                    e.equipment_id
                ) AS total_borrowed
            FROM public.equipment e
            ORDER BY total_borrowed DESC, e.equipment_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $queryTime = (microtime(true) - $startTime) * 1000;
        $recordCount = count($equipment);
    }


    // =========================================
    // BORROWING TRANSACTIONS
    // =========================================

    if ($view === "transactions") {

        $startTime = microtime(true);

        $transactions = $pdo->query("
            SELECT
                bt.borrow_id,
                bt.borrow_date,
                bt.due_date,
                bt.status,
                b.borrower_no,
                b.full_name,
                COUNT(bi.borrow_item_id) AS item_count,
                COALESCE(SUM(bi.quantity), 0) AS total_quantity
            FROM public.borrow_transactions bt
            INNER JOIN public.borrowers b
                ON b.borrower_id = bt.borrower_id
            LEFT JOIN public.borrow_items bi
                ON bi.borrow_id = bt.borrow_id
            GROUP BY
                bt.borrow_id,
                bt.borrow_date,
                bt.due_date,
                bt.status,
                b.borrower_no,
                b.full_name
            ORDER BY bt.borrow_id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $queryTime = (microtime(true) - $startTime) * 1000;
        $recordCount = count($transactions);
    }


    // =========================================
    // AUDIT TRAIL
    // =========================================

    if ($view === "audit") {

        $startTime = microtime(true);

        $auditLogs = $pdo->query("
            SELECT
                a.audit_id,
                a.borrow_id,
                a.old_status,
                a.new_status,
                a.changed_at,
                a.note,
                b.borrower_no,
                b.full_name
            FROM public.borrow_transaction_audit a
            LEFT JOIN public.borrow_transactions bt
                ON bt.borrow_id = a.borrow_id
            LEFT JOIN public.borrowers b
                ON b.borrower_id = bt.borrower_id
            ORDER BY
                a.changed_at DESC,
                a.audit_id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $queryTime = (microtime(true) - $startTime) * 1000;
        $recordCount = count($auditLogs);
    }

} catch (Throwable $error) {

    error_log("Week 10 Database Error: " . $error->getMessage());

    // Do not expose raw database errors to the browser.
    $errorMessage = "Unable to load this module. Please verify that the required Week 10 database tables and PostgreSQL functions are available.";
}


// =========================================
// CALCULATED OVERVIEW VALUES
// =========================================

$totalTransactions = (int)$stats["transactions"];
$totalAuditLogs = (int)$stats["audit_logs"];
$totalBorrowers = (int)$stats["borrowers"];
$totalEquipment = (int)$stats["equipment"];
$totalItems = (int)$stats["items"];
$totalAvailable = (int)$stats["available_quantity"];

$totalActiveStatuses = 0;

foreach ($statusSummary as $statusRow) {
    if (in_array(
        strtolower((string)$statusRow["status"]),
        ["borrowed", "active"],
        true
    )) {
        $totalActiveStatuses += (int)$statusRow["total"];
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Week 10 | Functions & Triggers</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        .week10-content {
            padding: 25px;
        }

        .week10-header {
            margin-bottom: 25px;
        }

        .week10-header h2 {
            font-weight: 750;
            color: #172033;
            margin-bottom: 8px;
        }

        .week10-header p {
            color: #778196;
            margin-bottom: 0;
            max-width: 750px;
        }

        .week10-stat,
        .week10-card,
        .week10-panel {
            background: #fff;
            border: 1px solid #e9edf4;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(20, 35, 60, 0.04);
        }

        .week10-stat {
            padding: 21px;
            height: 100%;
        }

        .week10-stat-label {
            color: #778196;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .week10-stat-value {
            color: #172033;
            font-size: 27px;
            font-weight: 750;
        }

        .week10-stat-icon {
            width: 43px;
            height: 43px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #edf3ff;
            color: #3157a4;
            font-size: 19px;
        }

        .week10-card {
            overflow: hidden;
        }

        .week10-card-header {
            padding: 21px;
            border-bottom: 1px solid #edf0f5;
        }

        .week10-card-header h5 {
            color: #172033;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .week10-card-header p {
            color: #778196;
            font-size: 13px;
            margin: 0;
        }

        .week10-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            padding: 17px 21px;
            border-bottom: 1px solid #edf0f5;
        }

        .week10-tab {
            text-decoration: none;
            padding: 9px 15px;
            border-radius: 9px;
            background: #f5f7fb;
            color: #647086;
            font-size: 13px;
            font-weight: 650;
        }

        .week10-tab:hover {
            background: #eaf0fc;
            color: #3157a4;
        }

        .week10-tab.active {
            background: #172d5d;
            color: #fff;
        }

        .week10-panel {
            padding: 20px;
            height: 100%;
        }

        .week10-panel-title {
            font-size: 14px;
            font-weight: 750;
            color: #172033;
            margin-bottom: 5px;
        }

        .week10-panel-description {
            color: #778196;
            font-size: 12px;
            margin-bottom: 17px;
        }

        .week10-function-card {
            padding: 20px;
            border: 1px solid #e9edf4;
            border-radius: 12px;
            height: 100%;
            background: #fff;
        }

        .week10-function-card h6 {
            font-weight: 700;
            color: #172033;
        }

        .week10-function-card code {
            display: inline-block;
            padding: 7px 10px;
            margin: 7px 0;
            border-radius: 7px;
            background: #f1f4fa;
            color: #3157a4;
            overflow-wrap: anywhere;
        }

        .week10-table-wrapper {
            overflow-x: auto;
        }

        .week10-table {
            min-width: 750px;
            margin: 0;
        }

        .week10-table thead th {
            background: #f7f9fc;
            color: #647086;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .35px;
            padding: 15px;
            white-space: nowrap;
        }

        .week10-table tbody td {
            padding: 15px;
            font-size: 13px;
            color: #344054;
            vertical-align: middle;
        }

        .week10-table tbody tr:hover {
            background: #f8faff;
        }

        .week10-empty {
            padding: 40px 20px;
            text-align: center;
            color: #778196;
        }

        .week10-empty i {
            display: block;
            font-size: 35px;
            margin-bottom: 10px;
        }

        .week10-toolbar {
            padding: 17px 21px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid #edf0f5;
        }

        .week10-search {
            max-width: 310px;
        }

        .week10-search input {
            font-size: 13px;
        }

        .week10-insight-number {
            font-size: 23px;
            font-weight: 750;
            color: #172033;
        }

        .week10-progress {
            height: 8px;
            border-radius: 20px;
            background: #edf0f5;
            overflow: hidden;
        }

        .week10-progress-bar {
            height: 100%;
            border-radius: 20px;
            background: #3157a4;
        }

        .week10-step {
            display: flex;
            gap: 13px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .week10-step-number {
            flex-shrink: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            width: 30px;
            height: 30px;
            background: #edf3ff;
            color: #3157a4;
            border-radius: 50%;
            font-size: 13px;
            font-weight: 750;
        }

        .week10-step p {
            font-size: 13px;
            color: #647086;
            margin: 4px 0 0;
        }

        .week10-step strong {
            font-size: 13px;
            color: #172033;
        }

        .week10-code-block {
            background: #172033;
            color: #e7edf8;
            padding: 16px;
            border-radius: 10px;
            font-size: 12px;
            overflow-x: auto;
            white-space: pre;
        }

        .week10-code-block code {
            color: #e7edf8;
        }

        .week10-metric {
            color: #647086;
            font-size: 12px;
        }

        .week10-error {
            margin: 20px;
            padding: 16px;
            border-radius: 10px;
            background: #fff1f1;
            border: 1px solid #ffcaca;
            color: #a12626;
        }

        .week10-no-print {
            display: block;
        }

        @media(max-width: 768px) {

            .week10-content {
                padding: 16px;
            }

            .week10-stat {
                padding: 16px;
            }

            .week10-stat-value {
                font-size: 23px;
            }

            .week10-tabs {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
            }

            .week10-tab {
                text-align: center;
            }

            .week10-toolbar {
                align-items: stretch;
            }

            .week10-search {
                max-width: 100%;
                width: 100%;
            }
        }

        @media print {

            .sidebar,
            .week10-tabs,
            .week10-no-print,
            .week10-toolbar,
            .week10-footer,
            .btn {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                width: 100% !important;
            }

            .week10-content {
                padding: 0 !important;
            }

            .week10-card,
            .week10-panel,
            .week10-stat {
                box-shadow: none !important;
                break-inside: avoid;
            }

            body {
                background: #fff !important;
            }

            .week10-table {
                min-width: 100%;
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

        <div class="week10-content">

            <!-- HEADER -->

            <div class="week10-header">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                    <div>

                        <span class="badge text-bg-primary mb-2">
                            PART 5 · WEEK 10
                        </span>

                        <h2>Functions & Triggers</h2>

                        <p><?= e($pageDescription) ?></p>

                    </div>

                    <div class="d-flex gap-2 week10-no-print">

                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            onclick="window.print()"
                        >
                            <i class="bi bi-printer me-1"></i>
                            Print
                        </button>

                        <a
                            href="week10_queries.php?view=<?= e($view) ?>"
                            class="btn btn-primary btn-sm"
                        >
                            <i class="bi bi-arrow-clockwise me-1"></i>
                            Refresh
                        </a>

                    </div>

                </div>

            </div>


            <!-- GENERAL STATISTICS -->

            <div class="row g-3 mb-4">

                <div class="col-6 col-xl-3">
                    <div class="week10-stat">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="week10-stat-label">Borrowers</span>
                            <span class="week10-stat-icon">
                                <i class="bi bi-people"></i>
                            </span>
                        </div>

                        <div class="week10-stat-value">
                            <?= formatNumber($stats["borrowers"]) ?>
                        </div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="week10-stat">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="week10-stat-label">Equipment</span>
                            <span class="week10-stat-icon">
                                <i class="bi bi-pc-display"></i>
                            </span>
                        </div>

                        <div class="week10-stat-value">
                            <?= formatNumber($stats["equipment"]) ?>
                        </div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="week10-stat">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="week10-stat-label">Transactions</span>
                            <span class="week10-stat-icon">
                                <i class="bi bi-arrow-left-right"></i>
                            </span>
                        </div>

                        <div class="week10-stat-value">
                            <?= formatNumber($stats["transactions"]) ?>
                        </div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="week10-stat">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="week10-stat-label">Audit Logs</span>
                            <span class="week10-stat-icon">
                                <i class="bi bi-shield-check"></i>
                            </span>
                        </div>

                        <div class="week10-stat-value">
                            <?= formatNumber($stats["audit_logs"]) ?>
                        </div>

                    </div>
                </div>

            </div>


            <!-- MAIN CARD -->

            <div class="week10-card">

                <!-- NAVIGATION -->

                <div class="week10-tabs week10-no-print">

                    <a href="?view=overview"
                       class="week10-tab <?= $view === "overview" ? "active" : "" ?>">
                        <i class="bi bi-grid me-1"></i>
                        Overview
                    </a>

                    <a href="?view=borrowers"
                       class="week10-tab <?= $view === "borrowers" ? "active" : "" ?>">
                        <i class="bi bi-people me-1"></i>
                        Borrower Functions
                    </a>

                    <a href="?view=equipment"
                       class="week10-tab <?= $view === "equipment" ? "active" : "" ?>">
                        <i class="bi bi-pc-display me-1"></i>
                        Equipment Functions
                    </a>

                    <a href="?view=transactions"
                       class="week10-tab <?= $view === "transactions" ? "active" : "" ?>">
                        <i class="bi bi-arrow-left-right me-1"></i>
                        Transactions
                    </a>

                    <a href="?view=audit"
                       class="week10-tab <?= $view === "audit" ? "active" : "" ?>">
                        <i class="bi bi-journal-text me-1"></i>
                        Audit Trail
                    </a>

                </div>


                <div class="week10-card-header">

                    <h5><?= e($pageTitle) ?></h5>
                    <p><?= e($pageDescription) ?></p>

                </div>


                <?php if ($errorMessage !== ""): ?>

                    <div class="week10-error">

                        <strong>
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Unable to Load Module
                        </strong>

                        <p class="mb-0 mt-2">
                            <?= e($errorMessage) ?>
                        </p>

                        <p class="small mb-0 mt-2">
                            Check your Week 10 SQL setup and database connection.
                            Detailed error information is recorded in the PHP error log.
                        </p>

                    </div>

                <?php else: ?>


                    <!-- =====================================
                         OVERVIEW
                    ====================================== -->

                    <?php if ($view === "overview"): ?>

                        <div class="p-4">

                            <!-- DATABASE SUMMARY -->

                            <div class="row g-3 mb-4">

                                <div class="col-md-4">
                                    <div class="week10-panel">

                                        <div class="text-muted small fw-semibold">
                                            BORROWING DETAILS
                                        </div>

                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($totalItems) ?>
                                        </div>

                                        <div class="text-muted small">
                                            Total borrow item records
                                        </div>

                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">

                                        <div class="text-muted small fw-semibold">
                                            AVAILABLE INVENTORY
                                        </div>

                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($totalAvailable) ?>
                                        </div>

                                        <div class="text-muted small">
                                            Combined available equipment quantity
                                        </div>

                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">

                                        <div class="text-muted small fw-semibold">
                                            CURRENT BORROWED STATUS
                                        </div>

                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($totalActiveStatuses) ?>
                                        </div>

                                        <div class="text-muted small">
                                            Transactions marked borrowed or active
                                        </div>

                                    </div>
                                </div>

                            </div>


                            <!-- FUNCTION EXPLANATIONS -->

                            <h6 class="fw-bold mb-3">
                                <i class="bi bi-database-check text-primary me-2"></i>
                                Database Functions and Trigger
                            </h6>

                            <div class="row g-3 mb-4">

                                <div class="col-md-6">

                                    <div class="week10-function-card">

                                        <i class="bi bi-calculator fs-3 text-primary"></i>

                                        <h6 class="mt-3">
                                            Borrower Transaction Count
                                        </h6>

                                        <code>fn_borrower_transaction_count(borrower_id)</code>

                                        <p class="text-muted small mb-2">
                                            Counts the borrowing transactions associated
                                            with a particular borrower.
                                        </p>

                                        <a href="?view=borrowers"
                                           class="btn btn-sm btn-outline-primary">
                                            View Function Results
                                            <i class="bi bi-arrow-right ms-1"></i>
                                        </a>

                                    </div>

                                </div>


                                <div class="col-md-6">

                                    <div class="week10-function-card">

                                        <i class="bi bi-box-seam fs-3 text-primary"></i>

                                        <h6 class="mt-3">
                                            Equipment Borrowing Total
                                        </h6>

                                        <code>fn_equipment_total_borrowed(equipment_id)</code>

                                        <p class="text-muted small mb-2">
                                            Calculates the total quantity recorded in
                                            borrowing details for a specific equipment item.
                                        </p>

                                        <a href="?view=equipment"
                                           class="btn btn-sm btn-outline-primary">
                                            View Function Results
                                            <i class="bi bi-arrow-right ms-1"></i>
                                        </a>

                                    </div>

                                </div>


                                <div class="col-md-6">

                                    <div class="week10-function-card">

                                        <i class="bi bi-arrow-repeat fs-3 text-primary"></i>

                                        <h6 class="mt-3">
                                            Automatic Status Audit Trigger
                                        </h6>

                                        <code>trg_borrow_status_audit</code>

                                        <p class="text-muted small mb-2">
                                            Automatically responds to qualifying transaction
                                            status updates and records the change.
                                        </p>

                                        <a href="?view=audit"
                                           class="btn btn-sm btn-outline-primary">
                                            Examine Audit History
                                            <i class="bi bi-arrow-right ms-1"></i>
                                        </a>

                                    </div>

                                </div>


                                <div class="col-md-6">

                                    <div class="week10-function-card">

                                        <i class="bi bi-journal-check fs-3 text-primary"></i>

                                        <h6 class="mt-3">
                                            Transaction Audit Table
                                        </h6>

                                        <code>borrow_transaction_audit</code>

                                        <p class="text-muted small mb-2">
                                            Stores the recorded status transition, timestamp,
                                            and audit note for later examination.
                                        </p>

                                        <a href="?view=audit"
                                           class="btn btn-sm btn-outline-primary">
                                            View Audit Records
                                            <i class="bi bi-arrow-right ms-1"></i>
                                        </a>

                                    </div>

                                </div>

                            </div>


                            <!-- STATUS DISTRIBUTION -->

                            <div class="row g-3 mb-4">

                                <div class="col-lg-6">

                                    <div class="week10-panel">

                                        <div class="week10-panel-title">
                                            Transaction Status Distribution
                                        </div>

                                        <div class="week10-panel-description">
                                            Number of transactions grouped by their current status.
                                        </div>

                                        <?php if (empty($statusSummary)): ?>

                                            <div class="text-muted small">
                                                No transaction status data available.
                                            </div>

                                        <?php else: ?>

                                            <?php foreach ($statusSummary as $statusRow): ?>

                                                <?php
                                                    $statusTotal = (int)$statusRow["total"];

                                                    $percentage = $totalTransactions > 0
                                                        ? ($statusTotal / $totalTransactions) * 100
                                                        : 0;
                                                ?>

                                                <div class="mb-3">

                                                    <div class="d-flex justify-content-between mb-2">

                                                        <span class="small fw-semibold">
                                                            <?= e($statusRow["status"]) ?>
                                                        </span>

                                                        <span class="small text-muted">
                                                            <?= formatNumber($statusTotal) ?>
                                                            (<?= number_format($percentage, 1) ?>%)
                                                        </span>

                                                    </div>

                                                    <div class="week10-progress">

                                                        <div
                                                            class="week10-progress-bar"
                                                            style="width: <?= min(100, $percentage) ?>%"
                                                        ></div>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </div>

                                </div>


                                <div class="col-lg-6">

                                    <div class="week10-panel">

                                        <div class="week10-panel-title">
                                            Database Activity Summary
                                        </div>

                                        <div class="week10-panel-description">
                                            A quick look at the records maintained by the system.
                                        </div>

                                        <div class="d-flex justify-content-between py-2 border-bottom">
                                            <span class="small text-muted">
                                                Registered borrowers
                                            </span>
                                            <strong>
                                                <?= formatNumber($totalBorrowers) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between py-2 border-bottom">
                                            <span class="small text-muted">
                                                Registered equipment
                                            </span>
                                            <strong>
                                                <?= formatNumber($totalEquipment) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between py-2 border-bottom">
                                            <span class="small text-muted">
                                                Borrowing transactions
                                            </span>
                                            <strong>
                                                <?= formatNumber($totalTransactions) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between py-2">
                                            <span class="small text-muted">
                                                Recorded audit events
                                            </span>
                                            <strong>
                                                <?= formatNumber($totalAuditLogs) ?>
                                            </strong>
                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- TOP BORROWERS -->

                            <div class="row g-3 mb-4">

                                <div class="col-lg-6">

                                    <div class="week10-panel">

                                        <div class="week10-panel-title">
                                            Most Active Borrowers
                                        </div>

                                        <div class="week10-panel-description">
                                            Ranked using the borrower transaction-count function.
                                        </div>

                                        <?php if (empty($topBorrowers)): ?>

                                            <p class="text-muted small mb-0">
                                                No borrower records available.
                                            </p>

                                        <?php else: ?>

                                            <?php foreach ($topBorrowers as $index => $borrower): ?>

                                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">

                                                    <div class="d-flex align-items-center gap-2">

                                                        <span class="badge bg-primary-subtle text-primary">
                                                            <?= $index + 1 ?>
                                                        </span>

                                                        <div>
                                                            <div class="small fw-semibold">
                                                                <?= e($borrower["full_name"]) ?>
                                                            </div>

                                                            <div class="text-muted" style="font-size:11px">
                                                                <?= e($borrower["borrower_no"]) ?>
                                                            </div>
                                                        </div>

                                                    </div>

                                                    <strong class="small">
                                                        <?= formatNumber($borrower["transaction_count"]) ?>
                                                        transactions
                                                    </strong>

                                                </div>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </div>

                                </div>


                                <!-- TOP EQUIPMENT -->

                                <div class="col-lg-6">

                                    <div class="week10-panel">

                                        <div class="week10-panel-title">
                                            Most Borrowed Equipment
                                        </div>

                                        <div class="week10-panel-description">
                                            Ranked using the equipment total-borrowed function.
                                        </div>

                                        <?php if (empty($topEquipment)): ?>

                                            <p class="text-muted small mb-0">
                                                No equipment records available.
                                            </p>

                                        <?php else: ?>

                                            <?php foreach ($topEquipment as $index => $item): ?>

                                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">

                                                    <div class="d-flex align-items-center gap-2">

                                                        <span class="badge bg-primary-subtle text-primary">
                                                            <?= $index + 1 ?>
                                                        </span>

                                                        <div>
                                                            <div class="small fw-semibold">
                                                                <?= e($item["equipment_name"]) ?>
                                                            </div>

                                                            <div class="text-muted" style="font-size:11px">
                                                                <?= e($item["property_no"]) ?>
                                                            </div>
                                                        </div>

                                                    </div>

                                                    <strong class="small">
                                                        <?= formatNumber($item["total_borrowed"]) ?>
                                                        borrowed
                                                    </strong>

                                                </div>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>


                            <!-- PRACTICAL TRIGGER DEMONSTRATION -->

                            <div class="week10-panel">

                                <div class="d-flex align-items-center gap-2 mb-2">

                                    <i class="bi bi-lightbulb fs-4 text-primary"></i>

                                    <h6 class="fw-bold mb-0">
                                        Practical Demonstration: How the Trigger Works
                                    </h6>

                                </div>

                                <p class="text-muted small">
                                    A database trigger allows PostgreSQL to perform an
                                    automatic action when a specified database event occurs.
                                    In this system, the audit trigger records transaction
                                    status changes.
                                </p>

                                <div class="row g-3 mt-2">

                                    <div class="col-md-4">

                                        <div class="week10-step">

                                            <span class="week10-step-number">1</span>

                                            <div>
                                                <strong>Update a transaction</strong>

                                                <p>
                                                    Change an existing transaction's status
                                                    through a legitimate database operation.
                                                </p>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="col-md-4">

                                        <div class="week10-step">

                                            <span class="week10-step-number">2</span>

                                            <div>
                                                <strong>Trigger executes automatically</strong>

                                                <p>
                                                    PostgreSQL invokes the associated trigger
                                                    when its configured event occurs.
                                                </p>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="col-md-4">

                                        <div class="week10-step">

                                            <span class="week10-step-number">3</span>

                                            <div>
                                                <strong>Audit record is created</strong>

                                                <p>
                                                    The previous status, new status, and
                                                    other configured details are recorded.
                                                </p>
                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <div class="alert alert-info mb-0 mt-3 small">

                                    <i class="bi bi-info-circle me-2"></i>

                                    The demonstration above describes the expected workflow.
                                    To verify that the trigger works, update a transaction
                                    status in PostgreSQL and then inspect the Audit Trail.
                                    This page does not generate artificial audit records.

                                </div>

                            </div>

                        </div>


                    <!-- =====================================
                         BORROWER FUNCTIONS
                    ====================================== -->

                    <?php elseif ($view === "borrowers"): ?>

                        <div class="p-4 pb-0">

                            <div class="alert alert-primary small">

                                <strong>
                                    <i class="bi bi-calculator me-2"></i>
                                    Function in Action
                                </strong>

                                <p class="mb-2 mt-2">
                                    The system calls
                                    <code>fn_borrower_transaction_count()</code>
                                    for each borrower. The returned value represents
                                    the number of borrowing transactions associated
                                    with that borrower.
                                </p>

                                <a href="?view=transactions" class="alert-link">
                                    Compare with transaction records
                                </a>

                            </div>

                            <div class="row g-3 mb-3">

                                <div class="col-md-4">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            TOTAL BORROWERS
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber(count($borrowers)) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            MOST ACTIVE BORROWER
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= !empty($borrowers)
                                                ? formatNumber($borrowers[0]["transaction_count"])
                                                : "0" ?>
                                        </div>
                                        <div class="text-muted small">
                                            Transactions recorded
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            QUERY RESULT RECORDS
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($recordCount) ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                        </div>


                        <div class="week10-toolbar week10-no-print">

                            <div class="week10-search input-group input-group-sm">

                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="tableSearch"
                                    placeholder="Search borrower records..."
                                >

                            </div>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                onclick="exportTableCSV('borrower-results.csv')"
                            >
                                <i class="bi bi-download me-1"></i>
                                Export CSV
                            </button>

                        </div>


                        <div class="week10-table-wrapper">

                            <table class="table table-hover week10-table" id="resultsTable">

                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Borrower Number</th>
                                        <th>Full Name</th>
                                        <th>Borrower Type</th>
                                        <th>Total Transactions</th>
                                        <th>Activity Level</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php if (empty($borrowers)): ?>

                                        <tr>
                                            <td colspan="6">
                                                <div class="week10-empty">
                                                    <i class="bi bi-inbox"></i>
                                                    No borrower records found.
                                                </div>
                                            </td>
                                        </tr>

                                    <?php else: ?>

                                        <?php foreach ($borrowers as $index => $borrower): ?>

                                            <?php
                                                $count = (int)$borrower["transaction_count"];

                                                if ($count === 0) {
                                                    $activity = "No Activity";
                                                    $activityClass = "secondary";
                                                } elseif ($count <= 2) {
                                                    $activity = "Low Activity";
                                                    $activityClass = "warning";
                                                } else {
                                                    $activity = "Active";
                                                    $activityClass = "success";
                                                }
                                            ?>

                                            <tr>

                                                <td><?= $index + 1 ?></td>

                                                <td>
                                                    <?= e($borrower["borrower_no"]) ?>
                                                </td>

                                                <td class="fw-semibold">
                                                    <?= e($borrower["full_name"]) ?>
                                                </td>

                                                <td>
                                                    <?= e($borrower["borrower_type"]) ?>
                                                </td>

                                                <td>
                                                    <strong><?= formatNumber($count) ?></strong>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= $activityClass ?>">
                                                        <?= e($activity) ?>
                                                    </span>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>


                    <!-- =====================================
                         EQUIPMENT FUNCTIONS
                    ====================================== -->

                    <?php elseif ($view === "equipment"): ?>

                        <div class="p-4 pb-0">

                            <div class="alert alert-primary small">

                                <strong>
                                    <i class="bi bi-box-seam me-2"></i>
                                    Function in Action
                                </strong>

                                <p class="mb-0 mt-2">
                                    The system calls
                                    <code>fn_equipment_total_borrowed()</code>
                                    for each equipment record. Its result is the total
                                    quantity recorded in borrowing details for that item.
                                </p>

                            </div>

                            <div class="row g-3 mb-3">

                                <div class="col-md-4">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            REGISTERED EQUIPMENT
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber(count($equipment)) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            AVAILABLE QUANTITY
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($totalAvailable) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            QUERY RESULT RECORDS
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($recordCount) ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                        </div>


                        <div class="week10-toolbar week10-no-print">

                            <div class="week10-search input-group input-group-sm">

                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="tableSearch"
                                    placeholder="Search equipment..."
                                >

                            </div>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                onclick="exportTableCSV('equipment-results.csv')"
                            >
                                <i class="bi bi-download me-1"></i>
                                Export CSV
                            </button>

                        </div>


                        <div class="week10-table-wrapper">

                            <table class="table table-hover week10-table" id="resultsTable">

                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Property Number</th>
                                        <th>Equipment Name</th>
                                        <th>Available Quantity</th>
                                        <th>Total Quantity Borrowed</th>
                                        <th>Recorded Usage</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php if (empty($equipment)): ?>

                                        <tr>
                                            <td colspan="6">
                                                <div class="week10-empty">
                                                    <i class="bi bi-inbox"></i>
                                                    No equipment records found.
                                                </div>
                                            </td>
                                        </tr>

                                    <?php else: ?>

                                        <?php foreach ($equipment as $index => $item): ?>

                                            <?php
                                                $borrowed = (int)$item["total_borrowed"];

                                                if ($borrowed === 0) {
                                                    $usage = "Not Borrowed";
                                                    $usageClass = "secondary";
                                                } elseif ($borrowed <= 2) {
                                                    $usage = "Occasional";
                                                    $usageClass = "info";
                                                } else {
                                                    $usage = "Frequently Borrowed";
                                                    $usageClass = "success";
                                                }
                                            ?>

                                            <tr>

                                                <td><?= $index + 1 ?></td>

                                                <td>
                                                    <?= e($item["property_no"]) ?>
                                                </td>

                                                <td class="fw-semibold">
                                                    <?= e($item["equipment_name"]) ?>
                                                </td>

                                                <td>
                                                    <?= formatNumber($item["quantity_available"]) ?>
                                                </td>

                                                <td>
                                                    <strong>
                                                        <?= formatNumber($borrowed) ?>
                                                    </strong>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= $usageClass ?>">
                                                        <?= e($usage) ?>
                                                    </span>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>


                        <div class="p-3 border-top">

                            <p class="text-muted small mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Recorded usage is based on the function's returned borrowing
                                total. It should not be interpreted as the current number
                                of units physically outside the inventory unless the
                                underlying function is specifically designed to calculate
                                outstanding loans.
                            </p>

                        </div>


                    <!-- =====================================
                         TRANSACTIONS
                    ====================================== -->

                    <?php elseif ($view === "transactions"): ?>

                        <?php
                            $borrowedCount = 0;
                            $returnedCount = 0;
                            $overdueCount = 0;

                            foreach ($transactions as $transaction) {

                                $currentStatus = strtolower(
                                    (string)$transaction["status"]
                                );

                                if ($currentStatus === "borrowed") {
                                    $borrowedCount++;
                                }

                                if (in_array($currentStatus, ["returned", "completed"], true)) {
                                    $returnedCount++;
                                }

                                if ($currentStatus === "overdue") {
                                    $overdueCount++;
                                }
                            }
                        ?>

                        <div class="p-4 pb-0">

                            <div class="row g-3 mb-3">

                                <div class="col-6 col-lg-3">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            TOTAL RECORDS
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber(count($transactions)) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-6 col-lg-3">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            BORROWED
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($borrowedCount) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-6 col-lg-3">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            RETURNED
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($returnedCount) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-6 col-lg-3">
                                    <div class="week10-panel">
                                        <div class="text-muted small fw-semibold">
                                            OVERDUE
                                        </div>
                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber($overdueCount) ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="alert alert-light border small">
                                This section summarizes existing borrowing records.
                                The transaction status is read directly from PostgreSQL;
                                this page does not modify transaction information.
                            </div>

                        </div>


                        <div class="week10-toolbar week10-no-print">

                            <div class="week10-search input-group input-group-sm">

                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="tableSearch"
                                    placeholder="Search transactions..."
                                >

                            </div>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                onclick="exportTableCSV('transaction-results.csv')"
                            >
                                <i class="bi bi-download me-1"></i>
                                Export CSV
                            </button>

                        </div>


                        <div class="week10-table-wrapper">

                            <table class="table table-hover week10-table" id="resultsTable">

                                <thead>
                                    <tr>
                                        <th>Transaction ID</th>
                                        <th>Borrower Number</th>
                                        <th>Borrower Name</th>
                                        <th>Borrow Date</th>
                                        <th>Due Date</th>
                                        <th>Items</th>
                                        <th>Total Quantity</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php if (empty($transactions)): ?>

                                        <tr>
                                            <td colspan="8">
                                                <div class="week10-empty">
                                                    <i class="bi bi-inbox"></i>
                                                    No borrowing transactions found.
                                                </div>
                                            </td>
                                        </tr>

                                    <?php else: ?>

                                        <?php foreach ($transactions as $transaction): ?>

                                            <tr>

                                                <td>
                                                    #<?= e($transaction["borrow_id"]) ?>
                                                </td>

                                                <td>
                                                    <?= e($transaction["borrower_no"]) ?>
                                                </td>

                                                <td class="fw-semibold">
                                                    <?= e($transaction["full_name"]) ?>
                                                </td>

                                                <td>
                                                    <?= e($transaction["borrow_date"]) ?>
                                                </td>

                                                <td>
                                                    <?= e($transaction["due_date"]) ?>
                                                </td>

                                                <td>
                                                    <?= formatNumber($transaction["item_count"]) ?>
                                                </td>

                                                <td>
                                                    <?= formatNumber($transaction["total_quantity"]) ?>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= statusClass($transaction["status"]) ?>">
                                                        <?= e($transaction["status"]) ?>
                                                    </span>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>


                    <!-- =====================================
                         AUDIT TRAIL
                    ====================================== -->

                    <?php elseif ($view === "audit"): ?>

                        <div class="p-4 pb-0">

                            <div class="alert alert-primary small">

                                <strong>
                                    <i class="bi bi-shield-check me-2"></i>
                                    Understanding the Audit Trigger
                                </strong>

                                <p class="mb-0 mt-2">
                                    An audit trigger automatically records qualifying
                                    changes to transaction statuses. This makes it possible
                                    to examine what changed and when the change was recorded.
                                </p>

                            </div>


                            <div class="row g-3 mb-3">

                                <div class="col-md-4">
                                    <div class="week10-panel">

                                        <div class="text-muted small fw-semibold">
                                            TOTAL AUDIT EVENTS
                                        </div>

                                        <div class="week10-insight-number mt-2">
                                            <?= formatNumber(count($auditLogs)) ?>
                                        </div>

                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">

                                        <div class="text-muted small fw-semibold">
                                            RECENT EVENT
                                        </div>

                                        <div class="mt-2 fw-bold text-dark">
                                            <?= !empty($auditLogs)
                                                ? e($auditLogs[0]["changed_at"])
                                                : "No events yet" ?>
                                        </div>

                                        <div class="text-muted small">
                                            Latest recorded timestamp
                                        </div>

                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="week10-panel">

                                        <div class="text-muted small fw-semibold">
                                            AUDIT STATUS
                                        </div>

                                        <div class="mt-2">

                                            <?php if (!empty($auditLogs)): ?>

                                                <span class="badge text-bg-success">
                                                    Records Available
                                                </span>

                                            <?php else: ?>

                                                <span class="badge text-bg-secondary">
                                                    Awaiting Events
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                        <div class="text-muted small mt-2">
                                            Based on existing audit records
                                        </div>

                                    </div>
                                </div>

                            </div>

                        </div>


                        <div class="week10-toolbar week10-no-print">

                            <div class="week10-search input-group input-group-sm">

                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="tableSearch"
                                    placeholder="Search audit records..."
                                >

                            </div>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                onclick="exportTableCSV('audit-trail.csv')"
                            >
                                <i class="bi bi-download me-1"></i>
                                Export CSV
                            </button>

                        </div>


                        <div class="week10-table-wrapper">

                            <table class="table table-hover week10-table" id="resultsTable">

                                <thead>
                                    <tr>
                                        <th>Audit ID</th>
                                        <th>Transaction ID</th>
                                        <th>Borrower Number</th>
                                        <th>Borrower Name</th>
                                        <th>Previous Status</th>
                                        <th>New Status</th>
                                        <th>Date Changed</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php if (empty($auditLogs)): ?>

                                        <tr>
                                            <td colspan="8">

                                                <div class="week10-empty">

                                                    <i class="bi bi-journal-x"></i>

                                                    <h6 class="fw-bold">
                                                        No Audit Records Yet
                                                    </h6>

                                                    <p class="mb-0">
                                                        Audit entries will appear after
                                                        a qualifying transaction status
                                                        change is recorded by the trigger.
                                                    </p>

                                                </div>

                                            </td>
                                        </tr>

                                    <?php else: ?>

                                        <?php foreach ($auditLogs as $log): ?>

                                            <tr>

                                                <td>
                                                    #<?= e($log["audit_id"]) ?>
                                                </td>

                                                <td>
                                                    #<?= e($log["borrow_id"]) ?>
                                                </td>

                                                <td>
                                                    <?= e($log["borrower_no"] ?? "Unavailable") ?>
                                                </td>

                                                <td class="fw-semibold">
                                                    <?= e($log["full_name"] ?? "Borrower record unavailable") ?>
                                                </td>

                                                <td>
                                                    <span class="badge bg-secondary-subtle text-secondary">
                                                        <?= e($log["old_status"]) ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <span class="badge bg-success-subtle text-success">
                                                        <?= e($log["new_status"]) ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <?= e($log["changed_at"]) ?>
                                                </td>

                                                <td>
                                                    <?= e($log["note"]) ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>


                        <!-- TRIGGER TESTING GUIDE -->

                        <div class="p-4">

                            <div class="week10-panel">

                                <h6 class="fw-bold mb-2">
                                    <i class="bi bi-terminal me-2 text-primary"></i>
                                    How to Test the Audit Trigger
                                </h6>

                                <p class="text-muted small">
                                    Use the following procedure to verify that the
                                    PostgreSQL trigger records a real transaction
                                    status change.
                                </p>

                                <div class="week10-step">

                                    <span class="week10-step-number">1</span>

                                    <div>
                                        <strong>Open pgAdmin or PostgreSQL Shell</strong>
                                        <p>
                                            Connect to the database containing your
                                            borrowing system.
                                        </p>
                                    </div>

                                </div>

                                <div class="week10-step">

                                    <span class="week10-step-number">2</span>

                                    <div>
                                        <strong>Inspect an existing transaction</strong>
                                        <p>
                                            Identify a valid borrow ID and its current
                                            status before testing.
                                        </p>
                                    </div>

                                </div>

                                <div class="week10-step">

                                    <span class="week10-step-number">3</span>

                                    <div>
                                        <strong>Perform a valid status update</strong>
                                        <p>
                                            Use an appropriate status transition for
                                            your existing transaction data.
                                        </p>
                                    </div>

                                </div>

                                <div class="week10-step">

                                    <span class="week10-step-number">4</span>

                                    <div>
                                        <strong>Inspect the audit table</strong>
                                        <p>
                                            Return to this page and refresh the Audit Trail
                                            to check whether the previous and new statuses
                                            were recorded.
                                        </p>
                                    </div>

                                </div>

                                <div class="alert alert-warning small mb-0">

                                    <i class="bi bi-exclamation-circle me-2"></i>

                                    Do not run an update against an arbitrary transaction.
                                    Use a test record or a transaction that you are
                                    authorized to modify. The exact update statement
                                    depends on your existing status values and trigger
                                    conditions.

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>


                <!-- FOOTER -->

                <div class="border-top px-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2 week10-footer">

                    <span class="text-muted small">
                        Week 10 Database Administration Laboratory
                    </span>

                    <span class="text-muted small">
                        PostgreSQL · Functions · Triggers · Audit Monitoring
                    </span>

                </div>

            </div>


            <!-- QUERY INFORMATION -->

            <?php if ($queryTime !== null && $errorMessage === ""): ?>

                <div class="text-muted small mt-3">

                    <i class="bi bi-speedometer2 me-1"></i>

                    <?= formatNumber($recordCount) ?> records retrieved

                    <span class="mx-2">·</span>

                    <?= number_format($queryTime, 2) ?> ms PHP query-and-fetch time

                </div>

            <?php endif; ?>


            <div class="text-muted small mt-3">

                <i class="bi bi-info-circle me-1"></i>

                Data is retrieved directly from the PostgreSQL public schema.
                Function results are calculated by PostgreSQL, while qualifying
                transaction status changes are recorded by the database trigger.

            </div>

        </div>

    </main>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>

// =========================================
// TABLE SEARCH
// =========================================

const searchInput = document.getElementById("tableSearch");
const resultsTable = document.getElementById("resultsTable");

if (searchInput && resultsTable) {

    searchInput.addEventListener("input", function () {

        const searchValue = this.value.toLowerCase();
        const rows = resultsTable.querySelectorAll("tbody tr");

        rows.forEach(function (row) {

            const rowText = row.textContent.toLowerCase();

            row.style.display = rowText.includes(searchValue)
                ? ""
                : "none";

        });

    });

}


// =========================================
// CSV EXPORT
// =========================================

function exportTableCSV(filename) {

    const table = document.getElementById("resultsTable");

    if (!table) {
        alert("No table is available for export.");
        return;
    }

    const rows = Array.from(table.querySelectorAll("tr"));

    const csv = rows.map(function (row) {

        const cells = Array.from(row.querySelectorAll("th, td"));

        return cells.map(function (cell) {

            let value = cell.innerText
                .replace(/\s+/g, " ")
                .trim();

            value = value.replace(/"/g, '""');

            return '"' + value + '"';

        }).join(",");

    }).join("\n");

    const blob = new Blob(
        ["\uFEFF" + csv],
        { type: "text/csv;charset=utf-8;" }
    );

    const link = document.createElement("a");

    const url = URL.createObjectURL(blob);

    link.setAttribute("href", url);
    link.setAttribute("download", filename);

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);

}

</script>

</body>
</html>
