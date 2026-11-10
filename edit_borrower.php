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

if (!$id || $id <= 0) {
    die("Invalid borrower ID.");
}

$stmt = $pdo->prepare("
    SELECT borrower_id, borrower_no, full_name, borrower_type
    FROM public.borrowers
    WHERE borrower_id = :id
");

$stmt->execute(["id" => $id]);
$borrower = $stmt->fetch();

if (!$borrower) {
    die("Borrower not found.");
}

$errors = [];

$borrowerNo = $borrower["borrower_no"];
$fullName = $borrower["full_name"];
$borrowerType = $borrower["borrower_type"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $errors[] = "Invalid request. Please refresh the page.";
    }

    $borrowerNo = trim($_POST["borrower_no"] ?? "");
    $fullName = trim($_POST["full_name"] ?? "");
    $borrowerType = trim($_POST["borrower_type"] ?? "");

    if ($borrowerNo === "") {
        $errors[] = "Borrower number is required.";
    }

    if ($fullName === "") {
        $errors[] = "Full name is required.";
    }

    if (!in_array($borrowerType, ["Student", "Faculty", "Staff"], true)) {
        $errors[] = "Please select a valid borrower type.";
    }

    if (empty($errors)) {
        try {
            $update = $pdo->prepare("
                UPDATE public.borrowers
                SET
                    borrower_no = :borrower_no,
                    full_name = :full_name,
                    borrower_type = :borrower_type
                WHERE borrower_id = :id
            ");

            $update->execute([
                "borrower_no" => $borrowerNo,
                "full_name" => $fullName,
                "borrower_type" => $borrowerType,
                "id" => $id
            ]);

            header("Location: borrowers.php?status=updated");
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === "23505") {
                $errors[] = "That borrower number already exists.";
            } else {
                error_log($e->getMessage());
                $errors[] = "Unable to update borrower. Please check the database connection and permissions.";
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
    <title>Edit Borrower | DB Practical</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f6fb;
            font-family: Arial, sans-serif;
        }

        .form-card {
            max-width: 650px;
            margin: 55px auto;
            padding: 35px;
            background: white;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0,0,0,.06);
        }

        .form-label {
            font-weight: 600;
            color: #26334d;
        }

        .btn-primary {
            background: #3563e9;
            border: none;
        }

        .btn-primary:hover {
            background: #244dcc;
        }
    </style>
</head>

<body>

<div class="container">
    <div class="form-card">

        <h3 class="fw-bold mb-2">Edit Borrower</h3>
        <p class="text-muted mb-4">
            Update the borrower's information.
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

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e($_SESSION["csrf_token"]) ?>">

            <div class="mb-3">
                <label class="form-label">Borrower Number</label>
                <input
                    type="text"
                    name="borrower_no"
                    class="form-control"
                    value="<?= e($borrowerNo) ?>"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input
                    type="text"
                    name="full_name"
                    class="form-control"
                    value="<?= e($fullName) ?>"
                    required
                >
            </div>

            <div class="mb-4">
                <label class="form-label">Borrower Type</label>

                <select name="borrower_type" class="form-select" required>
                    <option value="Student"
                        <?= $borrowerType === "Student" ? "selected" : "" ?>>
                        Student
                    </option>

                    <option value="Faculty"
                        <?= $borrowerType === "Faculty" ? "selected" : "" ?>>
                        Faculty
                    </option>

                    <option value="Staff"
                        <?= $borrowerType === "Staff" ? "selected" : "" ?>>
                        Staff
                    </option>
                </select>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    Save Changes
                </button>

                <a href="borrowers.php" class="btn btn-light px-4">
                    Cancel
                </a>
            </div>

        </form>

    </div>
</div>

</body>
</html>
