<?php
session_start();
require_once "../includes/db.php";

function e($value): string
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/*
|--------------------------------------------------------------------------
| WEEK 7 SQL QUERY LIBRARY
|--------------------------------------------------------------------------
*/

$operations = [

    // ================================================================
    // 1. JOIN OPERATIONS
    // ================================================================

    "join_inner" => [
        "group" => "JOIN Operations",
        "label" => "INNER JOIN",
        "icon" => "bi-intersect",
        "description" => "Displays borrowers who have matching borrowing transactions.",
        "purpose" => "Retrieve records that have matching values in both related tables.",
        "syntax" => "SELECT columns FROM table1 INNER JOIN table2 ON table1.common_id = table2.common_id;",
        "explanation" => "The INNER JOIN combines borrowers and borrowing transactions using borrower_id. Only borrowers with at least one matching transaction are included. Borrowers without transactions are excluded.",
        "expected" => "A list of borrowers together with their corresponding borrowing transaction information.",
        "sql" => "
SELECT
    b.borrower_id,
    b.borrower_no,
    b.full_name,
    b.borrower_type,
    bt.borrow_id,
    bt.borrow_date,
    bt.due_date,
    bt.status AS transaction_status
FROM public.borrowers AS b
INNER JOIN public.borrow_transactions AS bt
    ON b.borrower_id = bt.borrower_id
ORDER BY b.full_name, bt.borrow_id DESC
LIMIT 500;
"
    ],

    "join_left" => [
        "group" => "JOIN Operations",
        "label" => "LEFT JOIN",
        "icon" => "bi-arrow-left-right",
        "description" => "Displays all borrowers, including those without borrowing transactions.",
        "purpose" => "Preserve every record from the left table, whether or not a matching record exists in the right table.",
        "syntax" => "SELECT columns FROM table1 LEFT JOIN table2 ON table1.common_id = table2.common_id;",
        "explanation" => "The LEFT JOIN returns all borrowers. When a borrower has no matching transaction, the transaction-related columns contain NULL.",
        "expected" => "All registered borrowers, including borrowers who have never borrowed equipment.",
        "sql" => "
SELECT
    b.borrower_id,
    b.borrower_no,
    b.full_name,
    b.borrower_type,
    bt.borrow_id,
    bt.borrow_date,
    bt.due_date,
    bt.status AS transaction_status
FROM public.borrowers AS b
LEFT JOIN public.borrow_transactions AS bt
    ON b.borrower_id = bt.borrower_id
ORDER BY b.full_name, bt.borrow_id DESC NULLS LAST
LIMIT 500;
"
    ],

    "join_right" => [
        "group" => "JOIN Operations",
        "label" => "RIGHT JOIN",
        "icon" => "bi-arrow-right-left",
        "description" => "Displays all transactions and their matching borrower information.",
        "purpose" => "Preserve every record from the right table, even when there is no matching record in the left table.",
        "syntax" => "SELECT columns FROM table1 RIGHT JOIN table2 ON table1.common_id = table2.common_id;",
        "explanation" => "The RIGHT JOIN preserves all borrowing transactions. Any transaction without a matching borrower would display NULL borrower information.",
        "expected" => "All borrowing transactions and their corresponding borrower details.",
        "sql" => "
SELECT
    b.borrower_id,
    b.borrower_no,
    b.full_name,
    b.borrower_type,
    bt.borrow_id,
    bt.borrow_date,
    bt.due_date,
    bt.status AS transaction_status
FROM public.borrowers AS b
RIGHT JOIN public.borrow_transactions AS bt
    ON b.borrower_id = bt.borrower_id
ORDER BY bt.borrow_id DESC
LIMIT 500;
"
    ],

    "join_full" => [
        "group" => "JOIN Operations",
        "label" => "FULL OUTER JOIN",
        "icon" => "bi-arrows-fullscreen",
        "description" => "Displays all borrowers and transactions, including unmatched records.",
        "purpose" => "Preserve records from both tables, regardless of whether matching records exist.",
        "syntax" => "SELECT columns FROM table1 FULL OUTER JOIN table2 ON table1.common_id = table2.common_id;",
        "explanation" => "The FULL OUTER JOIN combines all matching records and retains unmatched records from both tables. Missing related information is represented by NULL.",
        "expected" => "A complete combined list of borrowers and transactions.",
        "sql" => "
SELECT
    b.borrower_id,
    b.borrower_no,
    b.full_name,
    b.borrower_type,
    bt.borrow_id,
    bt.borrow_date,
    bt.due_date,
    bt.status AS transaction_status
FROM public.borrowers AS b
FULL OUTER JOIN public.borrow_transactions AS bt
    ON b.borrower_id = bt.borrower_id
ORDER BY b.full_name NULLS LAST, bt.borrow_id DESC NULLS LAST
LIMIT 500;
"
    ],

    // ================================================================
    // 2. AGGREGATION
    // ================================================================

    "agg_borrowers" => [
        "group" => "Aggregation",
        "label" => "Borrowers by Type",
        "icon" => "bi-people",
        "description" => "Counts registered borrowers according to their borrower category.",
        "purpose" => "Summarize the number of borrowers belonging to each category.",
        "syntax" => "SELECT category, COUNT(*) FROM table_name GROUP BY category;",
        "explanation" => "GROUP BY creates separate groups based on borrower_type. COUNT calculates the number of borrower records in each group.",
        "expected" => "The number of Student, Faculty, and Staff borrowers, depending on the actual database records.",
        "sql" => "
SELECT
    borrower_type,
    COUNT(*) AS total_borrowers
FROM public.borrowers
GROUP BY borrower_type
ORDER BY borrower_type;
"
    ],

    "agg_equipment" => [
        "group" => "Aggregation",
        "label" => "Equipment Inventory Summary",
        "icon" => "bi-box-seam",
        "description" => "Summarizes available equipment quantities using aggregate functions.",
        "purpose" => "Calculate inventory statistics without retrieving every individual equipment record.",
        "syntax" => "SELECT COUNT(*), SUM(column), AVG(column), MIN(column), MAX(column) FROM table_name;",
        "explanation" => "COUNT determines the number of equipment records. SUM calculates the total available quantity. AVG calculates the average. MIN and MAX identify the lowest and highest quantities.",
        "expected" => "A single summary row containing five inventory measurements.",
        "sql" => "
SELECT
    COUNT(*) AS total_equipment_records,
    COALESCE(SUM(quantity_available), 0) AS total_available_quantity,
    COALESCE(ROUND(AVG(quantity_available), 2), 0) AS average_quantity,
    MIN(quantity_available) AS minimum_quantity,
    MAX(quantity_available) AS maximum_quantity
FROM public.equipment;
"
    ],

    "agg_transactions" => [
        "group" => "Aggregation",
        "label" => "Transactions by Status",
        "icon" => "bi-clipboard-data",
        "description" => "Counts borrowing transactions according to their current status.",
        "purpose" => "Determine how borrowing transactions are distributed across different statuses.",
        "syntax" => "SELECT status, COUNT(*) FROM table_name GROUP BY status;",
        "explanation" => "GROUP BY separates transactions according to status. COUNT calculates the number of transactions belonging to each status.",
        "expected" => "A summary of Borrowed, Returned, Completed, Cancelled, or other statuses present in the database.",
        "sql" => "
SELECT
    status,
    COUNT(*) AS total_transactions
FROM public.borrow_transactions
GROUP BY status
ORDER BY status;
"
    ],

    "agg_items" => [
        "group" => "Aggregation",
        "label" => "Borrowed Items Summary",
        "icon" => "bi-bag-check",
        "description" => "Calculates the total and statistical distribution of borrowed equipment quantities.",
        "purpose" => "Measure the quantity of items recorded in borrowing transactions.",
        "syntax" => "SELECT COUNT(*), SUM(column), AVG(column), MIN(column), MAX(column) FROM table_name;",
        "explanation" => "COUNT measures the number of borrowed item records. SUM calculates the total quantity. AVG, MIN, and MAX describe the distribution of recorded quantities.",
        "expected" => "A single summary row containing five measurements of borrowed item records.",
        "sql" => "
SELECT
    COUNT(*) AS total_borrow_item_records,
    COALESCE(SUM(quantity), 0) AS total_items_borrowed,
    COALESCE(ROUND(AVG(quantity), 2), 0) AS average_item_quantity,
    MIN(quantity) AS minimum_item_quantity,
    MAX(quantity) AS maximum_item_quantity
FROM public.borrow_items;
"
    ],

    // ================================================================
    // 3. SET OPERATIONS
    // ================================================================

    "set_union" => [
        "group" => "Set Operations",
        "label" => "UNION",
        "icon" => "bi-union",
        "description" => "Combines borrower IDs from two queries and removes duplicate values.",
        "purpose" => "Combine compatible result sets while retaining only distinct records.",
        "syntax" => "SELECT column FROM table1 UNION SELECT column FROM table2;",
        "explanation" => "UNION combines the borrower IDs from the borrowers table and the borrow_transactions table. Duplicate borrower IDs are automatically eliminated.",
        "expected" => "A distinct list of borrower IDs appearing in either query. With a valid foreign key, these should be the registered borrower IDs.",
        "sql" => "
SELECT borrower_id AS related_borrower_id
FROM public.borrowers

UNION

SELECT borrower_id AS related_borrower_id
FROM public.borrow_transactions

ORDER BY related_borrower_id
LIMIT 500;
"
    ],

    "set_intersect" => [
        "group" => "Set Operations",
        "label" => "INTERSECT",
        "icon" => "bi-intersect",
        "description" => "Returns borrower IDs that exist in both query results.",
        "purpose" => "Identify common records between two compatible result sets.",
        "syntax" => "SELECT column FROM table1 INTERSECT SELECT column FROM table2;",
        "explanation" => "INTERSECT compares the borrower IDs returned by both SELECT statements and keeps only the values present in both result sets.",
        "expected" => "Borrower IDs belonging to registered borrowers who have at least one borrowing transaction.",
        "sql" => "
SELECT borrower_id AS related_borrower_id
FROM public.borrowers

INTERSECT

SELECT borrower_id AS related_borrower_id
FROM public.borrow_transactions

ORDER BY related_borrower_id
LIMIT 500;
"
    ],

    "set_except" => [
        "group" => "Set Operations",
        "label" => "EXCEPT",
        "icon" => "bi-dash-circle",
        "description" => "Identifies borrowers who do not have any matching borrowing transactions.",
        "purpose" => "Find records in the first result set that do not appear in the second result set.",
        "syntax" => "SELECT column FROM table1 EXCEPT SELECT column FROM table2;",
        "explanation" => "EXCEPT returns borrower IDs found in the borrowers table but absent from the transaction table.",
        "expected" => "A list of registered borrowers who have never borrowed equipment.",
        "sql" => "
SELECT borrower_id AS related_borrower_id
FROM public.borrowers

EXCEPT

SELECT borrower_id AS related_borrower_id
FROM public.borrow_transactions

ORDER BY related_borrower_id
LIMIT 500;
"
    ],

    // ================================================================
    // 4. SQL FUNCTIONS
    // ================================================================

    "func_string" => [
        "group" => "SQL Functions",
        "label" => "String Functions",
        "icon" => "bi-fonts",
        "description" => "Demonstrates UPPER, LOWER, LENGTH, and CONCAT using borrower information.",
        "purpose" => "Manipulate and transform text values directly within SQL queries.",
        "syntax" => "UPPER(text), LOWER(text), LENGTH(text), CONCAT(value1, value2)",
        "explanation" => "UPPER converts names to uppercase. LOWER converts names to lowercase. LENGTH counts characters. CONCAT combines the borrower number and full name into one text value.",
        "expected" => "Borrower information displayed in its original form and in different calculated text formats.",
        "sql" => "
SELECT
    borrower_id,
    borrower_no,
    full_name,
    UPPER(full_name) AS uppercase_name,
    LOWER(full_name) AS lowercase_name,
    LENGTH(full_name) AS name_length,
    CONCAT(borrower_no, ' - ', full_name) AS borrower_information
FROM public.borrowers
ORDER BY full_name
LIMIT 500;
"
    ],

    "func_numeric" => [
        "group" => "SQL Functions",
        "label" => "Numeric Functions",
        "icon" => "bi-calculator",
        "description" => "Demonstrates ABS, ROUND, CEIL, and FLOOR using equipment quantities.",
        "purpose" => "Perform mathematical calculations and manipulate numeric values.",
        "syntax" => "ABS(number), ROUND(number, decimals), CEIL(number), FLOOR(number)",
        "explanation" => "ABS returns the absolute value. ROUND adjusts a number to the specified decimal places. CEIL rounds upward to the next integer, while FLOOR rounds downward.",
        "expected" => "Equipment quantities displayed alongside calculated numeric values.",
        "sql" => "
SELECT
    equipment_id,
    property_no,
    equipment_name,
    quantity_available,
    ABS(quantity_available) AS absolute_quantity,
    ROUND(quantity_available::NUMERIC / 2, 2) AS rounded_half_quantity,
    CEIL(quantity_available::NUMERIC / 2) AS ceiling_value,
    FLOOR(quantity_available::NUMERIC / 2) AS floor_value
FROM public.equipment
ORDER BY equipment_name
LIMIT 500;
"
    ],

    "func_date" => [
        "group" => "SQL Functions",
        "label" => "Date Functions",
        "icon" => "bi-calendar-event",
        "description" => "Demonstrates CURRENT_DATE, AGE, EXTRACT, and date subtraction.",
        "purpose" => "Retrieve, calculate, and analyze date-related information in PostgreSQL.",
        "syntax" => "CURRENT_DATE, AGE(date1, date2), EXTRACT(YEAR FROM date), date1 - date2",
        "explanation" => "CURRENT_DATE returns the current database date. AGE calculates the interval between two dates. EXTRACT retrieves a specific date component. Date subtraction returns the number of days between two DATE values.",
        "expected" => "Borrowing dates together with calculated borrowing periods, years, and allowed days.",
        "sql" => "
SELECT
    borrow_id,
    borrow_date,
    due_date,
    CURRENT_DATE AS current_date,
    AGE(due_date, borrow_date) AS borrowing_period,
    EXTRACT(YEAR FROM borrow_date) AS borrow_year,
    (due_date - borrow_date) AS allowed_days
FROM public.borrow_transactions
ORDER BY borrow_id DESC
LIMIT 500;
"
    ]

];

