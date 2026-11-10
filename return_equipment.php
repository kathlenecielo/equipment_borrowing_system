<?php
session_start();
require_once "../includes/db.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$error = "";

if (!$id || $id < 1) {
    header("Location: transactions.php?status=invalid");
    exit;
}

// Process complete equipment return.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page.";

    } else {
        try {

            // Start database transaction.
            $pdo->beginTransaction();

            // ==========================================
            // AUDIT INFORMATION
            // ==========================================

            // Capture the actual client IP address.
            $clientIP = $_SERVER["REMOTE_ADDR"] ?? "";

            // Capture the currently logged-in user.
            $changedBy = $_SESSION["username"]
                ?? $_SESSION["user_id"]
                ?? $_SESSION["user_name"]
                ?? "Unknown";

            // Capture browser and device information.
            $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? "";

            // Pass audit information to PostgreSQL.
            // These settings are local to this transaction.
            $setAuditInfo = $pdo->prepare("
                SELECT
                    set_config('app.client_ip', :ip, true),
                    set_config('app.changed_by', :changed_by, true),
                    set_config('app.user_agent', :user_agent, true)
            ");

            $setAuditInfo->execute([
                ":ip" => $clientIP,
                ":changed_by" => (string)$changedBy,
                ":user_agent" => $userAgent
            ]);

            // ==========================================
            // LOCK TRANSACTION
            // ==========================================

            $lock = $pdo->prepare("
                SELECT borrow_id, status
                FROM public.borrow_transactions
                WHERE borrow_id = :id
                FOR UPDATE
            ");

            $lock->execute(["id" => $id]);
            $lockedTransaction = $lock->fetch();

            if (!$lockedTransaction) {
                throw new RuntimeException("Transaction not found.");
            }

            $currentStatus = strtolower($lockedTransaction["status"]);

            if (in_array(
                $currentStatus,
                ["returned", "completed", "cancelled"],
                true
            )) {
                throw new RuntimeException(
                    "This transaction has already been returned or closed."
                );
            }

            // ==========================================
            // RETRIEVE BORROWED EQUIPMENT
            // ==========================================

            $itemQuery = $pdo->prepare("
                SELECT equipment_id, quantity
                FROM public.borrow_items
                WHERE borrow_id = :id
            ");

            $itemQuery->execute(["id" => $id]);
            $items = $itemQuery->fetchAll();

            if (empty($items)) {
                throw new RuntimeException(
                    "No equipment items were found for this transaction."
                );
            }

            // ==========================================
            // RESTORE INVENTORY
            // ==========================================

            $restore = $pdo->prepare("
                UPDATE public.equipment
                SET quantity_available = quantity_available + :quantity
                WHERE equipment_id = :equipment_id
            ");

            foreach ($items as $item) {

                $restore->execute([
                    "quantity" => (int)$item["quantity"],
                    "equipment_id" => (int)$item["equipment_id"]
                ]);

                if ($restore->rowCount() !== 1) {
                    throw new RuntimeException(
                        "Unable to restore one of the equipment records."
                    );
                }
            }

            // ==========================================
            // UPDATE TRANSACTION STATUS
            // ==========================================
            // This automatically activates:
            // trg_borrow_status_audit
            //
            // PostgreSQL records:
            // - Old status
            // - New status
            // - Changed by
            // - Client IP address
            // - Browser information
            // - Timestamp

            $updateStatus = $pdo->prepare("
                UPDATE public.borrow_transactions
                SET status = 'Returned'
                WHERE borrow_id = :id
            ");

            $updateStatus->execute(["id" => $id]);

            // ==========================================
            // COMMIT CHANGES
            // ==========================================

            $pdo->commit();

            // Redirect after successful return.
            header(
                "Location: view_transaction.php?id=" .
                $id . "&status=returned"
            );
            exit;

        } catch (Throwable $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($ex->getMessage());

            $error = $ex instanceof RuntimeException
                ? $ex->getMessage()
                : "Unable to process the return. No inventory changes were saved.";
        }
    }
}

// ==========================================
// RETRIEVE TRANSACTION DETAILS
// ==========================================

$stmt = $pdo->prepare("
    SELECT
        bt.borrow_id,
        bt.borrow_date,
        bt.due_date,
        bt.status,
        b.full_name,
        b.borrower_no
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

// ==========================================
// RETRIEVE EQUIPMENT ITEMS
// ==========================================

$itemsQuery = $pdo->prepare("
    SELECT
        e.property_no,
        e.equipment_name,
        bi.quantity
    FROM public.borrow_items bi
    JOIN public.equipment e
        ON e.equipment_id = bi.equipment_id
    WHERE bi.borrow_id = :id
");

$itemsQuery->execute(["id" => $id]);
$items = $itemsQuery->fetchAll();

$isClosed = in_array(
    strtolower($transaction["status"]),
    ["returned", "completed", "cancelled"],
    true
);

$totalQuantity = array_sum(array_column($items, "quantity"));
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Return Equipment | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        .return-card {
            max-width: 850px;
            margin: 20px auto;
            padding: 28px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
        }

        .return-icon {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: #e4f7ed;
            color: #198754;
            font-size: 24px;
            margin-bottom: 18px;
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

                <div class="breadcrumb-text">
                    PART 1 / WEEKS 4–5
                </div>

                <h4>Return Equipment</h4>

            </div>

        </header>

        <div class="content-area">

            <div class="mb-4">

                <a href="view_transaction.php?id=<?= (int)$id ?>"
                   class="text-decoration-none small">

                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Transaction

                </a>

            </div>

            <div class="return-card">

                <div class="return-icon">

                    <i class="bi bi-box-arrow-in-left"></i>

                </div>

                <h3 class="fw-bold">
                    Process Equipment Return
                </h3>

                <p class="text-muted mb-4">
                    Review the transaction before confirming the return.
                </p>

                <?php if ($error): ?>

                    <div class="alert alert-danger">
                        <?= e($error) ?>
                    </div>

                <?php endif; ?>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">

                        <div class="text-muted small">
                            Transaction ID
                        </div>

                        <strong>
                            #<?= (int)$transaction["borrow_id"] ?>
                        </strong>

                    </div>

                    <div class="col-md-6">

                        <div class="text-muted small">
                            Borrower
                        </div>

                        <strong>
                            <?= e($transaction["full_name"]) ?>
                        </strong>

                        <div class="text-muted small">
                            <?= e($transaction["borrower_no"]) ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="text-muted small">
                            Borrow Date
                        </div>

                        <strong>
                            <?= e($transaction["borrow_date"]) ?>
                        </strong>

                    </div>

                    <div class="col-md-6">

                        <div class="text-muted small">
                            Due Date
                        </div>

                        <strong>
                            <?= e($transaction["due_date"]) ?>
                        </strong>

                    </div>

                </div>

                <h5 class="fw-bold mb-3">
                    Equipment to Return
                </h5>

                <div class="table-responsive mb-4">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>
                                <th>Property Number</th>
                                <th>Equipment</th>
                                <th>Quantity to Restore</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($items as $item): ?>

                                <tr>

                                    <td>
                                        <?= e($item["property_no"]) ?>
                                    </td>

                                    <td>
                                        <?= e($item["equipment_name"]) ?>
                                    </td>

                                    <td>

                                        <strong>
                                            <?= number_format($item["quantity"]) ?>
                                        </strong>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <div class="alert alert-info">

                    <strong>Total units:</strong>
                    <?= number_format($totalQuantity) ?>

                    <p class="small mb-0 mt-2">

                        Confirming this return will restore all listed
                        quantities to the equipment inventory and mark
                        the transaction as Returned.

                    </p>

                </div>

                <?php if ($isClosed): ?>

                    <div class="alert alert-success">

                        <i class="bi bi-check-circle me-2"></i>

                        This transaction is already closed.
                        No further return action is available.

                    </div>

                <?php elseif (empty($items)): ?>

                    <div class="alert alert-warning">

                        No equipment items are available to return.

                    </div>

                <?php else: ?>

                    <form method="POST"
                          onsubmit="return confirm('Confirm the complete return of all equipment in this transaction?');">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION["csrf_token"]) ?>"
                        >

                        <button type="submit"
                                class="btn btn-success px-4">

                            <i class="bi bi-check-circle me-2"></i>

                            Confirm Equipment Return

                        </button>

                        <a href="view_transaction.php?id=<?= (int)$id ?>"
                           class="btn btn-light px-4">

                            Cancel

                        </a>

                    </form>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>

</body>
</html>
