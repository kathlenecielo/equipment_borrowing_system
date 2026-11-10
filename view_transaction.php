<?php
session_start();
require_once "../includes/db.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: transactions.php?status=invalid");
    exit;
}

// Retrieve transaction and borrower details.
$stmt = $pdo->prepare("
    SELECT
        bt.borrow_id,
        bt.borrow_date,
        bt.due_date,
        bt.status,
        b.borrower_id,
        b.borrower_no,
        b.full_name,
        b.borrower_type
    FROM public.borrow_transactions bt
    JOIN public.borrowers b
        ON b.borrower_id = bt.borrower_id
    WHERE bt.borrow_id = :id
");

$stmt->execute(["id" => $id]);
$transaction = $stmt->fetch();

if (!$transaction) {
    header("Location: transactions.php?status=not_found");
    exit;
}

// Retrieve all equipment items associated with this transaction.
$itemStmt = $pdo->prepare("
    SELECT
        bi.borrow_item_id,
        bi.quantity,
        e.equipment_id,
        e.property_no,
        e.equipment_name
    FROM public.borrow_items bi
    JOIN public.equipment e
        ON e.equipment_id = bi.equipment_id
    WHERE bi.borrow_id = :id
    ORDER BY bi.borrow_item_id
");

$itemStmt->execute(["id" => $id]);
$items = $itemStmt->fetchAll();

$totalQuantity = array_sum(
    array_column($items, "quantity")
);

// Normalize transaction status.
$normalizedStatus = strtolower(trim($transaction["status"]));

$statusClass = match ($normalizedStatus) {
    "borrowed", "active" => "bg-primary-subtle text-primary",
    "returned", "completed" => "bg-success-subtle text-success",
    "overdue" => "bg-danger-subtle text-danger",
    default => "bg-secondary-subtle text-secondary"
};

// Check whether the transaction is overdue.
$isOverdue = (
    $transaction["due_date"] < date("Y-m-d") &&
    !in_array(
        $normalizedStatus,
        ["returned", "completed", "cancelled"],
        true
    )
);

// Determine whether equipment can still be returned.
$canReturn = !in_array(
    $normalizedStatus,
    ["returned", "completed", "cancelled"],
    true
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Transaction Details | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .detail-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 24px;
            height: 100%;
        }

        .detail-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .detail-value {
            font-size: 15px;
            font-weight: 700;
            margin-top: 6px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: 800;
        }

        @media print {
            .sidebar,
            .topbar,
            .btn,
            .footer,
            .breadcrumb-text {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                width: 100% !important;
            }

            .content-area {
                padding: 0 !important;
            }

            .detail-card {
                border: 1px solid #ddd;
                break-inside: avoid;
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

        <header class="topbar">
            <div>
                <div class="breadcrumb-text">PART 1 / WEEKS 4–5</div>
                <h4>Transaction Details</h4>
            </div>
        </header>

        <div class="content-area">

            <!-- Return Success Notification -->
            <?php if (($_GET["status"] ?? "") === "returned"): ?>

                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Equipment successfully returned!</strong>
                    Inventory quantities have been restored.

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>
                </div>

            <?php endif; ?>

            <!-- Back Navigation -->
            <div class="mb-4">
                <a href="transactions.php" class="text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Transactions
                </a>
            </div>

            <!-- Transaction Heading -->
            <div class="welcome-section">
                <div>
                    <h2>
                        Transaction #<?= (int)$transaction["borrow_id"] ?>
                    </h2>

                    <p class="text-muted small">
                        Complete borrowing record and equipment details.
                    </p>
                </div>

                <span class="badge rounded-pill <?= $statusClass ?> px-3 py-2">
                    <?= e($transaction["status"]) ?>
                </span>
            </div>

            <!-- Overdue Notification -->
            <?php if ($isOverdue): ?>

                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    This transaction is past its due date.
                </div>

            <?php endif; ?>

            <!-- Borrowing Information -->
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <div class="detail-card">

                        <div class="detail-label">Borrower</div>

                        <div class="detail-value">
                            <?= e($transaction["full_name"]) ?>
                        </div>

                        <div class="text-muted small mt-1">
                            <?= e($transaction["borrower_no"]) ?>
                        </div>

                        <div class="text-muted small">
                            <?= e($transaction["borrower_type"]) ?>
                        </div>

                    </div>
                </div>

                <div class="col-md-4">
                    <div class="detail-card">

                        <div class="detail-label">Borrow Date</div>

                        <div class="detail-value">
                            <i class="bi bi-calendar-event me-2 text-primary"></i>
                            <?= e($transaction["borrow_date"]) ?>
                        </div>

                    </div>
                </div>

                <div class="col-md-4">
                    <div class="detail-card">

                        <div class="detail-label">Due Date</div>

                        <div class="detail-value">
                            <i class="bi bi-calendar-check me-2 text-primary"></i>
                            <?= e($transaction["due_date"]) ?>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Borrowed Equipment -->
            <section class="detail-card mb-4">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                    <div>
                        <h5 class="fw-bold mb-1">
                            Borrowed Equipment
                        </h5>

                        <p class="text-muted small mb-0">
                            Equipment items included in this transaction.
                        </p>
                    </div>

                    <div class="text-end">

                        <div class="detail-label">
                            Total Units
                        </div>

                        <div class="summary-number">
                            <?= number_format($totalQuantity) ?>
                        </div>

                    </div>

                </div>

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>
                            <tr>
                                <th>Property Number</th>
                                <th>Equipment Name</th>
                                <th>Quantity Borrowed</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (empty($items)): ?>

                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">
                                        No equipment items recorded.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($items as $item): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= e($item["property_no"]) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= e($item["equipment_name"]) ?>
                                        </td>

                                        <td>
                                            <span class="badge bg-primary-subtle text-primary">
                                                <?= number_format((int)$item["quantity"]) ?>
                                                units
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

            <!-- Action Buttons -->
            <div class="d-flex gap-2 flex-wrap">

                <a href="transactions.php" class="btn btn-light px-4">
                    <i class="bi bi-arrow-left me-2"></i>
                    Back
                </a>

                <?php if ($canReturn && !empty($items)): ?>

                    <a href="return_equipment.php?id=<?= (int)$transaction["borrow_id"] ?>"
                       class="btn btn-success px-4">

                        <i class="bi bi-box-arrow-in-left me-2"></i>
                        Return Equipment

                    </a>

                <?php endif; ?>

                <button onclick="window.print()"
                        class="btn btn-outline-primary px-4">

                    <i class="bi bi-printer me-2"></i>
                    Print Details

                </button>

            </div>

            <!-- Footer -->
            <footer class="footer">
                <span>Database Administration Practical System</span>
                <span>Transaction Details · Week 4–5</span>
            </footer>

        </div>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