/*
|--------------------------------------------------------------------------
| VALIDATE SELECTED OPERATION
|--------------------------------------------------------------------------
*/

$selectedOperation = $_GET["operation"] ?? "join_inner";

if (!isset($operations[$selectedOperation])) {
    $selectedOperation = "join_inner";
}

$currentOperation = $operations[$selectedOperation];
$sql = trim($currentOperation["sql"]);

/*
|--------------------------------------------------------------------------
| QUERY EXECUTION VARIABLES
|--------------------------------------------------------------------------
*/

$results = [];
$columns = [];

$errorMessage = "";

$queryExecuted = isset($_GET["run"]);

$totalResults = 0;
$totalColumns = 0;

$executionTime = 0;
$executionTimeLabel = "Not executed";

$queryStatus = "Awaiting Execution";

$chartLabels = [];
$chartValues = [];
$chartTitle = "";

$numericColumns = [];

$startedAt = 0;

/*
|--------------------------------------------------------------------------
| EXECUTE QUERY
|--------------------------------------------------------------------------
*/

if ($queryExecuted) {

    try {

        $startedAt = hrtime(true);

        $stmt = $pdo->query($sql);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $executionTime = (hrtime(true) - $startedAt) / 1000000;

        $executionTimeLabel = number_format($executionTime, 3) . " ms";

        $totalResults = count($results);

        if (!empty($results)) {

            $columns = array_keys($results[0]);

            $totalColumns = count($columns);

        }

        $queryStatus = "Successfully Executed";

        /*
        |--------------------------------------------------------------------------
        | PREPARE CHART DATA FOR AGGREGATION RESULTS
        |--------------------------------------------------------------------------
        */

        if (
            $currentOperation["group"] === "Aggregation"
            && count($results) >= 2
        ) {

            $firstColumn = $columns[0];

            $numericColumn = null;

            foreach ($columns as $column) {

                if (
                    isset($results[0][$column])
                    && is_numeric($results[0][$column])
                ) {

                    if ($column !== $firstColumn) {

                        $numericColumn = $column;
                        break;

                    }

                }

            }

            if ($numericColumn !== null) {

                $chartTitle = str_replace("_", " ", $numericColumn);

                foreach ($results as $row) {

                    $chartLabels[] = (string)$row[$firstColumn];

                    $chartValues[] = (float)$row[$numericColumn];

                }

            }

        }

    } catch (PDOException $exception) {

        error_log("Week 7 SQL error: " . $exception->getMessage());

        $errorMessage = "The selected query could not be executed. Please check your database connection, table structure, and permissions.";

        $queryStatus = "Execution Failed";

        $executionTime = (hrtime(true) - $startedAt) / 1000000;

        $executionTimeLabel = number_format($executionTime, 3) . " ms";

    }

}

