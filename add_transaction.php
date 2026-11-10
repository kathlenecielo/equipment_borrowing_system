<?php
session_start();
require_once "../includes/db.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$errors = [];

$borrowers = $pdo->query("
    SELECT borrower_id, borrower_no, full_name, borrower_type
    FROM public.borrowers
    ORDER BY full_name
")->fetchAll();

$equipmentList = $pdo->query("
    SELECT equipment_id, property_no, equipment_name, quantity_available
    FROM public.equipment
    ORDER BY equipment_name
")->fetchAll();

$borrowDate = date("Y-m-d");
$dueDate = date("Y-m-d", strtotime("+7 days"));

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $errors[] = "Invalid request. Please refresh the page.";
    }

    $borrowerId = filter_var(
        $_POST["borrower_id"] ?? null,
        FILTER_VALIDATE_INT
    );

    $borrowDate = $_POST["borrow_date"] ?? "";
    $dueDate = $_POST["due_date"] ?? "";

    $equipmentIds = $_POST["equipment_id"] ?? [];
    $quantities = $_POST["quantity"] ?? [];

    if (!$borrowerId || $borrowerId < 1) {
        $errors[] = "Please select a borrower.";
    }

    $borrowerCheck = $pdo->prepare("
        SELECT COUNT(*)
        FROM public.borrowers
        WHERE borrower_id = :id
    ");
    $borrowerCheck->execute(["id" => $borrowerId]);

    if (!$borrowerCheck->fetchColumn()) {
        $errors[] = "The selected borrower does not exist.";
    }

    $validBorrowDate = DateTime::createFromFormat("!Y-m-d", $borrowDate);
    $validDueDate = DateTime::createFromFormat("!Y-m-d", $dueDate);

    if (
        !$validBorrowDate ||
        $validBorrowDate->format("Y-m-d") !== $borrowDate
    ) {
        $errors[] = "Please enter a valid borrowing date.";
    }

    if (
        !$validDueDate ||
        $validDueDate->format("Y-m-d") !== $dueDate
    ) {
        $errors[] = "Please enter a valid due date.";
    }

    if (
        $validBorrowDate &&
        $validDueDate &&
        $validDueDate < $validBorrowDate
    ) {
        $errors[] = "The due date cannot be earlier than the borrowing date.";
    }

    $items = [];

    if (
        !is_array($equipmentIds) ||
        !is_array($quantities) ||
        count($equipmentIds) !== count($quantities) ||
        count($equipmentIds) === 0
    ) {
        $errors[] = "Please add at least one equipment item.";
    } elseif (count($equipmentIds) > 50) {
        $errors[] = "You can add a maximum of 50 equipment rows.";
    } else {
        $seenEquipment = [];

        foreach ($equipmentIds as $index => $equipmentId) {

            $equipmentId = filter_var(
                $equipmentId,
                FILTER_VALIDATE_INT
            );

            $quantity = filter_var(
                $quantities[$index] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$equipmentId || $equipmentId < 1) {
                $errors[] = "Please select valid equipment for every row.";
                continue;
            }

            if ($quantity === false || $quantity < 1) {
                $errors[] = "Each equipment quantity must be at least 1.";
                continue;
            }

            if (isset($seenEquipment[$equipmentId])) {
                $errors[] = "Please select each equipment type only once. Combine duplicate quantities into one row.";
                continue;
            }

            $seenEquipment[$equipmentId] = true;

            $items[] = [
                "equipment_id" => $equipmentId,
                "quantity" => $quantity
            ];
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock selected equipment rows to prevent concurrent over-borrowing.
            $lockEquipment = $pdo->prepare("
                SELECT equipment_id, quantity_available
                FROM public.equipment
                WHERE equipment_id = :id
                FOR UPDATE
            ");

            foreach ($items as $item) {
                $lockEquipment->execute([
                    "id" => $item["equipment_id"]
                ]);

                $equipment = $lockEquipment->fetch();

                if (!$equipment) {
                    throw new RuntimeException(
                        "One of the selected equipment records no longer exists."
                    );
                }

                if ((int)$equipment["quantity_available"] < $item["quantity"]) {
                    throw new RuntimeException(
                        "Insufficient available quantity for equipment ID "
                        . $item["equipment_id"] . "."
                    );
                }
            }

            // Create the borrowing transaction.
            $createTransaction = $pdo->prepare("
                INSERT INTO public.borrow_transactions (
                    borrower_id,
                    borrow_date,
                    due_date,
                    status
                )
                VALUES (
                    :borrower_id,
                    :borrow_date,
                    :due_date,
                    'Borrowed'
                )
                RETURNING borrow_id
            ");

            $createTransaction->execute([
                "borrower_id" => $borrowerId,
                "borrow_date" => $borrowDate,
                "due_date" => $dueDate
            ]);

            $borrowId = $createTransaction->fetchColumn();

            // Add each equipment item and deduct inventory.
            $insertItem = $pdo->prepare("
                INSERT INTO public.borrow_items (
                    borrow_id,
                    equipment_id,
                    quantity
                )
                VALUES (
                    :borrow_id,
                    :equipment_id,
                    :quantity
                )
            ");

            $deductInventory = $pdo->prepare("
                UPDATE public.equipment
                SET quantity_available = quantity_available - :quantity
                WHERE equipment_id = :equipment_id
            ");

            foreach ($items as $item) {

                $insertItem->execute([
                    "borrow_id" => $borrowId,
                    "equipment_id" => $item["equipment_id"],
                    "quantity" => $item["quantity"]
                ]);

                $deductInventory->execute([
                    "quantity" => $item["quantity"],
                    "equipment_id" => $item["equipment_id"]
                ]);
            }

            $pdo->commit();

            header("Location: transactions.php?status=added");
            exit;

        } catch (Throwable $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($ex->getMessage());

            $errors[] = $ex instanceof RuntimeException
                ? $ex->getMessage()
                : "Unable to create the borrowing transaction. Please check your database structure and permissions.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>New Transaction | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .form-panel {
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 22px;
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
        }

        .item-row {
            display: grid;
            grid-template-columns: 1fr 150px 45px;
            gap: 12px;
            align-items: end;
            margin-bottom: 12px;
        }

        .item-row .form-control,
        .item-row .form-select {
            min-height: 44px;
        }

        .item-row label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            padding: 11px 13px;
        }

        @media(max-width: 600px) {
            .item-row {
                grid-template-columns: 1fr 90px 40px;
                gap: 7px;
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
                <h4>New Borrowing Transaction</h4>
            </div>
        </header>

        <div class="content-area">

            <div class="mb-4">
                <a href="transactions.php" class="text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Transactions
                </a>
            </div>

            <div class="welcome-section">
                <div>
                    <h2>Create Transaction</h2>
                    <p class="text-muted small">
                        Register a borrower and the equipment being borrowed.
                    </p>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>Please review the following:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="transactionForm">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION["csrf_token"]) ?>"
                >

                <section class="form-panel">

                    <h5 class="fw-bold mb-3">
                        <i class="bi bi-person me-2"></i>
                        Borrower Information
                    </h5>

                    <div class="mb-3">
                        <label class="form-label">Select Borrower</label>

                        <select name="borrower_id" class="form-select" required>
                            <option value="">Choose a borrower</option>

                            <?php foreach ($borrowers as $borrower): ?>

                                <option
                                    value="<?= (int)$borrower["borrower_id"] ?>"
                                    <?= (($_POST["borrower_id"] ?? "") == $borrower["borrower_id"]) ? "selected" : "" ?>
                                >
                                    <?= e($borrower["borrower_no"]) ?>
                                    — <?= e($borrower["full_name"]) ?>
                                    (<?= e($borrower["borrower_type"]) ?>)
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <?php if (empty($borrowers)): ?>
                            <div class="form-text text-danger">
                                No borrowers registered. Add a borrower first.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Borrow Date</label>

                            <input
                                type="date"
                                name="borrow_date"
                                class="form-control"
                                value="<?= e($borrowDate) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Due Date</label>

                            <input
                                type="date"
                                name="due_date"
                                class="form-control"
                                value="<?= e($dueDate) ?>"
                                required
                            >
                        </div>

                    </div>

                </section>

                <section class="form-panel">

                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div>
                            <h5 class="fw-bold mb-1">
                                <i class="bi bi-box-seam me-2"></i>
                                Equipment Items
                            </h5>

                            <p class="text-muted small mb-0">
                                Select the equipment and quantities to borrow.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm"
                            onclick="addItemRow()"
                        >
                            <i class="bi bi-plus-lg me-1"></i>
                            Add Item
                        </button>
                    </div>

                    <div id="equipmentRows">

                        <?php
                        $postedIds = $_POST["equipment_id"] ?? [""];
                        $postedQuantities = $_POST["quantity"] ?? [1];

                        if (!is_array($postedIds) || empty($postedIds)) {
                            $postedIds = [""];
                        }

                        if (!is_array($postedQuantities)) {
                            $postedQuantities = [1];
                        }

                        foreach ($postedIds as $index => $selectedId):
                            $selectedQuantity = $postedQuantities[$index] ?? 1;
                        ?>

                            <div class="item-row">

                                <div>
                                    <label>Equipment</label>

                                    <select
                                        name="equipment_id[]"
                                        class="form-select"
                                        required
                                    >
                                        <option value="">Select equipment</option>

                                        <?php foreach ($equipmentList as $equipment): ?>

                                            <option
                                                value="<?= (int)$equipment["equipment_id"] ?>"
                                                <?= ((string)$selectedId === (string)$equipment["equipment_id"]) ? "selected" : "" ?>
                                                <?= (int)$equipment["quantity_available"] < 1 ? "disabled" : "" ?>
                                            >
                                                <?= e($equipment["equipment_name"]) ?>
                                                (<?= e($equipment["property_no"]) ?>)
                                                — <?= (int)$equipment["quantity_available"] ?> available
                                            </option>

                                        <?php endforeach; ?>

                                    </select>
                                </div>

                                <div>
                                    <label>Quantity</label>

                                    <input
                                        type="number"
                                        name="quantity[]"
                                        class="form-control"
                                        min="1"
                                        step="1"
                                        value="<?= e($selectedQuantity) ?>"
                                        required
                                    >
                                </div>

                                <button
                                    type="button"
                                    class="btn btn-outline-danger"
                                    onclick="removeItemRow(this)"
                                    title="Remove item"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <div class="alert alert-info small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Available quantities are checked again when the transaction is submitted.
                    </div>

                </section>

                <div class="d-flex gap-2 flex-wrap mb-4">

                    <button
                        type="submit"
                        class="btn btn-primary px-4"
                        <?= empty($borrowers) || empty($equipmentList) ? "disabled" : "" ?>
                    >
                        <i class="bi bi-check-circle me-2"></i>
                        Create Transaction
                    </button>

                    <a href="transactions.php" class="btn btn-light px-4">
                        Cancel
                    </a>

                </div>

            </form>

            <footer class="footer">
                <span>Database Administration Practical System</span>
                <span>Transactions · Week 4–5</span>
            </footer>

        </div>

    </main>

</div>

<script>
const equipmentOptions = <?= json_encode(array_map(function ($item) {
    return [
        "id" => (int)$item["equipment_id"],
        "name" => $item["equipment_name"],
        "property" => $item["property_no"],
        "quantity" => (int)$item["quantity_available"]
    ];
}, $equipmentList), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function createEquipmentOptions() {
    let html = '<option value="">Select equipment</option>';

    equipmentOptions.forEach(item => {
        const disabled = item.quantity < 1 ? "disabled" : "";

        html += `
            <option value="${item.id}" ${disabled}>
                ${escapeHTML(item.name)} (${escapeHTML(item.property)})
                — ${item.quantity} available
            </option>
        `;
    });

    return html;
}

function escapeHTML(value) {
    return String(value).replace(/[&<>"']/g, character => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    })[character]);
}

function addItemRow() {
    const container = document.getElementById("equipmentRows");

    const row = document.createElement("div");
    row.className = "item-row";

    row.innerHTML = `
        <div>
            <label>Equipment</label>
            <select name="equipment_id[]" class="form-select" required>
                ${createEquipmentOptions()}
            </select>
        </div>

        <div>
            <label>Quantity</label>
            <input type="number" name="quantity[]"
                   class="form-control" min="1" step="1"
                   value="1" required>
        </div>

        <button type="button" class="btn btn-outline-danger"
                onclick="removeItemRow(this)" title="Remove item">
            <i class="bi bi-trash"></i>
        </button>
    `;

    container.appendChild(row);
}

function removeItemRow(button) {
    const container = document.getElementById("equipmentRows");

    if (container.children.length <= 1) {
        alert("At least one equipment item is required.");
        return;
    }

    button.closest(".item-row").remove();
}
</script>

</body>
</html>
