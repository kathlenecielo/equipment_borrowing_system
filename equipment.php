<?php
session_start();
require_once "../includes/db.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$status = $_GET["status"] ?? "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete") {
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $id = filter_var($_POST["equipment_id"] ?? null, FILTER_VALIDATE_INT);

        if (!$id || $id <= 0) {
            $error = "Invalid equipment ID.";
        } else {
            try {
                $delete = $pdo->prepare("
                    DELETE FROM public.equipment
                    WHERE equipment_id = :id
                ");
                $delete->execute(["id" => $id]);

                header("Location: equipment.php?status=deleted");
                exit;
            } catch (PDOException $ex) {
                if ($ex->getCode() === "23503") {
                    $error = "This equipment is linked to a borrowing transaction and cannot be deleted.";
                } else {
                    error_log($ex->getMessage());
                    $error = "Unable to delete equipment.";
                }
            }
        }
    }
}

$search = trim($_GET["search"] ?? "");

$statsQuery = $pdo->query("
    SELECT
        COUNT(*) AS total_records,
        COALESCE(SUM(quantity_available), 0) AS total_quantity
    FROM public.equipment
");

$stats = $statsQuery->fetch();

$sql = "
    SELECT
        equipment_id,
        property_no,
        equipment_name,
        quantity_available
    FROM public.equipment
";

$params = [];

if ($search !== "") {
    $sql .= "
        WHERE property_no ILIKE :property_no
        OR equipment_name ILIKE :equipment_name
    ";

    $params = [
        "property_no" => "%$search%",
        "equipment_name" => "%$search%"
    ];
}

$sql .= " ORDER BY equipment_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$equipmentList = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Equipment Inventory | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .equipment-stat {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 23px;
            height: 100%;
        }

        .equipment-stat h3 {
            font-size: 30px;
            font-weight: 800;
            margin: 8px 0 3px;
        }

        .equipment-stat p {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
        }

        .equipment-table {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
        }

        .search-box {
            max-width: 350px;
        }

        .page-description {
            color: var(--muted);
            font-size: 13px;
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
                <div class="breadcrumb-text">PART 1 / WEEK 4–5</div>
                <h4>Equipment Inventory</h4>
            </div>

            <div class="topbar-right">
                <span class="system-status">
                    <span class="status-dot"></span>
                    System Active
                </span>
            </div>
        </header>

        <div class="content-area">

            <div class="welcome-section">
                <div>
                    <h2>Equipment Management</h2>
                    <p class="page-description">
                        Manage your equipment records and available quantities.
                    </p>
                </div>

                <a href="add_equipment.php" class="btn btn-primary px-4">
                    <i class="bi bi-plus-lg me-2"></i>
                    Add Equipment
                </a>
            </div>

            <?php if ($status === "added"): ?>
                <div class="alert alert-success">
                    Equipment successfully added.
                </div>
            <?php elseif ($status === "updated"): ?>
                <div class="alert alert-success">
                    Equipment successfully updated.
                </div>
            <?php elseif ($status === "deleted"): ?>
                <div class="alert alert-success">
                    Equipment successfully deleted.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">

                <div class="col-md-6">
                    <div class="equipment-stat">
                        <span class="text-muted small">Equipment Records</span>
                        <h3><?= number_format($stats["total_records"]) ?></h3>
                        <p>Registered equipment types</p>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="equipment-stat">
                        <span class="text-muted small">Available Quantity</span>
                        <h3><?= number_format($stats["total_quantity"]) ?></h3>
                        <p>Total available units</p>
                    </div>
                </div>

            </div>

            <section class="equipment-table">

                <div class="panel-header">
                    <div>
                        <h5>Equipment List</h5>
                        <p>View and manage registered equipment.</p>
                    </div>

                    <form method="GET" class="d-flex gap-2 search-box">
                        <input
                            type="search"
                            name="search"
                            class="form-control"
                            placeholder="Search equipment..."
                            value="<?= e($search) ?>"
                        >

                        <button type="submit" class="btn btn-dark">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                </div>

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>
                            <tr>
                                <th>Property No.</th>
                                <th>Equipment Name</th>
                                <th>Available Quantity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (empty($equipmentList)): ?>

                            <tr>
                                <td colspan="4" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    No equipment records found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($equipmentList as $item): ?>

                                <tr>
                                    <td>
                                        <strong><?= e($item["property_no"]) ?></strong>
                                    </td>

                                    <td>
                                        <?= e($item["equipment_name"]) ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-primary-subtle text-primary">
                                            <?= number_format($item["quantity_available"]) ?> units
                                        </span>
                                    </td>

                                    <td>
                                        <div class="d-flex gap-2">

                                            <a
                                                href="edit_equipment.php?id=<?= (int)$item["equipment_id"] ?>"
                                                class="btn btn-sm btn-outline-primary"
                                                title="Edit"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Are you sure you want to delete this equipment?');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION["csrf_token"]) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="equipment_id"
                                                    value="<?= (int)$item["equipment_id"] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Delete"
                                                >
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>

                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <div class="text-muted small mt-3">
                    Showing <?= count($equipmentList) ?> equipment record(s).
                </div>

            </section>

            <footer class="footer">
                <span>Database Administration Practical System</span>
                <span>Equipment Management · Week 4–5</span>
            </footer>

        </div>

    </main>

</div>

</body>
</html>