/*
|--------------------------------------------------------------------------
| QUERY CATEGORY COUNTS
|--------------------------------------------------------------------------
*/

$categoryCounts = [];

foreach ($operations as $operation) {

    $group = $operation["group"];

    if (!isset($categoryCounts[$group])) {
        $categoryCounts[$group] = 0;
    }

    $categoryCounts[$group]++;

}

$totalOperations = count($operations);

$groupIcons = [
    "JOIN Operations" => "bi-intersect",
    "Aggregation" => "bi-bar-chart-line",
    "Set Operations" => "bi-diagram-3",
    "SQL Functions" => "bi-calculator"
];

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Week 7 SQL Laboratory | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

/* ================================================================
   GENERAL
================================================================ */

        .operation-card,
        .query-card,
        .result-card,
        .guide-card,
        .analytics-card,
        .explanation-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 23px;
            margin-bottom: 20px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-heading h5 {
            margin: 0;
            font-size: 16px;
        }

        .section-heading span {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .muted-text {
            color: var(--muted);
        }

/* ================================================================
   INTRODUCTION
================================================================ */

        .week-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #edf3ff;
            color: #315cc5;
            padding: 8px 13px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 800;
        }

        .intro-text {
            max-width: 720px;
            font-size: 13px;
            line-height: 1.8;
            color: var(--muted);
        }

