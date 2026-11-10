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

$propertyNo = "";
$equipmentName = "";
$quantity = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $errors[] = "Invalid request. Please refresh the page.";
    }

    $propertyNo = trim($_POST["property_no"] ?? "");
    $equipmentName = trim($_POST["equipment_name"] ?? "");
    $quantity = trim($_POST["quantity_available"] ?? "");

    if ($propertyNo === "") {
        $errors[] = "Property number is required.";
    }

    if ($equipmentName === "") {
        $errors[] = "Equipment name is required.";
    }

    if (
        $quantity === "" ||
        filter_var($quantity, FILTER_VALIDATE_INT) === false ||
        (int)$quantity < 0
    ) {
        $errors[] = "Quantity must be a whole number greater than or equal to zero.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO public.equipment (
                    property_no,
                    equipment_name,
                    quantity_available
                )
                VALUES (
                    :property_no,
                    :equipment_name,
                    :quantity_available
                )
            ");

            $stmt->execute([
                "property_no" => $propertyNo,
                "equipment_name" => $equipmentName,
                "quantity_available" => (int)$quantity
            ]);

            header("Location: equipment.php?status=added");
            exit;

        } catch (PDOException $ex) {
            if ($ex->getCode() === "23505") {
                $errors[] = "This property number already exists.";
            } else {
                error_log($ex->getMessage());
                $errors[] = "Unable to save equipment. Please check your database permissions and table constraints.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Equipment | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .form-card {
            max-width: 680px;
            margin: 20px auto;
            padding: 35px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0,0,0,.04);
        }

        .form-label {
            font-weight: 600;
            color: #26334d;
        }

        .form-control {
            padding: 11px 13px;
        }

        .form-icon {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            background: #e9efff;
            color: #3563e9;
            border-radius: 12px;
            font-size: 22px;
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
                <div class="breadcrumb-text">PART 1 / WEEK 4–5 / EQUIPMENT</div>
                <h4>Add Equipment</h4>
            </div>

            <div class="topbar-right">
                <span class="system-status">
                    <span class="status-dot"></span>
                    System Active
                </span>
            </div>
        </header>

        <div class="content-area">

            <div class="mb-4">
                <a href="equipment.php" class="text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Equipment Inventory
                </a>
            </div>

            <div class="form-card">

                <div class="form-icon">
                    <i class="bi bi-box-seam"></i>
                </div>

                <h3 class="fw-bold mb-2">Register New Equipment</h3>

                <p class="text-muted mb-4">
                    Enter the equipment details below to add a new inventory record.
                </p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION["csrf_token"]) ?>"
                    >

                    <div class="mb-3">
                        <label class="form-label">Property Number</label>

                        <input
                            type="text"
                            name="property_no"
                            class="form-control"
                            placeholder="e.g. EQ-2026-001"
                            value="<?= e($propertyNo) ?>"
                            required
                        >

                        <div class="form-text">
                            Enter the unique property identification number.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Equipment Name</label>

                        <input
                            type="text"
                            name="equipment_name"
                            class="form-control"
                            placeholder="e.g. Laptop, Projector, Monitor"
                            value="<?= e($equipmentName) ?>"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Available Quantity</label>

                        <input
                            type="number"
                            name="quantity_available"
                            class="form-control"
                            min="0"
                            step="1"
                            placeholder="Enter available quantity"
                            value="<?= e($quantity) ?>"
                            required
                        >

                        <div class="form-text">
                            Enter the number of available units.
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">

                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-2"></i>
                            Save Equipment
                        </button>

                        <a href="equipment.php" class="btn btn-light px-4">
                            Cancel
                        </a>

                    </div>

                </form>

            </div>

            <footer class="footer">
                <span>Database Administration Practical System</span>
                <span>Equipment Management · Week 4–5</span>
            </footer>

        </div>

    </main>

</div>

</body>
</html>
