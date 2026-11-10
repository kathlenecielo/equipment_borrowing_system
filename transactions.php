<?php
session_start();
require_once "../includes/db.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Search and filter inputs.
$search = trim($_GET["search"] ?? "");
$filter = strtolower(trim($_GET["filter"] ?? "all"));

$allowedFilters = [
    "all",
    "borrowed",
    "returned",
    "overdue",
    "cancelled"
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = "all";
}

// Computed transaction status.
$statusExpression = "
    CASE
        WHEN LOWER(bt.status) IN ('returned', 'completed', 'cancelled')
            THEN LOWER(bt.status)
        WHEN bt.due_date < CURRENT_DATE
            THEN 'overdue'
        ELSE LOWER(bt.status)
    END
";

// Dashboard statistics.
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_transactions,

        COUNT(*) FILTER (
            WHERE LOWER(status) NOT IN ('returned', 'completed', 'cancelled')
        ) AS active_transactions,

        COUNT(*) FILTER (
            WHERE LOWER(status) IN ('returned', 'completed')
        ) AS returned_transactions,

        COUNT(*) FILTER (
            WHERE LOWER(status) NOT IN ('returned', 'completed', 'cancelled')
              AND due_date < CURRENT_DATE
        ) AS overdue_transactions

    FROM public.borrow_transactions
");

$stats = $statsStmt->fetch();

// Build transaction list query.
$sql = "
    SELECT
        bt.borrow_id,
        bt.borrow_date,
        bt.due_date,
        bt.status,

        b.borrower_no,
        b.full_name,
        b.borrower_type,

        COALESCE(items.item_count, 0) AS item_count,
        COALESCE(items.total_quantity, 0) AS total_quantity,

        $statusExpression AS display_status

    FROM public.borrow_transactions bt

    JOIN public.borrowers b
        ON b.borrower_id = bt.borrower_id

    LEFT JOIN (
        SELECT
            borrow_id,
            COUNT(*) AS item_count,
            SUM(quantity) AS total_quantity
        FROM public.borrow_items
        GROUP BY borrow_id
    ) items
        ON items.borrow_id = bt.borrow_id

    WHERE 1 = 1
";

$params = [];

// Search by transaction number, borrower number, or borrower name.
if ($search !== "") {
    $sql .= "
        AND (
            CAST(bt.borrow_id AS TEXT) ILIKE :search
            OR b.borrower_no ILIKE :search
            OR b.full_name ILIKE :search
        )
    ";

    $params["search"] = "%" . $search . "%";
}

// Apply status filter.
if ($filter !== "all") {
    $sql .= " AND ($statusExpression) = :filter";
    $params["filter"] = $filter;
}

$sql .= "
    ORDER BY bt.borrow_id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$transactions = $stmt->fetchAll();