/* ================================================================
   STATISTICS
================================================================ */

        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 19px;
            height: 100%;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 11px;
            font-size: 18px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 800;
            margin-top: 12px;
            color: #25334d;
        }

        .stat-label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .4px;
            text-transform: uppercase;
        }

/* ================================================================
   OPERATION SELECTION
================================================================ */

        .operation-description {
            background: #f4f7ff;
            border: 1px solid #e5ecff;
            border-radius: 11px;
            padding: 17px;
            margin-top: 18px;
        }

        .operation-description h6 {
            color: #24468f;
            font-weight: 800;
        }

        .operation-description p {
            color: #586782;
            font-size: 12px;
            line-height: 1.7;
            margin-bottom: 0;
        }

        .operation-label {
            font-size: 10px;
            font-weight: 800;
            color: #52617b;
            margin-bottom: 8px;
            letter-spacing: .4px;
        }

/* ================================================================
   CATEGORY CARDS
================================================================ */

        .category-card {
            border: 1px solid var(--border);
            border-radius: 13px;
            background: white;
            padding: 17px;
            height: 100%;
        }

        .category-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #edf3ff;
            color: #315cc5;
            font-size: 17px;
            margin-bottom: 12px;
        }

        .category-card h6 {
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .category-card p {
            color: var(--muted);
            font-size: 11px;
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
            font-size: 12px;
            line-height: 1.9;
            white-space: pre;
            margin: 0;
            max-height: 430px;
        }

        .sql-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 13px;
        }

/* ================================================================
   EXECUTION STATUS
================================================================ */

        .execution-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 800;
            background: #edf1f6;
            color: #64748b;
        }

        .execution-status.success {
            background: #e6f7ed;
            color: #16834c;
        }

        .execution-status.failed {
            background: #fff0f0;
            color: #c33c3c;
        }

        .metric-card {
            background: #f7f9fc;
            border: 1px solid #edf0f5;
            padding: 15px;
            border-radius: 11px;
            height: 100%;
        }

        .metric-card small {
            display: block;
            color: #7b879c;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .metric-card strong {
            display: block;
            color: #283a58;
            font-size: 20px;
            font-weight: 800;
            margin-top: 7px;
        }

