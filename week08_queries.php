<?php
session_start();
require_once "../includes/db.php";

function e($value): string
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/*
|--------------------------------------------------------------------------
| MODULE SELECTION
|--------------------------------------------------------------------------
*/

$view = $_GET["view"] ?? "views";

$allowedViews = [
    "views",
    "case",
    "transactions",
    "constraints"
];

if (!in_array($view, $allowedViews, true)) {
    $view = "views";
}

/*
|--------------------------------------------------------------------------
| PAGE VARIABLES
|--------------------------------------------------------------------------
*/

$records = [];
$columns = [];
$sql = "";

$pageTitle = "";
$pageDescription = "";
$errorMessage = "";

$transactionDemo = null;

$queryExecuted = false;
$executionTime = 0;
$executionTimeLabel = "Not executed";

$stats = [
    "total_borrowers" => 0,
    "total_equipment" => 0,
    "total_transactions" => 0,
    "total_items" => 0
];

$moduleInformation = [
    "views" => [
        "icon" => "bi-eye",
        "name" => "Database Views",
        "purpose" => "Create and retrieve a summarized virtual table containing borrower and borrowing information.",
        "explanation" => "A database view is a virtual table based on a SELECT statement. It allows users to access summarized information without repeatedly writing the same complex query.",
        "expected" => "A borrower summary showing transaction counts, item records, and total borrowed quantities."
    ],

    "case" => [
        "icon" => "bi-diagram-2",
        "name" => "Conditional SQL",
        "purpose" => "Classify borrowing transactions according to their current status and due dates.",
        "explanation" => "CASE evaluates conditions in order and returns the result associated with the first matching condition. It is useful for generating classifications and conditional descriptions.",
        "expected" => "A transaction list with classifications such as Completed, Cancelled, Overdue, and Active."
    ],

    "transactions" => [
        "icon" => "bi-arrow-left-right",
        "name" => "Transaction Control",
        "purpose" => "Demonstrate how PostgreSQL saves or reverses database changes.",
        "explanation" => "BEGIN starts a transaction. COMMIT permanently saves its changes. ROLLBACK cancels uncommitted changes. The demonstration uses a temporary table so permanent application data remains untouched.",
        "expected" => "A successful COMMIT demonstration and a ROLLBACK demonstration showing that the inserted test record is removed."
    ],

    "constraints" => [
        "icon" => "bi-shield-check",
        "name" => "Database Constraints",
        "purpose" => "Inspect the actual constraints defined in the application's PostgreSQL database.",
        "explanation" => "Constraints enforce database integrity. They include PRIMARY KEY, FOREIGN KEY, UNIQUE, CHECK, and NOT NULL rules. This module reads PostgreSQL's system catalog without changing existing constraints.",
        "expected" => "A list of actual constraints and NOT NULL column requirements for the four application tables."
    ]
];

/*
|--------------------------------------------------------------------------
| QUERY EXECUTION HELPER
|--------------------------------------------------------------------------
*/

function executeQuery(PDO $pdo, string $sql): array
{
    $start = hrtime(true);

    $statement = $pdo->query($sql);

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $elapsed = (hrtime(true) - $start) / 1000000;

    return [
        "records" => $rows,
        "time" => $elapsed
    ];
}