// Status badge styling.
function statusBadge($status) {
    return match ($status) {
        "borrowed", "active" => "bg-primary-subtle text-primary",
        "returned", "completed" => "bg-success-subtle text-success",
        "overdue" => "bg-danger-subtle text-danger",
        "cancelled" => "bg-secondary-subtle text-secondary",
        default => "bg-secondary-subtle text-secondary"
    };
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Transactions & Items | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 20px;
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
            font-size: 27px;
            font-weight: 800;
            margin-top: 12px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }

        .table-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 24px;
        }

        .search-box {
            max-width: 340px;
        }

        .transaction-id {
            font-weight: 800;
            color: var(--primary);
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 40px;
            color: #adb5bd;
        }

        @media (max-width: 768px) {
            .table-card {
                padding: 15px;
            }

            .search-box {
                max-width: 100%;
                width: 100%;
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

        <!-- Header -->
        <header class="topbar">

            <div>
                <div class="breadcrumb-text">
                    PART 1 / WEEKS 4–5
                </div>

                <h4>Transactions & Items</h4>
            </div>

        </header>

        <div class="content-area">

            <!-- Page Heading -->
            <div class="welcome-section">

                <div>
                    <h2>Borrowing Transactions</h2>

                    <p class="text-muted small">
                        Manage borrowing records, equipment items, and returns.
                    </p>
                </div>

                <a href="add_transaction.php" class="btn btn-primary px-4">
                    <i class="bi bi-plus-lg me-2"></i>
                    New Transaction
                </a>

            </div>

            <!-- Statistics -->
            <div class="row g-3 mb-4">

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-primary-subtle text-primary">
                            <i class="bi bi-arrow-left-right"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["total_transactions"]) ?>
                        </div>

                        <div class="stat-label">
                            TOTAL TRANSACTIONS
                        </div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-info-subtle text-info">
                            <i class="bi bi-box-seam"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["active_transactions"]) ?>
                        </div>

                        <div class="stat-label">
                            ACTIVE BORROWINGS
                        </div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-success-subtle text-success">
                            <i class="bi bi-check-circle"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["returned_transactions"]) ?>
                        </div>

                        <div class="stat-label">
                            RETURNED TRANSACTIONS
                        </div>

                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="stat-card">

                        <div class="stat-icon bg-danger-subtle text-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format((int)$stats["overdue_transactions"]) ?>
                        </div>

                        <div class="stat-label">
                            OVERDUE TRANSACTIONS
                        </div>

                    </div>
                </div>

            </div>

            <!-- Transactions Table -->
            <section class="table-card">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                    <div>
                        <h5 class="fw-bold mb-1">
                            Transaction Records
                        </h5>

                        <p class="text-muted small mb-0">
                            View and manage all equipment borrowing records.
                        </p>
                    </div>

                    <span class="badge bg-primary-subtle text-primary px-3 py-2">
                        <?= count($transactions) ?> records found
                    </span>

                </div>

                <!-- Search and Filter -->
                <form method="GET" class="row g-2 mb-4">

                    <div class="col-lg-5">

                        <div class="input-group search-box">

                            <span class="input-group-text bg-white">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Search borrower or transaction..."
                                value="<?= e($search) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-lg-3">

                        <select name="filter" class="form-select">

                            <option value="all" <?= $filter === "all" ? "selected" : "" ?>>
                                All Transactions
                            </option>

                            <option value="borrowed" <?= $filter === "borrowed" ? "selected" : "" ?>>
                                Borrowed / Active
                            </option>

                            <option value="returned" <?= $filter === "returned" ? "selected" : "" ?>>
                                Returned
                            </option>

                            <option value="overdue" <?= $filter === "overdue" ? "selected" : "" ?>>
                                Overdue
                            </option>

                            <option value="cancelled" <?= $filter === "cancelled" ? "selected" : "" ?>>
                                Cancelled
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-4 d-flex gap-2">

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel me-1"></i>
                            Apply
                        </button>

                        <a href="transactions.php" class="btn btn-light">
                            Reset
                        </a>

                    </div>

                </form>

                <!-- Table -->
                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>
                            <tr>
                                <th>Transaction</th>
                                <th>Borrower</th>
                                <th>Borrow Date</th>
                                <th>Due Date</th>
                                <th>Equipment</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (empty($transactions)): ?>

                            <tr>
                                <td colspan="8">

                                    <div class="empty-state">

                                        <i class="bi bi-inbox"></i>

                                        <h6 class="fw-bold mt-3">
                                            No Transactions Found
                                        </h6>

                                        <p class="text-muted small">
                                            Try another search or create a new borrowing transaction.
                                        </p>

                                        <a href="add_transaction.php" class="btn btn-primary btn-sm">
                                            <i class="bi bi-plus-lg me-1"></i>
                                            Create Transaction
                                        </a>

                                    </div>

                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($transactions as $transaction): ?>

                                <?php
                                $displayStatus = $transaction["display_status"];
                                $isReturned = in_array(
                                    $displayStatus,
                                    ["returned", "completed", "cancelled"],
                                    true
                                );
                                ?>

                                <tr>

                                    <!-- Transaction ID -->
                                    <td>
                                        <span class="transaction-id">
                                            #<?= (int)$transaction["borrow_id"] ?>
                                        </span>
                                    </td>

                                    <!-- Borrower -->
                                    <td>

                                        <div class="fw-semibold">
                                            <?= e($transaction["full_name"]) ?>
                                        </div>

                                        <div class="text-muted small">
                                            <?= e($transaction["borrower_no"]) ?>
                                        </div>

                                    </td>

                                    <!-- Borrow Date -->
                                    <td>
                                        <?= e($transaction["borrow_date"]) ?>
                                    </td>

                                    <!-- Due Date -->
                                    <td>
                                        <?= e($transaction["due_date"]) ?>
                                    </td>

                                    <!-- Equipment Count -->
                                    <td>
                                        <?= number_format((int)$transaction["item_count"]) ?>
                                        item(s)
                                    </td>

                                    <!-- Total Quantity -->
                                    <td>
                                        <strong>
                                            <?= number_format((int)$transaction["total_quantity"]) ?>
                                        </strong>
                                        units
                                    </td>

                                    <!-- Status -->
                                    <td>

                                        <span class="badge rounded-pill px-3 py-2 <?= statusBadge($displayStatus) ?>">
                                            <?= e(ucfirst($displayStatus)) ?>
                                        </span>

                                    </td>

                                    <!-- Actions -->
                                    <td>

                                        <div class="action-buttons">

                                            <a
                                                href="view_transaction.php?id=<?= (int)$transaction["borrow_id"] ?>"
                                                class="btn btn-sm btn-outline-primary"
                                                title="View Details"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </a>

                                            <?php if (!$isReturned && (int)$transaction["item_count"] > 0): ?>

                                                <a
                                                    href="return_equipment.php?id=<?= (int)$transaction["borrow_id"] ?>"
                                                    class="btn btn-sm btn-outline-success"
                                                    title="Return Equipment"
                                                >
                                                    <i class="bi bi-box-arrow-in-left"></i>
                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

            <!-- Footer -->
            <footer class="footer">

                <span>
                    Database Administration Practical System
                </span>

                <span>
                    Transactions & Items · Week 4–5
                </span>

            </footer>

        </div>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