/* ================================================================
   RESULTS TABLE
================================================================ */

        .result-count {
            background: #edf3ff;
            color: #315cc5;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
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

        .null-value {
            color: #a0a9b8;
            font-style: italic;
        }

        .copy-cell {
            border: none;
            background: transparent;
            color: #315cc5;
            font-size: 10px;
            margin-left: 5px;
            padding: 2px 4px;
            opacity: .35;
            cursor: pointer;
        }

        .copy-cell:hover {
            opacity: 1;
        }

        .table-tools {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }

        .table-tools input {
            max-width: 280px;
            font-size: 12px;
        }

/* ================================================================
   EMPTY STATE
================================================================ */

        .empty-state {
            text-align: center;
            padding: 45px 15px;
            color: #8793a8;
        }

        .empty-state i {
            font-size: 38px;
        }

/* ================================================================
   EXPLANATION
================================================================ */

        .explanation-block {
            padding: 16px;
            background: #f7f9fc;
            border: 1px solid #edf0f5;
            border-radius: 11px;
            height: 100%;
        }

        .explanation-block .label {
            color: #7b879c;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .explanation-block p {
            font-size: 12px;
            color: #43516b;
            line-height: 1.8;
            margin-bottom: 0;
        }

        .syntax-box {
            background: #101d3a;
            color: #d8e5ff;
            padding: 13px;
            border-radius: 9px;
            font-size: 11px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

/* ================================================================
   CHART
================================================================ */

        .chart-container {
            height: 280px;
            position: relative;
        }

/* ================================================================
   GUIDE
================================================================ */

        .guide-table {
            font-size: 11px;
        }

        .guide-table th {
            background: #f3f6fb;
            color: #68778f;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: .4px;
        }

        .guide-table td,
        .guide-table th {
            padding: 12px;
            vertical-align: middle;
        }

        .exam-question {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 16px;
            margin-bottom: 10px;
        }

        .exam-question h6 {
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .exam-question p {
            font-size: 11px;
            color: #52617b;
            line-height: 1.8;
            margin: 0;
        }

/* ================================================================
   NOTES
================================================================ */

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

            .operation-card,
            .query-card,
            .result-card,
            .guide-card,
            .analytics-card,
            .explanation-card {
                padding: 16px;
            }

            .stat-number {
                font-size: 21px;
            }

            .table-tools {
                align-items: stretch;
                flex-direction: column;
            }

            .table-tools input {
                max-width: 100%;
                width: 100%;
            }

            .chart-container {
                height: 230px;
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
                    PART 3 / WEEK 7
                </div>

                <h4>SQL Operations</h4>

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
                        WEEK 7 · DATABASE ADMINISTRATION
                    </div>

                    <h2>SQL Operations Laboratory</h2>

                    <p class="intro-text mb-0">
                        Explore and execute advanced PostgreSQL operations
                        using the Equipment Borrowing Management Database.
                        This laboratory demonstrates table relationships,
                        aggregate calculations, set operations, and built-in
                        SQL functions through actual database records.
                    </p>

                </div>

            </section>

            <!-- =================================================
                 OVERVIEW STATISTICS
            ================================================== -->

            <div class="row g-3 mb-4">

                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-primary-subtle text-primary">
                            <i class="bi bi-code-square"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format($totalOperations) ?>
                        </div>

                        <div class="stat-label">
                            Available SQL Queries
                        </div>

                    </div>

                </div>

                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-info-subtle text-info">
                            <i class="bi bi-diagram-3"></i>
                        </div>

                        <div class="stat-number">
                            <?= count($categoryCounts) ?>
                        </div>

                        <div class="stat-label">
                            SQL Categories
                        </div>

                    </div>

                </div>

                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-success-subtle text-success">
                            <i class="bi bi-table"></i>
                        </div>

                        <div class="stat-number">
                            4
                        </div>

                        <div class="stat-label">
                            Main Database Tables
                        </div>

                    </div>

                </div>

                <div class="col-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-icon bg-warning-subtle text-warning">
                            <i class="bi bi-speedometer2"></i>
                        </div>

                        <div class="stat-number" style="font-size:18px;">
                            <?= $queryExecuted ? e($executionTimeLabel) : "—" ?>
                        </div>

                        <div class="stat-label">
                            Last Query Execution
                        </div>

                    </div>

                </div>

            </div>

            <!-- =================================================
                 SQL CATEGORY OVERVIEW
            ================================================== -->

            <div class="row g-3 mb-4">

                <?php foreach ($categoryCounts as $group => $count): ?>

                    <div class="col-6 col-xl-3">

                        <div class="category-card">

                            <div class="category-icon">
                                <i class="bi <?= e($groupIcons[$group]) ?>"></i>
                            </div>

                            <h6><?= e($group) ?></h6>

                            <p>
                                <?= number_format($count) ?> demonstration queries
                            </p>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

            <!-- =================================================
                 SELECT OPERATION
            ================================================== -->

            <section class="operation-card">

                <div class="section-heading mb-4">

                    <h5 class="fw-bold">
                        <i class="bi bi-sliders me-2 text-primary"></i>
                        SQL Query Laboratory
                    </h5>

                    <span>Interactive Query Selection</span>

                </div>

                <form method="GET">

                    <div class="row g-3 align-items-end">

                        <div class="col-lg-8">

                            <label class="operation-label" for="operation">
                                SELECT SQL OPERATION
                            </label>

                            <select
                                name="operation"
                                id="operation"
                                class="form-select"
                            >

                                <?php
                                $currentGroup = "";

                                foreach ($operations as $key => $operation):

                                    if ($currentGroup !== $operation["group"]):

                                        if ($currentGroup !== "") {
                                            echo "</optgroup>";
                                        }

                                        $currentGroup = $operation["group"];
                                ?>

                                    <optgroup label="<?= e($currentGroup) ?>">

                                <?php endif; ?>

                                    <option
                                        value="<?= e($key) ?>"
                                        <?= $selectedOperation === $key ? "selected" : "" ?>
                                    >
                                        <?= e($operation["label"]) ?>
                                    </option>

                                <?php endforeach; ?>

                                </optgroup>

                            </select>

                        </div>

                        <div class="col-lg-4">

                            <button
                                type="submit"
                                name="run"
                                value="1"
                                class="btn btn-primary w-100"
                            >

                                <i class="bi bi-play-fill me-2"></i>
                                Execute SQL Query

                            </button>

                        </div>

                    </div>

                </form>

                <div class="operation-description">

                    <h6 class="mb-2">

                        <i class="bi <?= e($currentOperation["icon"]) ?> me-2"></i>

                        <?= e($currentOperation["label"]) ?>

                    </h6>

                    <p>
                        <?= e($currentOperation["description"]) ?>
                    </p>

                </div>

            </section>

            <!-- =================================================
                 QUERY EXPLANATION
            ================================================== -->

            <section class="explanation-card">

                <div class="section-heading mb-3">

                    <h5 class="fw-bold">
                        <i class="bi bi-lightbulb me-2 text-primary"></i>
                        Understanding This Query
                    </h5>

                    <span>SQL Learning Guide</span>

                </div>

                <div class="row g-3">

                    <div class="col-lg-6">

                        <div class="explanation-block">

                            <div class="label">
                                Purpose
                            </div>

                            <p>
                                <?= e($currentOperation["purpose"]) ?>
                            </p>

                        </div>

                    </div>

                    <div class="col-lg-6">

                        <div class="explanation-block">

                            <div class="label">
                                Query Explanation
                            </div>

                            <p>
                                <?= e($currentOperation["explanation"]) ?>
                            </p>

                        </div>

                    </div>

                    <div class="col-lg-6">

                        <div class="explanation-block">

                            <div class="label">
                                Expected Output
                            </div>

                            <p>
                                <?= e($currentOperation["expected"]) ?>
                            </p>

                        </div>

                    </div>

                    <div class="col-lg-6">

                        <div class="explanation-block">

                            <div class="label">
                                General SQL Syntax
                            </div>

                            <div class="syntax-box">
                                <?= e($currentOperation["syntax"]) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </section>

            <!-- =================================================
                 SQL STATEMENT
            ================================================== -->

            <section class="query-card">

                <div class="sql-toolbar">

                    <div>

                        <h5 class="fw-bold mb-1">
                            <i class="bi bi-code-slash me-2 text-primary"></i>
                            SQL Statement
                        </h5>

                        <small class="text-muted">
                            Actual PostgreSQL query used by the system.
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

                <pre class="sql-preview" id="sqlCode"><?= e($sql) ?></pre>

            </section>

            <!-- =================================================
                 EXECUTION ERROR
            ================================================== -->

            <?php if ($errorMessage !== ""): ?>

                <div class="alert alert-danger">

                    <i class="bi bi-exclamation-triangle me-2"></i>

                    <strong>Query Execution Failed</strong>

                    <p class="mb-0 mt-1 small">
                        <?= e($errorMessage) ?>
                    </p>

                </div>

            <?php endif; ?>

            <!-- =================================================
                 EXECUTION ANALYTICS
            ================================================== -->

            <?php if ($queryExecuted): ?>

                <section class="analytics-card">

                    <div class="section-heading mb-4">

                        <h5 class="fw-bold">
                            <i class="bi bi-activity me-2 text-primary"></i>
                            Query Execution Report
                        </h5>

                        <?php if ($errorMessage === ""): ?>

                            <span class="execution-status success">
                                <i class="bi bi-check-circle-fill"></i>
                                SUCCESSFULLY EXECUTED
                            </span>

                        <?php else: ?>

                            <span class="execution-status failed">
                                <i class="bi bi-x-circle-fill"></i>
                                EXECUTION FAILED
                            </span>

                        <?php endif; ?>

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
                                    <?= number_format($totalResults) ?>
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

                                <small>Result Limit</small>

                                <strong>500</strong>

                            </div>

                        </div>

                    </div>

                    <p class="text-muted mt-3 mb-0" style="font-size:10px;line-height:1.7;">

                        <i class="bi bi-info-circle me-1"></i>

                        Execution time measures the time spent executing the
                        query and fetching its results through PDO. It is not
                        a PostgreSQL server-side EXPLAIN ANALYZE measurement.
                        Queries with LIMIT 500 display a maximum of 500 rows.

                    </p>

                </section>

            <?php endif; ?>

            <!-- =================================================
                 QUERY RESULTS
            ================================================== -->

            <section class="result-card">

                <div class="section-heading mb-3">

                    <div>

                        <h5 class="fw-bold mb-1">
                            <i class="bi bi-table me-2 text-primary"></i>
                            Query Results
                        </h5>

                        <small class="text-muted">
                            Live results retrieved from PostgreSQL.
                        </small>

                    </div>

                    <?php if ($queryExecuted && $errorMessage === ""): ?>

                        <span class="result-count" id="resultCount">

                            <?= number_format($totalResults) ?> records found

                        </span>

                    <?php else: ?>

                        <span class="badge bg-secondary-subtle text-secondary px-3 py-2">
                            <?= $queryExecuted ? "Query Failed" : "Awaiting Execution" ?>
                        </span>

                    <?php endif; ?>

                </div>

                <?php if (!$queryExecuted): ?>

                    <div class="empty-state">

                        <i class="bi bi-database d-block mb-3"></i>

                        <h6 class="fw-bold">
                            Ready to Execute
                        </h6>

                        <p class="small mb-0">
                            Select an SQL operation above and execute the query
                            to retrieve actual database records.
                        </p>

                    </div>

                <?php elseif ($errorMessage === ""): ?>

                    <?php if (empty($results)): ?>

                        <div class="empty-state">

                            <i class="bi bi-inbox d-block mb-3"></i>

                            <h6 class="fw-bold">
                                No Records Found
                            </h6>

                            <p class="small mb-0">
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

                            <div class="d-flex gap-2 flex-wrap">

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
                                    Print

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

                                                <i class="bi bi-arrow-down-up ms-1 text-muted"></i>

                                            </th>

                                        <?php endforeach; ?>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($results as $row): ?>

                                        <tr>

                                            <?php foreach ($columns as $column): ?>

                                                <td>

                                                    <?php if ($row[$column] === null): ?>

                                                        <span class="null-value">
                                                            NULL
                                                        </span>

                                                    <?php else: ?>

                                                        <?= e($row[$column]) ?>

                                                        <button
                                                            type="button"
                                                            class="copy-cell"
                                                            title="Copy value"
                                                            data-copy="<?= e($row[$column]) ?>"
                                                        >
                                                            <i class="bi bi-copy"></i>
                                                        </button>

                                                    <?php endif; ?>

                                                </td>

                                            <?php endforeach; ?>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">

                            <small class="text-muted" id="visibleRows">
                                Showing <?= number_format($totalResults) ?> records
                            </small>

                            <small class="text-muted">
                                Click a column heading to sort its values.
                            </small>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </section>

            <!-- =================================================
                 AGGREGATION CHART
            ================================================== -->

            <?php if (
                $queryExecuted
                && $errorMessage === ""
                && count($chartValues) >= 2
            ): ?>

                <section class="analytics-card">

                    <div class="section-heading mb-3">

                        <div>

                            <h5 class="fw-bold mb-1">
                                <i class="bi bi-bar-chart-line me-2 text-primary"></i>
                                Visual Data Analysis
                            </h5>

                            <small class="text-muted">
                                Graphical representation of the aggregation results.
                            </small>

                        </div>

                        <span class="badge bg-primary-subtle text-primary">
                            Chart Visualization
                        </span>

                    </div>

                    <div class="chart-container">

                        <canvas id="aggregationChart"></canvas>

                    </div>

                    <p class="text-muted small mt-3 mb-0">

                        The chart uses the first descriptive column as its
                        category labels and the first numeric column as its
                        plotted values. The table above remains the complete
                        source of the query results.

                    </p>

                </section>

            <?php endif; ?>

            <!-- =================================================
                 WEEK 7 PRACTICAL REVIEWER
            ================================================== -->

            <section class="guide-card">

                <div class="section-heading mb-4">

                    <h5 class="fw-bold">
                        <i class="bi bi-mortarboard me-2 text-primary"></i>
                        Week 7 Practical Examination Reviewer
                    </h5>

                    <span>SQL Concepts and Applications</span>

                </div>

                <div class="table-responsive">

                    <table class="table table-bordered guide-table align-middle">

                        <thead>

                            <tr>

                                <th style="width:20%;">
                                    SQL Concept
                                </th>

                                <th style="width:35%;">
                                    Definition
                                </th>

                                <th>
                                    Implementation in This System
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <tr>

                                <td><strong>INNER JOIN</strong></td>

                                <td>
                                    Returns records with matching values in both tables.
                                </td>

                                <td>
                                    Retrieves borrowers with corresponding borrowing transactions.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>LEFT JOIN</strong></td>

                                <td>
                                    Returns all records from the left table and matching records from the right table.
                                </td>

                                <td>
                                    Displays all registered borrowers, including those without transactions.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>RIGHT JOIN</strong></td>

                                <td>
                                    Returns all records from the right table and matching records from the left table.
                                </td>

                                <td>
                                    Displays all borrowing transactions and borrower information.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>FULL OUTER JOIN</strong></td>

                                <td>
                                    Returns matching and unmatched records from both tables.
                                </td>

                                <td>
                                    Combines all borrowers and transactions, retaining unmatched records.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>GROUP BY</strong></td>

                                <td>
                                    Groups records that share the same value in specified columns.
                                </td>

                                <td>
                                    Groups borrowers and transactions according to their categories or statuses.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>COUNT</strong></td>

                                <td>
                                    Counts the number of rows or non-NULL values.
                                </td>

                                <td>
                                    Calculates borrower, equipment, transaction, and item record counts.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>SUM</strong></td>

                                <td>
                                    Calculates the total of numeric values.
                                </td>

                                <td>
                                    Calculates the total available and borrowed equipment quantities.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>AVG</strong></td>

                                <td>
                                    Calculates the arithmetic average of numeric values.
                                </td>

                                <td>
                                    Determines the average equipment and borrowed item quantities.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>MIN / MAX</strong></td>

                                <td>
                                    Returns the smallest and largest values.
                                </td>

                                <td>
                                    Identifies the minimum and maximum recorded quantities.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>UNION</strong></td>

                                <td>
                                    Combines compatible query results and removes duplicates.
                                </td>

                                <td>
                                    Combines borrower IDs from borrowers and transactions.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>INTERSECT</strong></td>

                                <td>
                                    Returns values common to both query results.
                                </td>

                                <td>
                                    Identifies borrowers who have borrowing transactions.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>EXCEPT</strong></td>

                                <td>
                                    Returns records found in the first query but not in the second.
                                </td>

                                <td>
                                    Identifies borrowers who have never borrowed equipment.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>String Functions</strong></td>

                                <td>
                                    Manipulate and transform character and text values.
                                </td>

                                <td>
                                    Demonstrates UPPER, LOWER, LENGTH, and CONCAT.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>Numeric Functions</strong></td>

                                <td>
                                    Perform mathematical operations on numeric values.
                                </td>

                                <td>
                                    Demonstrates ABS, ROUND, CEIL, and FLOOR.
                                </td>

                            </tr>

                            <tr>

                                <td><strong>Date Functions</strong></td>

                                <td>
                                    Retrieve and calculate date and time information.
                                </td>

                                <td>
                                    Demonstrates CURRENT_DATE, AGE, EXTRACT, and date subtraction.
                                </td>

                            </tr>

                        </tbody>

                    </table>

                </div>

            </section>

            <!-- =================================================
                 POSSIBLE ORAL EXAMINATION QUESTIONS
            ================================================== -->

            <section class="guide-card">

                <h5 class="fw-bold mb-3">

                    <i class="bi bi-patch-question me-2 text-primary"></i>

                    Possible Oral Examination Questions

                </h5>

                <p class="text-muted small mb-4">
                    Review these questions to understand the purpose and
                    differences between the SQL operations demonstrated.
                </p>

                <div class="exam-question">

                    <h6>1. What is the difference between INNER JOIN and LEFT JOIN?</h6>

                    <p>
                        INNER JOIN returns only records with matching values
                        in both tables. LEFT JOIN returns all records from
                        the left table, including those without a matching
                        record in the right table. Missing values are shown as NULL.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>2. When would you use RIGHT JOIN?</h6>

                    <p>
                        RIGHT JOIN is useful when all records from the right
                        table must be retained, even if corresponding records
                        do not exist in the left table.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>3. What is the purpose of FULL OUTER JOIN?</h6>

                    <p>
                        It combines matching records and retains unmatched
                        records from both tables. Missing related information
                        is represented by NULL.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>4. What is the difference between WHERE and HAVING?</h6>

                    <p>
                        WHERE filters individual rows before grouping.
                        HAVING filters grouped results after GROUP BY.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>5. What is the difference between UNION and UNION ALL?</h6>

                    <p>
                        UNION removes duplicate records, while UNION ALL
                        retains duplicates. UNION ALL generally requires
                        less work because it does not eliminate duplicates.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>6. What is the difference between INTERSECT and EXCEPT?</h6>

                    <p>
                        INTERSECT returns common records from both queries.
                        EXCEPT returns records found in the first query
                        but not in the second.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>7. Why do we use GROUP BY?</h6>

                    <p>
                        GROUP BY organizes records with the same values into
                        groups, allowing aggregate functions such as COUNT,
                        SUM, AVG, MIN, and MAX to calculate results for each group.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>8. What is the purpose of COALESCE?</h6>

                    <p>
                        COALESCE returns the first non-NULL value from its
                        arguments. It is useful for replacing NULL aggregate
                        results with a default value such as zero.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>9. What is a foreign key?</h6>

                    <p>
                        A foreign key is a column or group of columns that
                        references a primary or unique key in another table.
                        It establishes relationships and helps maintain
                        referential integrity.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>10. Why can RIGHT JOIN and INNER JOIN return the same results in this database?</h6>

                    <p>
                        The borrow_transactions table uses a NOT NULL foreign
                        key referencing borrowers. Therefore, every valid
                        transaction must have a matching borrower. As a result,
                        both queries return the same transaction records
                        when no additional filtering changes the results.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>11. What is the difference between CEIL and FLOOR?</h6>

                    <p>
                        CEIL rounds a numeric value upward to the nearest
                        integer, while FLOOR rounds it downward to the nearest
                        integer.
                    </p>

                </div>

                <div class="exam-question">

                    <h6>12. What does the AGE() function do?</h6>

                    <p>
                        AGE() calculates the interval between two date or
                        timestamp values and returns the result in years,
                        months, and days where applicable.
                    </p>

                </div>

            </section>

            <!-- =================================================
                 DATABASE INTEGRITY NOTES
            ================================================== -->

            <section class="guide-card">

                <h5 class="fw-bold mb-3">

                    <i class="bi bi-shield-check me-2 text-primary"></i>

                    Database Integrity and Technical Notes

                </h5>

                <div class="success-note mb-3">

                    <strong>Foreign Key Relationship</strong>

                    <p class="mb-0 mt-2">

                        The borrow_transactions.borrower_id column references
                        borrowers.borrower_id. When the foreign key is
                        enforced and the column is NOT NULL, every valid
                        transaction must reference an existing borrower.

                    </p>

                </div>

                <div class="info-note mb-3">

                    <strong>JOIN Result Interpretation</strong>

                    <p class="mb-0 mt-2">

                        Because of the foreign key constraint, RIGHT JOIN
                        and INNER JOIN can return equivalent matching
                        transaction records. Similarly, FULL OUTER JOIN
                        and LEFT JOIN can return equivalent records.
                        This is an expected outcome of the database design,
                        not an error in the SQL statements.

                    </p>

                </div>

                <div class="info-note">

                    <strong>Result Limitation</strong>

                    <p class="mb-0 mt-2">

                        Queries containing LIMIT 500 display no more than
                        500 records. The aggregation queries are not subject
                        to that limit because they return summarized results.
                        The execution report measures PHP/PDO elapsed time
                        rather than PostgreSQL's internal execution-plan cost.

                    </p>

                </div>

            </section>

            <!-- =================================================
                 FOOTER
            ================================================== -->

            <footer class="footer">

                <span>
                    Database Administration Practical System
                </span>

                <span>
                    SQL Operations · Week 7
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
| COPY SQL
|--------------------------------------------------------------------------
*/

document.getElementById("copySql")?.addEventListener("click", async function () {

    const sql = document.getElementById("sqlCode").innerText;

    try {

        await navigator.clipboard.writeText(sql);

        this.innerHTML = '<i class="bi bi-check-circle me-1"></i> Copied!';

        setTimeout(() => {

            this.innerHTML = '<i class="bi bi-clipboard me-1"></i> Copy SQL';

        }, 2000);

    } catch (error) {

        alert("Unable to copy SQL. Please select and copy the statement manually.");

    }

});


/*
|--------------------------------------------------------------------------
| SEARCH RESULTS
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
| COPY INDIVIDUAL TABLE VALUES
|--------------------------------------------------------------------------
*/

document.querySelectorAll(".copy-cell").forEach(button => {

    button.addEventListener("click", async function () {

        const value = this.dataset.copy;

        try {

            await navigator.clipboard.writeText(value);

            const original = this.innerHTML;

            this.innerHTML = '<i class="bi bi-check"></i>';

            setTimeout(() => {

                this.innerHTML = original;

            }, 1200);

        } catch (error) {

            alert("Unable to copy this value.");

        }

    });

});


/*
|--------------------------------------------------------------------------
| EXPORT RESULTS TO CSV
|--------------------------------------------------------------------------
*/

document.getElementById("exportCsv")?.addEventListener("click", function () {

    const table = document.getElementById("resultsTable");

    if (!table) return;

    const rows = [];

    table.querySelectorAll("tr").forEach(row => {

        const cells = Array.from(row.querySelectorAll("th, td"));

        const values = cells.map(cell => {

            let value;

            if (cell.querySelector(".null-value")) {

                value = "NULL";

            } else {

                value = cell.innerText.trim();

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

    link.download = "week7_sql_results.csv";

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
        $currentOperation["label"],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Week 7 - ${title}</title>
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
                    body { padding: 0; }
                }
            </style>
        </head>
        <body>
            <h2>Database Administration Practical System</h2>
            <p>Week 7 SQL Operations | ${title}</p>
            ${table.outerHTML}
        </body>
        </html>
    `);

    printWindow.document.close();

    printWindow.onload = function () {

        printWindow.print();

    };

});


/*
|--------------------------------------------------------------------------
| AGGREGATION CHART
|--------------------------------------------------------------------------
*/

const chartCanvas = document.getElementById("aggregationChart");

if (chartCanvas) {

    const chartLabels = <?= json_encode(
        $chartLabels,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const chartValues = <?= json_encode(
        $chartValues,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const chartTitle = <?= json_encode(
        $chartTitle,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    new Chart(chartCanvas, {

        type: "bar",

        data: {

            labels: chartLabels,

            datasets: [{

                label: chartTitle,

                data: chartValues,

                backgroundColor: [
                    "#315CC5",
                    "#18A999",
                    "#F0AD4E",
                    "#7D69CF",
                    "#E66A8D",
                    "#3F9AB5"
                ],

                borderRadius: 7,

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
                }

            }

        }

    });

}

</script>

</body>

</html>