/*
|--------------------------------------------------------------------------
| DATABASE OPERATIONS
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD STATISTICS
    |--------------------------------------------------------------------------
    */

    $statsResult = $pdo->query("
        SELECT
            (SELECT COUNT(*)
             FROM public.borrowers) AS total_borrowers,

            (SELECT COUNT(*)
             FROM public.equipment) AS total_equipment,

            (SELECT COUNT(*)
             FROM public.borrow_transactions) AS total_transactions,

            (SELECT COUNT(*)
             FROM public.borrow_items) AS total_items
    ")->fetch(PDO::FETCH_ASSOC);

    if ($statsResult) {

        foreach ($stats as $key => $value) {

            $stats[$key] = (int)($statsResult[$key] ?? 0);

        }

    }


    /*
    |--------------------------------------------------------------------------
    | MODULE 1: DATABASE VIEWS
    |--------------------------------------------------------------------------
    */

    if ($view === "views") {

        $pageTitle = "Borrowing Summary View";

        $pageDescription = "Create and retrieve a temporary view containing summarized borrower and transaction information.";

        /*
         * Temporary view:
         * It exists only within the current database session.
         * CREATE OR REPLACE also handles an existing temporary view.
         */

        $pdo->exec("
            CREATE OR REPLACE TEMP VIEW week08_borrowing_summary AS

            SELECT
                b.borrower_id,
                b.borrower_no,
                b.full_name,
                b.borrower_type,

                COUNT(DISTINCT bt.borrow_id)
                    AS total_transactions,

                COUNT(bi.borrow_item_id)
                    AS total_item_records,

                COALESCE(SUM(bi.quantity), 0)
                    AS total_quantity_borrowed

            FROM public.borrowers AS b

            LEFT JOIN public.borrow_transactions AS bt
                ON bt.borrower_id = b.borrower_id

            LEFT JOIN public.borrow_items AS bi
                ON bi.borrow_id = bt.borrow_id

            GROUP BY
                b.borrower_id,
                b.borrower_no,
                b.full_name,
                b.borrower_type
        ");

        $sql = "
SELECT
    borrower_id,
    borrower_no,
    full_name,
    borrower_type,
    total_transactions,
    total_item_records,
    total_quantity_borrowed

FROM week08_borrowing_summary

ORDER BY full_name ASC;
";

        $result = executeQuery($pdo, $sql);

        $records = $result["records"];
        $executionTime = $result["time"];

        $queryExecuted = true;

    }


    /*
    |--------------------------------------------------------------------------
    | MODULE 2: CONDITIONAL SQL / CASE
    |--------------------------------------------------------------------------
    */

    elseif ($view === "case") {

        $pageTitle = "Conditional SQL Analysis";

        $pageDescription = "Classify borrowing transactions using CASE, WHEN, and THEN.";

        $sql = "
SELECT
    bt.borrow_id,
    b.borrower_no,
    b.full_name,
    bt.borrow_date,
    bt.due_date,
    bt.status,

    CASE
        WHEN bt.status IN ('Returned', 'Completed')
            THEN 'Completed'

        WHEN bt.status = 'Cancelled'
            THEN 'Cancelled'

        WHEN bt.due_date < CURRENT_DATE
            THEN 'Overdue'

        ELSE 'Active'
    END AS transaction_classification,

    CASE
        WHEN bt.status IN ('Returned', 'Completed', 'Cancelled')
            THEN 'No Action Required'

        WHEN bt.due_date < CURRENT_DATE
            THEN 'Return Immediately'

        ELSE 'Within Due Date'
    END AS borrowing_reminder

FROM public.borrow_transactions AS bt

INNER JOIN public.borrowers AS b
    ON b.borrower_id = bt.borrower_id

ORDER BY bt.borrow_id DESC;
";

        $result = executeQuery($pdo, $sql);

        $records = $result["records"];
        $executionTime = $result["time"];

        $queryExecuted = true;

    }


    /*
    |--------------------------------------------------------------------------
    | MODULE 3: TRANSACTION CONTROL
    |--------------------------------------------------------------------------
    */

    elseif ($view === "transactions") {

        $pageTitle = "Transaction Management";

        $pageDescription = "Demonstrate BEGIN, COMMIT, and ROLLBACK using isolated temporary test data.";

        /*
         * Remove an existing temporary table if the same
         * database session is reused.
         */

        $pdo->exec("
            DROP TABLE IF EXISTS week08_transaction_demo
        ");

        /*
         * Create a temporary table.
         * This does not modify permanent application tables.
         */

        $pdo->exec("
            CREATE TEMP TABLE week08_transaction_demo (

                demo_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

                description VARCHAR(100) NOT NULL

            ) ON COMMIT PRESERVE ROWS
        ");

        /*
        |--------------------------------------------------------------------------
        | DEMONSTRATION 1: COMMIT
        |--------------------------------------------------------------------------
        */

        $startCommit = hrtime(true);

        $pdo->beginTransaction();

        $pdo->exec("
            INSERT INTO week08_transaction_demo (description)

            VALUES ('Committed test record')
        ");

        $countBeforeCommit = (int)$pdo->query("
            SELECT COUNT(*)
            FROM week08_transaction_demo
        ")->fetchColumn();

        $pdo->commit();

        $countAfterCommit = (int)$pdo->query("
            SELECT COUNT(*)
            FROM week08_transaction_demo
        ")->fetchColumn();

        $commitTime = (hrtime(true) - $startCommit) / 1000000;


        /*
        |--------------------------------------------------------------------------
        | DEMONSTRATION 2: ROLLBACK
        |--------------------------------------------------------------------------
        */

        $startRollback = hrtime(true);

        $pdo->beginTransaction();

        $pdo->exec("
            INSERT INTO week08_transaction_demo (description)

            VALUES ('Rolled back test record')
        ");

        $countBeforeRollback = (int)$pdo->query("
            SELECT COUNT(*)
            FROM week08_transaction_demo
        ")->fetchColumn();

        $pdo->rollBack();

        $countAfterRollback = (int)$pdo->query("
            SELECT COUNT(*)
            FROM week08_transaction_demo
        ")->fetchColumn();

        $rollbackTime = (hrtime(true) - $startRollback) / 1000000;


        /*
        |--------------------------------------------------------------------------
        | VERIFY FINAL TRANSACTION DATA
        |--------------------------------------------------------------------------
        */

        $transactionRecords = $pdo->query("
            SELECT
                demo_id,
                description
            FROM week08_transaction_demo
            ORDER BY demo_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $transactionDemo = [
            "before_commit" => $countBeforeCommit,
            "after_commit" => $countAfterCommit,
            "before_rollback" => $countBeforeRollback,
            "after_rollback" => $countAfterRollback,
            "commit_time" => $commitTime,
            "rollback_time" => $rollbackTime,
            "records" => $transactionRecords
        ];

        $executionTime = $commitTime + $rollbackTime;

        $queryExecuted = true;

        /*
         * This is a demonstration script.
         * The commands were executed individually using PDO.
         */

        $sql = "
-- CREATE TEMPORARY TABLE

CREATE TEMP TABLE week08_transaction_demo (
    demo_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    description VARCHAR(100) NOT NULL
) ON COMMIT PRESERVE ROWS;


-- DEMONSTRATION 1: COMMIT

BEGIN;

INSERT INTO week08_transaction_demo (description)
VALUES ('Committed test record');

COMMIT;


-- DEMONSTRATION 2: ROLLBACK

BEGIN;

INSERT INTO week08_transaction_demo (description)
VALUES ('Rolled back test record');

ROLLBACK;


-- VERIFY FINAL RECORDS

SELECT *
FROM week08_transaction_demo;
";

    }


    /*
    |--------------------------------------------------------------------------
    | MODULE 4: DATABASE CONSTRAINTS
    |--------------------------------------------------------------------------
    */

    elseif ($view === "constraints") {

        $pageTitle = "Database Constraints";

        $pageDescription = "Inspect actual primary key, foreign key, unique, check, and NOT NULL requirements in PostgreSQL.";

        $sql = "
SELECT
    table_name,
    constraint_name,
    constraint_type,
    constraint_definition

FROM (

    SELECT
        tbl.relname AS table_name,

        con.conname AS constraint_name,

        CASE con.contype
            WHEN 'p' THEN 'PRIMARY KEY'
            WHEN 'f' THEN 'FOREIGN KEY'
            WHEN 'u' THEN 'UNIQUE'
            WHEN 'c' THEN 'CHECK'
            WHEN 'x' THEN 'EXCLUSION'
            ELSE 'OTHER'
        END AS constraint_type,

        pg_get_constraintdef(con.oid)
            AS constraint_definition,

        1 AS sort_order

    FROM pg_constraint AS con

    INNER JOIN pg_class AS tbl
        ON tbl.oid = con.conrelid

    INNER JOIN pg_namespace AS ns
        ON ns.oid = tbl.relnamespace

    WHERE ns.nspname = 'public'

        AND tbl.relname IN (
            'borrowers',
            'equipment',
            'borrow_transactions',
            'borrow_items'
        )

    UNION ALL

    SELECT
        tbl.relname AS table_name,

        attr.attname || '_not_null'
            AS constraint_name,

        'NOT NULL' AS constraint_type,

        attr.attname || ' IS NOT NULL'
            AS constraint_definition,

        2 AS sort_order

    FROM pg_attribute AS attr

    INNER JOIN pg_class AS tbl
        ON tbl.oid = attr.attrelid

    INNER JOIN pg_namespace AS ns
        ON ns.oid = tbl.relnamespace

    WHERE ns.nspname = 'public'

        AND tbl.relname IN (
            'borrowers',
            'equipment',
            'borrow_transactions',
            'borrow_items'
        )

        AND attr.attnum > 0
        AND NOT attr.attisdropped
        AND attr.attnotnull = TRUE

) AS database_constraints

ORDER BY
    table_name ASC,
    sort_order ASC,
    constraint_name ASC;
";

        $result = executeQuery($pdo, $sql);

        $records = $result["records"];
        $executionTime = $result["time"];

        $queryExecuted = true;

    }


    /*
    |--------------------------------------------------------------------------
    | AUTOMATICALLY DETERMINE TABLE COLUMNS
    |--------------------------------------------------------------------------
    */

    if (!empty($records)) {

        $columns = array_keys($records[0]);

    }

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {

        $pdo->rollBack();

    }

    error_log("Week 8 error: " . $exception->getMessage());

    $errorMessage = "Unable to complete this module. Please check the database connection, schema, and permissions.";

}

/*
|--------------------------------------------------------------------------
| EXECUTION METRICS
|--------------------------------------------------------------------------
*/

$executionTimeLabel = $queryExecuted
    ? number_format($executionTime, 3) . " ms"
    : "Not executed";

$totalRecords = count($records);
$totalColumns = count($columns);

$constraintCounts = [];

if ($view === "constraints" && !empty($records)) {

    foreach ($records as $record) {

        $type = $record["constraint_type"];

        $constraintCounts[$type] = ($constraintCounts[$type] ?? 0) + 1;

    }

}

$classificationCounts = [];

if ($view === "case" && !empty($records)) {

    foreach ($records as $record) {

        $classification = $record["transaction_classification"];

        $classificationCounts[$classification] =
            ($classificationCounts[$classification] ?? 0) + 1;

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Week 8 | Database Operations</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

/* ================================================================
   GENERAL
================================================================ */

        .stat-card,
        .table-card,
        .concept-card,
        .explanation-card,
        .analytics-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 22px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .section-heading h5 {
            font-size: 16px;
            margin: 0;
        }

        .muted {
            color: var(--muted);
        }

/* ================================================================
   HEADER
================================================================ */

        .week-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 13px;
            border-radius: 30px;
            background: #edf3ff;
            color: #315cc5;
            font-size: 10px;
            font-weight: 800;
        }

        .intro-text {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.8;
            max-width: 750px;
        }

/* ================================================================
   STATISTICS
================================================================ */

        .stat-card {
            height: 100%;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .stat-number {
            font-size: 26px;
            font-weight: 800;
            margin-top: 12px;
            color: #25334d;
        }

        .stat-label {
            font-size: 10px;
            color: var(--muted);
            font-weight: 800;
            letter-spacing: .4px;
            text-transform: uppercase;
        }

/* ================================================================
   CONCEPT CARDS
================================================================ */

        .concept-card {
            height: 100%;
        }

        .concept-label {
            font-size: 10px;
            color: var(--muted);
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .concept-card h6 {
            font-size: 13px;
            font-weight: 800;
        }

        .concept-description {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.7;
        }

        .sql-badge {
            display: inline-block;
            background: #f1f0ff;
            color: #6246c7;
            padding: 6px 9px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 10px;
            font-weight: 700;
        }

/* ================================================================
   NAVIGATION
================================================================ */

        .module-nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .module-nav a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            border: 1px solid var(--border);
            padding: 10px 15px;
            border-radius: 9px;
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
            background: white;
            transition: .2s ease;
        }

        .module-nav a:hover {
            background: #f5f7fc;
        }

        .module-nav a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

/* ================================================================
   EXPLANATION
================================================================ */

        .explanation-card {
            margin-bottom: 20px;
        }

        .explanation-box {
            height: 100%;
            padding: 17px;
            background: #f7f9fc;
            border: 1px solid #edf0f5;
            border-radius: 11px;
        }

        .explanation-box .label {
            font-size: 10px;
            font-weight: 800;
            color: #7b879c;
            letter-spacing: .5px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .explanation-box p {
            font-size: 12px;
            color: #43516b;
            line-height: 1.8;
            margin: 0;
        }

/* ================================================================
   SQL PREVIEW
================================================================ */

        .sql-preview {
            background: #101d3a;
            color: #d8e5ff;
            border-radius: 11px;
            padding: 20px;
            overflow-x: auto;
            font-size: 11px;
            line-height: 1.9;
            white-space: pre;
            margin: 0;
            max-height: 440px;
        }

        .sql-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 13px;
        }

/* ================================================================
   METRICS
================================================================ */

        .metric-card {
            background: #f7f9fc;
            border: 1px solid #edf0f5;
            border-radius: 11px;
            padding: 16px;
            height: 100%;
        }

        .metric-card small {
            display: block;
            color: #7b879c;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .metric-card strong {
            display: block;
            color: #283a58;
            font-size: 21px;
            font-weight: 800;
            margin-top: 7px;
        }

        .execution-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 30px;
            background: #e6f7ed;
            color: #16834c;
            font-size: 10px;
            font-weight: 800;
        }

/* ================================================================
   TABLE
================================================================ */

        .table-tools {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }

        .table-tools input {
            max-width: 300px;
            font-size: 12px;
        }

        .table {
            font-size: 11px;
            margin-bottom: 0;
        }

        .table thead th {
            background: #f3f6fb;
            color: #68778f;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: nowrap;
            padding: 14px 12px;
            cursor: pointer;
            border-bottom: 1px solid #e8edf5;
        }

        .table tbody td {
            padding: 12px;
            color: #43516b;
            vertical-align: middle;
            white-space: nowrap;
        }

        .table tbody tr:hover {
            background: #f8faff;
        }

        .result-count {
            background: #edf3ff;
            color: #315cc5;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
        }

/* ================================================================
   TRANSACTION RESULTS
================================================================ */

        .transaction-result {
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 20px;
            height: 100%;
        }

        .transaction-result .result-number {
            font-size: 30px;
            font-weight: 800;
        }

        .transaction-step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .step-number {
            min-width: 28px;
            width: 28px;
            height: 28px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            background: #edf3ff;
            color: #315cc5;
            font-size: 11px;
            font-weight: 800;
        }

        .transaction-step strong {
            display: block;
            font-size: 12px;
            margin-bottom: 3px;
        }

        .transaction-step small {
            display: block;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
        }

/* ================================================================
   BADGES
================================================================ */

        .constraint-badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 800;
            background: #edf3ff;
            color: #315cc5;
        }

        .classification-badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 800;
        }

/* ================================================================
   EMPTY STATE / NOTES
================================================================ */

        .empty-state {
            text-align: center;
            padding: 45px 20px;
            color: #8793a8;
        }

        .empty-state i {
            font-size: 38px;
        }

        .info-note {
            background: #fff9e9;
            border: 1px solid #f5e6b5;
            color: #806523;
            border-radius: 10px;
            padding: 15px;
            font-size: 11px;
            line-height: 1.8;
        }

        .success-note {
            background: #eaf8f0;
            border: 1px solid #d3efdf;
            color: #286b47;
            border-radius: 10px;
            padding: 15px;
            font-size: 11px;
            line-height: 1.8;
        }

/* ================================================================
   RESPONSIVE
================================================================ */

        @media (max-width: 768px) {

            .stat-card,
            .table-card,
            .concept-card,
            .explanation-card,
            .analytics-card {
                padding: 16px;
            }

            .stat-number {
                font-size: 22px;
            }

            .module-nav {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
            }

            .module-nav a {
                justify-content: center;
                text-align: center;
                padding: 11px 7px;
            }

            .table-tools {
                align-items: stretch;
                flex-direction: column;
            }

            .table-tools input {
                width: 100%;
                max-width: 100%;
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

        <!-- =====================================================
             HEADER
        ====================================================== -->

        <header class="topbar">

            <div>

                <div class="breadcrumb-text">
                    PART 4 / WEEK 8
                </div>

                <h4>Database Operations</h4>

            </div>

        </header>


        <div class="content-area">

            <!-- =================================================
                 PAGE INTRODUCTION
            ================================================== -->

            <section class="welcome-section">

                <div>

                    <div class="week-badge mb-3">

                        <i class="bi bi-database-gear"></i>

                        WEEK 8 · DATABASE ADMINISTRATION

                    </div>

                    <h2>Views, CASE, Constraints & Transactions</h2>

                    <p class="intro-text mb-0">

                        Explore advanced PostgreSQL database operations
                        through the Equipment Borrowing Management System.
                        This module demonstrates virtual views, conditional
                        SQL expressions, database integrity constraints,
                        and transaction control using actual database
                        information and isolated test records.

                    </p>

                </div>

            </section>


            <!-- =================================================
                 DASHBOARD STATISTICS
            ================================================== -->

            <div class="row g-3 mb-4">

                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-primary-subtle text-primary">

                            <i class="bi bi-people"></i>

                        </div>

                        <div class="stat-number">

                            <?= number_format($stats["total_borrowers"]) ?>

                        </div>

                        <div class="stat-label">
                            Total Borrowers
                        </div>

                    </div>

                </div>


                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-info-subtle text-info">

                            <i class="bi bi-laptop"></i>

                        </div>

                        <div class="stat-number">

                            <?= number_format($stats["total_equipment"]) ?>

                        </div>

                        <div class="stat-label">
                            Equipment Records
                        </div>

                    </div>

                </div>


                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-success-subtle text-success">

                            <i class="bi bi-journal-check"></i>

                        </div>

                        <div class="stat-number">

                            <?= number_format($stats["total_transactions"]) ?>

                        </div>

                        <div class="stat-label">
                            Total Transactions
                        </div>

                    </div>

                </div>


                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-warning-subtle text-warning">

                            <i class="bi bi-box-seam"></i>

                        </div>

                        <div class="stat-number">

                            <?= number_format($stats["total_items"]) ?>

                        </div>

                        <div class="stat-label">
                            Borrowed Item Records
                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 MODULE OVERVIEW
            ================================================== -->

            <div class="row g-3 mb-4">

                <div class="col-md-3">

                    <div class="concept-card">

                        <div class="concept-label">
                            01 · Database Views
                        </div>

                        <div class="stat-icon bg-primary-subtle text-primary my-3">
                            <i class="bi bi-eye"></i>
                        </div>

                        <h6>Virtual Tables</h6>

                        <p class="concept-description">
                            Create a summarized database view from existing
                            borrower and transaction information.
                        </p>

                        <span class="sql-badge">CREATE VIEW</span>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="concept-card">

                        <div class="concept-label">
                            02 · Conditional Logic
                        </div>

                        <div class="stat-icon bg-info-subtle text-info my-3">
                            <i class="bi bi-diagram-2"></i>
                        </div>

                        <h6>CASE Statements</h6>

                        <p class="concept-description">
                            Generate classifications and reminders based
                            on transaction conditions.
                        </p>

                        <span class="sql-badge">CASE WHEN</span>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="concept-card">

                        <div class="concept-label">
                            03 · Data Integrity
                        </div>

                        <div class="stat-icon bg-success-subtle text-success my-3">
                            <i class="bi bi-shield-check"></i>
                        </div>

                        <h6>Constraints</h6>

                        <p class="concept-description">
                            Inspect database rules that protect the
                            consistency of stored records.
                        </p>

                        <span class="sql-badge">PRIMARY / FOREIGN KEY</span>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="concept-card">

                        <div class="concept-label">
                            04 · Transaction Control
                        </div>

                        <div class="stat-icon bg-warning-subtle text-warning my-3">
                            <i class="bi bi-arrow-left-right"></i>
                        </div>

                        <h6>Transaction Control</h6>

                        <p class="concept-description">
                            Demonstrate how database changes can be
                            saved or reversed.
                        </p>

                        <span class="sql-badge">COMMIT / ROLLBACK</span>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 MODULE NAVIGATION
            ================================================== -->

            <section class="table-card mb-4">

                <div class="section-heading mb-3">

                    <h5 class="fw-bold">

                        <i class="bi bi-grid me-2 text-primary"></i>

                        Practice Modules

                    </h5>

                    <span class="muted small">
                        Select a module to explore
                    </span>

                </div>


                <div class="module-nav">

                    <a
                        href="?view=views"
                        class="<?= $view === "views" ? "active" : "" ?>"
                    >

                        <i class="bi bi-eye"></i>

                        Database Views

                    </a>


                    <a
                        href="?view=case"
                        class="<?= $view === "case" ? "active" : "" ?>"
                    >

                        <i class="bi bi-diagram-2"></i>

                        CASE Statements

                    </a>


                    <a
                        href="?view=constraints"
                        class="<?= $view === "constraints" ? "active" : "" ?>"
                    >

                        <i class="bi bi-shield-check"></i>

                        Constraints

                    </a>


                    <a
                        href="?view=transactions"
                        class="<?= $view === "transactions" ? "active" : "" ?>"
                    >

                        <i class="bi bi-arrow-left-right"></i>

                        Transactions

                    </a>

                </div>

            </section>


            <!-- =================================================
                 MODULE EXPLANATION
            ================================================== -->

            <section class="explanation-card">

                <div class="section-heading mb-3">

                    <h5 class="fw-bold">

                        <i class="bi <?= e($moduleInformation[$view]["icon"]) ?> me-2 text-primary"></i>

                        <?= e($moduleInformation[$view]["name"]) ?>

                    </h5>

                    <span class="muted small">
                        Module Overview
                    </span>

                </div>


                <div class="row g-3">

                    <div class="col-lg-4">

                        <div class="explanation-box">

                            <div class="label">
                                Purpose
                            </div>

                            <p>
                                <?= e($moduleInformation[$view]["purpose"]) ?>
                            </p>

                        </div>

                    </div>


                    <div class="col-lg-4">

                        <div class="explanation-box">

                            <div class="label">
                                How It Works
                            </div>

                            <p>
                                <?= e($moduleInformation[$view]["explanation"]) ?>
                            </p>

                        </div>

                    </div>


                    <div class="col-lg-4">

                        <div class="explanation-box">

                            <div class="label">
                                Expected Output
                            </div>

                            <p>
                                <?= e($moduleInformation[$view]["expected"]) ?>
                            </p>

                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================================
                 EXECUTION ERROR
            ================================================== -->

            <?php if ($errorMessage !== ""): ?>

                <div class="alert alert-danger mb-4">

                    <i class="bi bi-exclamation-triangle me-2"></i>

                    <strong>Module Execution Failed</strong>

                    <p class="small mb-0 mt-1">
                        <?= e($errorMessage) ?>
                    </p>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 QUERY EXECUTION REPORT
            ================================================== -->

            <?php if ($queryExecuted && $errorMessage === ""): ?>

                <section class="analytics-card mb-4">

                    <div class="section-heading mb-4">

                        <h5 class="fw-bold">

                            <i class="bi bi-activity me-2 text-primary"></i>

                            Execution Report

                        </h5>

                        <span class="execution-status">

                            <i class="bi bi-check-circle-fill"></i>

                            Successfully Executed

                        </span>

                    </div>


                    <div class="row g-3">

                        <div class="col-6 col-xl-3">

                            <div class="metric-card">

                                <small>Execution Time</small>

                                <strong>
                                    <?= e($executionTimeLabel) ?>
                                </strong>

                            </div>

                        </div>


                        <div class="col-6 col-xl-3">

                            <div class="metric-card">

                                <small>Rows Returned</small>

                                <strong>
                                    <?= number_format($totalRecords) ?>
                                </strong>

                            </div>

                        </div>


                        <div class="col-6 col-xl-3">

                            <div class="metric-card">

                                <small>Columns Returned</small>

                                <strong>
                                    <?= number_format($totalColumns) ?>
                                </strong>

                            </div>

                        </div>


                        <div class="col-6 col-xl-3">

                            <div class="metric-card">

                                <small>Module</small>

                                <strong style="font-size:16px;">
                                    <?= e($moduleInformation[$view]["name"]) ?>
                                </strong>

                            </div>

                        </div>

                    </div>


                    <p class="muted mt-3 mb-0" style="font-size:10px;line-height:1.7;">

                        <i class="bi bi-info-circle me-1"></i>

                        Execution time measures PHP/PDO elapsed time,
                        including fetching the returned records. It is
                        not PostgreSQL's internal EXPLAIN ANALYZE execution time.

                    </p>

                </section>

            <?php endif; ?>


            <!-- =================================================
                 TRANSACTION VISUALIZATION
            ================================================== -->

            <?php if (
                $view === "transactions"
                && $transactionDemo !== null
                && $errorMessage === ""
            ): ?>

                <section class="table-card mb-4">

                    <div class="section-heading mb-4">

                        <h5 class="fw-bold">

                            <i class="bi bi-diagram-3 me-2 text-primary"></i>

                            Transaction Execution Flow

                        </h5>

                        <span class="execution-status">
                            <i class="bi bi-check-circle-fill"></i>
                            Completed
                        </span>

                    </div>


                    <div class="row g-4">

                        <!-- COMMIT -->

                        <div class="col-lg-6">

                            <div class="transaction-result">

                                <span class="badge bg-success-subtle text-success mb-3">
                                    COMMIT
                                </span>

                                <h6 class="fw-bold">
                                    Save Database Changes
                                </h6>

                                <p class="muted small">
                                    COMMIT permanently saves changes made
                                    within the active transaction.
                                </p>


                                <div class="transaction-step mt-4">

                                    <div class="step-number">1</div>

                                    <div>

                                        <strong>BEGIN</strong>

                                        <small>
                                            A new transaction is started.
                                        </small>

                                    </div>

                                </div>


                                <div class="transaction-step">

                                    <div class="step-number">2</div>

                                    <div>

                                        <strong>INSERT</strong>

                                        <small>
                                            A test record is inserted into
                                            the temporary table.
                                        </small>

                                    </div>

                                </div>


                                <div class="transaction-step">

                                    <div class="step-number">3</div>

                                    <div>

                                        <strong>COMMIT</strong>

                                        <small>
                                            The inserted record is saved.
                                        </small>

                                    </div>

                                </div>


                                <div class="row g-2 mt-3">

                                    <div class="col-6">

                                        <div class="metric-card">

                                            <small>Before Commit</small>

                                            <strong>
                                                <?= $transactionDemo["before_commit"] ?>
                                            </strong>

                                        </div>

                                    </div>

                                    <div class="col-6">

                                        <div class="metric-card">

                                            <small>After Commit</small>

                                            <strong class="text-success">
                                                <?= $transactionDemo["after_commit"] ?>
                                            </strong>

                                        </div>

                                    </div>

                                </div>


                                <div class="mt-3">

                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Test record saved
                                    </span>

                                </div>

                                <small class="muted d-block mt-3">
                                    Elapsed time:
                                    <?= number_format($transactionDemo["commit_time"], 3) ?> ms
                                </small>

                            </div>

                        </div>


                        <!-- ROLLBACK -->

                        <div class="col-lg-6">

                            <div class="transaction-result">

                                <span class="badge bg-danger-subtle text-danger mb-3">
                                    ROLLBACK
                                </span>

                                <h6 class="fw-bold">
                                    Reverse Database Changes
                                </h6>

                                <p class="muted small">
                                    ROLLBACK cancels changes made after
                                    the transaction begins.
                                </p>


                                <div class="transaction-step mt-4">

                                    <div class="step-number">1</div>

                                    <div>

                                        <strong>BEGIN</strong>

                                        <small>
                                            A new transaction is started.
                                        </small>

                                    </div>

                                </div>


                                <div class="transaction-step">

                                    <div class="step-number">2</div>

                                    <div>

                                        <strong>INSERT</strong>

                                        <small>
                                            Another test record is inserted
                                            into the temporary table.
                                        </small>

                                    </div>

                                </div>


                                <div class="transaction-step">

                                    <div class="step-number">3</div>

                                    <div>

                                        <strong>ROLLBACK</strong>

                                        <small>
                                            The inserted record is reversed.
                                        </small>

                                    </div>

                                </div>


                                <div class="row g-2 mt-3">

                                    <div class="col-6">

                                        <div class="metric-card">

                                            <small>Before Rollback</small>

                                            <strong>
                                                <?= $transactionDemo["before_rollback"] ?>
                                            </strong>

                                        </div>

                                    </div>

                                    <div class="col-6">

                                        <div class="metric-card">

                                            <small>After Rollback</small>

                                            <strong class="text-danger">
                                                <?= $transactionDemo["after_rollback"] ?>
                                            </strong>

                                        </div>

                                    </div>

                                </div>


                                <div class="mt-3">

                                    <span class="badge bg-danger">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                                        Test record reversed
                                    </span>

                                </div>

                                <small class="muted d-block mt-3">
                                    Elapsed time:
                                    <?= number_format($transactionDemo["rollback_time"], 3) ?> ms
                                </small>

                            </div>

                        </div>

                    </div>


                    <!-- FINAL TRANSACTION RECORDS -->

                    <div class="mt-4">

                        <div class="section-heading mb-3">

                            <h6 class="fw-bold">
                                Final Temporary Table Records
                            </h6>

                            <span class="result-count">
                                <?= count($transactionDemo["records"]) ?> record(s)
                            </span>

                        </div>


                        <?php if (!empty($transactionDemo["records"])): ?>

                            <div class="table-responsive">

                                <table class="table table-hover align-middle">

                                    <thead>

                                        <tr>

                                            <th>Demo ID</th>

                                            <th>Description</th>

                                            <th>Transaction Result</th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php foreach ($transactionDemo["records"] as $record): ?>

                                            <tr>

                                                <td>
                                                    <?= e($record["demo_id"]) ?>
                                                </td>

                                                <td>
                                                    <?= e($record["description"]) ?>
                                                </td>

                                                <td>

                                                    <span class="badge bg-success-subtle text-success">
                                                        Committed
                                                    </span>

                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php else: ?>

                            <div class="empty-state">
                                No committed records were found.
                            </div>

                        <?php endif; ?>

                    </div>


                    <div class="success-note mt-4">

                        <strong>
                            <i class="bi bi-shield-check me-1"></i>
                            Database Safety
                        </strong>

                        <p class="mb-0 mt-2">

                            Both demonstrations use a temporary table.
                            The committed test record exists only in that
                            temporary table, while the rolled-back test
                            record is removed. No permanent borrower,
                            equipment, or transaction records are modified.

                        </p>

                    </div>

                </section>

            <?php endif; ?>


            <!-- =================================================
                 CASE ANALYSIS SUMMARY
            ================================================== -->

            <?php if (
                $view === "case"
                && !empty($classificationCounts)
                && $errorMessage === ""
            ): ?>

                <section class="analytics-card mb-4">

                    <div class="section-heading mb-3">

                        <h5 class="fw-bold">

                            <i class="bi bi-bar-chart-line me-2 text-primary"></i>

                            Transaction Classification Summary

                        </h5>

                        <span class="result-count">
                            <?= number_format($totalRecords) ?> transactions
                        </span>

                    </div>


                    <div class="row g-3">

                        <?php

                        $classificationStyles = [
                            "Completed" => [
                                "icon" => "bi-check-circle",
                                "color" => "success"
                            ],
                            "Cancelled" => [
                                "icon" => "bi-x-circle",
                                "color" => "secondary"
                            ],
                            "Overdue" => [
                                "icon" => "bi-exclamation-circle",
                                "color" => "danger"
                            ],
                            "Active" => [
                                "icon" => "bi-clock",
                                "color" => "primary"
                            ]
                        ];

                        foreach ($classificationStyles as $name => $style):

                            $count = $classificationCounts[$name] ?? 0;

                        ?>

                            <div class="col-6 col-xl-3">

                                <div class="metric-card">

                                    <small>

                                        <i class="bi <?= e($style["icon"]) ?> me-1"></i>

                                        <?= e($name) ?>

                                    </small>

                                    <strong class="text-<?= e($style["color"]) ?>">
                                        <?= number_format($count) ?>
                                    </strong>

                                    <small class="mt-2">
                                        <?= $totalRecords > 0
                                            ? number_format(($count / $totalRecords) * 100, 1) . "%"
                                            : "0%" ?>
                                        of displayed transactions
                                    </small>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </section>

            <?php endif; ?>


            <!-- =================================================
                 CONSTRAINT SUMMARY
            ================================================== -->

            <?php if (
                $view === "constraints"
                && !empty($constraintCounts)
                && $errorMessage === ""
            ): ?>

                <section class="analytics-card mb-4">

                    <div class="section-heading mb-3">

                        <h5 class="fw-bold">

                            <i class="bi bi-shield-check me-2 text-primary"></i>

                            Constraint Distribution

                        </h5>

                        <span class="result-count">
                            <?= number_format($totalRecords) ?> definitions
                        </span>

                    </div>


                    <div class="row g-3">

                        <?php foreach ($constraintCounts as $type => $count): ?>

                            <div class="col-6 col-xl-3">

                                <div class="metric-card">

                                    <small>
                                        <?= e($type) ?>
                                    </small>

                                    <strong>
                                        <?= number_format($count) ?>
                                    </strong>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <p class="muted small mt-3 mb-0">

                        These counts are based on the definitions returned
                        by PostgreSQL's system catalogs for the selected
                        application tables.

                    </p>

                </section>

            <?php endif; ?>


            <!-- =================================================
                 SQL STATEMENT
            ================================================== -->

            <section class="table-card mb-4">

                <div class="sql-toolbar">

                    <div>

                        <h5 class="fw-bold mb-1">

                            <i class="bi bi-code-slash me-2 text-primary"></i>

                            SQL Statement

                        </h5>

                        <small class="muted">
                            PostgreSQL commands used in this module.
                        </small>

                    </div>


                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        id="copySql"
                    >

                        <i class="bi bi-clipboard me-1"></i>

                        Copy SQL

                    </button>

                </div>


                <pre class="sql-preview" id="sqlCode"><?= e(trim($sql)) ?></pre>

            </section>


            <!-- =================================================
                 DATABASE RESULTS
            ================================================== -->

            <section class="table-card mb-4">

                <div class="section-heading mb-3">

                    <div>

                        <h5 class="fw-bold mb-1">

                            <i class="bi bi-table me-2 text-primary"></i>

                            Database Results

                        </h5>

                        <small class="muted">
                            Records retrieved directly from PostgreSQL.
                        </small>

                    </div>


                    <?php if ($queryExecuted && $errorMessage === ""): ?>

                        <span class="result-count" id="resultCount">

                            <?= number_format($totalRecords) ?> records found

                        </span>

                    <?php endif; ?>

                </div>


                <?php if ($errorMessage !== ""): ?>

                    <div class="empty-state">

                        <i class="bi bi-exclamation-triangle d-block mb-3"></i>

                        <h6 class="fw-bold">
                            Results Unavailable
                        </h6>

                        <p class="small">
                            The selected operation could not be completed.
                        </p>

                    </div>


                <?php elseif (!$queryExecuted): ?>

                    <div class="empty-state">

                        <i class="bi bi-database d-block mb-3"></i>

                        <h6 class="fw-bold">
                            Awaiting Execution
                        </h6>

                        <p class="small">
                            Select a module to execute its corresponding SQL operation.
                        </p>

                    </div>


                <?php elseif ($view === "transactions"): ?>

                    <div class="empty-state">

                        <i class="bi bi-arrow-left-right d-block mb-3"></i>

                        <h6 class="fw-bold">
                            Transaction Demonstration Completed
                        </h6>

                        <p class="small">
                            View the COMMIT and ROLLBACK results in the
                            transaction execution report above.
                        </p>

                    </div>


                <?php elseif (empty($records)): ?>

                    <div class="empty-state">

                        <i class="bi bi-inbox d-block mb-3"></i>

                        <h6 class="fw-bold">
                            No Records Found
                        </h6>

                        <p class="small">
                            The query executed successfully but returned
                            no records from the database.
                        </p>

                    </div>


                <?php else: ?>


                    <div class="table-tools">

                        <input
                            type="search"
                            id="tableSearch"
                            class="form-control"
                            placeholder="Search within results..."
                        >


                        <div class="d-flex flex-wrap gap-2">

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                id="exportCsv"
                            >

                                <i class="bi bi-filetype-csv me-1"></i>

                                Export CSV

                            </button>


                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                id="printResults"
                            >

                                <i class="bi bi-printer me-1"></i>

                                Print Results

                            </button>

                        </div>

                    </div>


                    <div class="table-responsive">

                        <table
                            class="table table-hover align-middle"
                            id="resultsTable"
                        >

                            <thead>

                                <tr>

                                    <?php foreach ($columns as $column): ?>

                                        <th title="Click to sort">

                                            <?= e(str_replace("_", " ", $column)) ?>

                                            <i class="bi bi-arrow-down-up ms-1"></i>

                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach ($records as $record): ?>

                                    <tr>

                                        <?php foreach ($columns as $column): ?>

                                            <td>

                                                <?php if ($record[$column] === null): ?>

                                                    <span class="text-muted fst-italic">
                                                        NULL
                                                    </span>


                                                <?php elseif ($column === "transaction_classification"): ?>

                                                    <?php

                                                        $classification = $record[$column];

                                                        $classStyle = match ($classification) {

                                                            "Completed" =>
                                                                "bg-success-subtle text-success",

                                                            "Cancelled" =>
                                                                "bg-secondary-subtle text-secondary",

                                                            "Overdue" =>
                                                                "bg-danger-subtle text-danger",

                                                            "Active" =>
                                                                "bg-primary-subtle text-primary",

                                                            default =>
                                                                "bg-light text-dark"

                                                        };

                                                    ?>

                                                    <span class="classification-badge <?= e($classStyle) ?>">
                                                        <?= e($classification) ?>
                                                    </span>


                                                <?php elseif ($column === "borrowing_reminder"): ?>

                                                    <?php

                                                        $reminder = $record[$column];

                                                        $reminderStyle = match ($reminder) {

                                                            "No Action Required" =>
                                                                "bg-success-subtle text-success",

                                                            "Return Immediately" =>
                                                                "bg-danger-subtle text-danger",

                                                            "Within Due Date" =>
                                                                "bg-primary-subtle text-primary",

                                                            default =>
                                                                "bg-light text-dark"

                                                        };

                                                    ?>

                                                    <span class="classification-badge <?= e($reminderStyle) ?>">
                                                        <?= e($reminder) ?>
                                                    </span>


                                                <?php elseif ($column === "constraint_type"): ?>

                                                    <span class="constraint-badge">
                                                        <?= e($record[$column]) ?>
                                                    </span>


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


                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">

                        <small class="muted" id="visibleRows">

                            Showing <?= number_format($totalRecords) ?> records

                        </small>

                        <small class="muted">
                            Click a column heading to sort.
                        </small>

                    </div>

                <?php endif; ?>

            </section>


            <!-- =================================================
                 IMPORTANT MODULE NOTES
            ================================================== -->

            <section class="table-card mb-4">

                <h5 class="fw-bold mb-3">

                    <i class="bi bi-info-circle me-2 text-primary"></i>

                    Module Notes

                </h5>


                <?php if ($view === "views"): ?>

                    <div class="info-note">

                        <strong>Temporary Database View</strong>

                        <p class="mb-0 mt-2">

                            The system creates a temporary view named
                            <code>week08_borrowing_summary</code>.
                            It exists only for the current PostgreSQL
                            database session. It does not permanently
                            add a view to the public schema.

                        </p>

                    </div>


                    <div class="success-note mt-3">

                        <strong>Summary Accuracy</strong>

                        <p class="mb-0 mt-2">

                            COUNT(DISTINCT bt.borrow_id) prevents a
                            transaction from being counted multiple times
                            when it contains several borrowed item records.
                            The item quantity total is calculated from
                            the related borrow_items records.

                        </p>

                    </div>


                <?php elseif ($view === "case"): ?>

                    <div class="info-note">

                        <strong>Condition Order Matters</strong>

                        <p class="mb-0 mt-2">

                            CASE evaluates conditions from top to bottom.
                            Completed and Cancelled statuses are checked
                            before the due date. Therefore, a completed
                            transaction will not be classified as overdue
                            even if its due date has already passed.

                        </p>

                    </div>


                <?php elseif ($view === "transactions"): ?>

                    <div class="success-note">

                        <strong>Transaction Isolation</strong>

                        <p class="mb-0 mt-2">

                            The demonstration uses a temporary table and
                            does not insert, update, or delete permanent
                            application records. COMMIT preserves the
                            test record within the temporary table;
                            ROLLBACK removes the second test record.

                        </p>

                    </div>


                <?php elseif ($view === "constraints"): ?>

                    <div class="info-note">

                        <strong>Actual Database Definitions</strong>

                        <p class="mb-0 mt-2">

                            The system reads pg_constraint and
                            pg_attribute. It does not assume that a
                            particular constraint exists. Only actual
                            constraint definitions and NOT NULL
                            attributes found in the selected tables
                            are displayed.

                        </p>

                    </div>

                <?php endif; ?>

            </section>


            <!-- =================================================
                 FOOTER
            ================================================== -->

            <footer class="footer">

                <span>
                    Database Administration Practical System
                </span>

                <span>
                    Week 8 · Database Operations
                </span>

            </footer>

        </div>

    </main>

</div>


<!-- =============================================================
     JAVASCRIPT
============================================================== -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>


<script>

/*
|--------------------------------------------------------------------------
| COPY SQL STATEMENT
|--------------------------------------------------------------------------
*/

document.getElementById("copySql")?.addEventListener("click", async function () {

    const sql = document.getElementById("sqlCode").innerText;

    try {

        await navigator.clipboard.writeText(sql);

        this.innerHTML =
            '<i class="bi bi-check-circle me-1"></i> Copied!';

        setTimeout(() => {

            this.innerHTML =
                '<i class="bi bi-clipboard me-1"></i> Copy SQL';

        }, 2000);

    } catch (error) {

        alert("Unable to copy SQL. Please copy it manually.");

    }

});


/*
|--------------------------------------------------------------------------
| SEARCH TABLE RESULTS
|--------------------------------------------------------------------------
*/

const tableSearch = document.getElementById("tableSearch");

const resultsTable = document.getElementById("resultsTable");

const resultCount = document.getElementById("resultCount");

const visibleRows = document.getElementById("visibleRows");

if (tableSearch && resultsTable) {

    tableSearch.addEventListener("input", function () {

        const keyword = this.value.toLowerCase();

        const rows = resultsTable.querySelectorAll("tbody tr");

        let visible = 0;

        rows.forEach(row => {

            const matches = row.innerText.toLowerCase().includes(keyword);

            row.style.display = matches ? "" : "none";

            if (matches) {
                visible++;
            }

        });

        if (visibleRows) {

            visibleRows.textContent =
                `Showing ${visible.toLocaleString()} of ${rows.length.toLocaleString()} records`;

        }

        if (resultCount) {

            resultCount.textContent =
                `${visible.toLocaleString()} visible records`;

        }

    });

}


/*
|--------------------------------------------------------------------------
| SORT TABLE COLUMNS
|--------------------------------------------------------------------------
*/

if (resultsTable) {

    const headers = resultsTable.querySelectorAll("thead th");

    headers.forEach((header, index) => {

        let ascending = true;

        header.addEventListener("click", function () {

            const tbody = resultsTable.querySelector("tbody");

            const rows = Array.from(tbody.querySelectorAll("tr"));

            rows.sort((a, b) => {

                const valueA = a.children[index].innerText.trim();

                const valueB = b.children[index].innerText.trim();

                const numberA = Number(valueA);

                const numberB = Number(valueB);

                let comparison;

                if (
                    valueA !== "" &&
                    valueB !== "" &&
                    !isNaN(numberA) &&
                    !isNaN(numberB)
                ) {

                    comparison = numberA - numberB;

                } else {

                    comparison = valueA.localeCompare(
                        valueB,
                        undefined,
                        {
                            numeric: true,
                            sensitivity: "base"
                        }
                    );

                }

                return ascending ? comparison : -comparison;

            });

            rows.forEach(row => tbody.appendChild(row));

            ascending = !ascending;

        });

    });

}


/*
|--------------------------------------------------------------------------
| EXPORT TABLE TO CSV
|--------------------------------------------------------------------------
*/

document.getElementById("exportCsv")?.addEventListener("click", function () {

    const table = document.getElementById("resultsTable");

    if (!table) return;

    const rows = [];

    table.querySelectorAll("tr").forEach(row => {

        const cells = Array.from(row.querySelectorAll("th, td"));

        const values = cells.map(cell => {

            let value = cell.innerText.trim();

            // Prevent spreadsheet formula execution.
            if (/^[=+\-@\t\r]/.test(value)) {
                value = "'" + value;
            }

            value = value.replace(/"/g, '""');

            return `"${value}"`;

        });

        rows.push(values.join(","));

    });

    const csv = "\uFEFF" + rows.join("\r\n");

    const blob = new Blob([csv], {
        type: "text/csv;charset=utf-8;"
    });

    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;

    link.download = "week8_database_results.csv";

    document.body.appendChild(link);

    link.click();

    link.remove();

    URL.revokeObjectURL(url);

});


/*
|--------------------------------------------------------------------------
| PRINT RESULTS
|--------------------------------------------------------------------------
*/

document.getElementById("printResults")?.addEventListener("click", function () {

    const table = document.getElementById("resultsTable");

    if (!table) return;

    const printWindow = window.open("", "_blank");

    if (!printWindow) {

        alert("Please allow pop-ups to print the results.");

        return;

    }

    const title = <?= json_encode(
        $pageTitle,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const tableCopy = table.cloneNode(true);

    // Remove hidden rows so the printed report matches the current search.
    tableCopy.querySelectorAll("tbody tr").forEach(row => {

        if (row.style.display === "none") {
            row.remove();
        }

    });

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>

            <title>Week 8 - ${title}</title>

            <style>

                body {
                    font-family: Arial, sans-serif;
                    padding: 25px;
                    color: #26344d;
                }

                h2 {
                    margin-bottom: 5px;
                }

                p {
                    color: #64748b;
                    font-size: 12px;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                    font-size: 10px;
                }

                th, td {
                    border: 1px solid #ddd;
                    padding: 9px;
                    text-align: left;
                }

                th {
                    background: #edf3ff;
                    text-transform: uppercase;
                }

                @media print {
                    body {
                        padding: 0;
                    }
                }

            </style>

        </head>

        <body>

            <h2>Database Administration Practical System</h2>

            <p>Week 8 | ${title}</p>

            ${tableCopy.outerHTML}

        </body>

        </html>
    `);

    printWindow.document.close();

    printWindow.onload = function () {

        printWindow.print();

    };

});

</script>

</body>

</html>
