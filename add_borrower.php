<?php
session_start();
require_once '../includes/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$errors = [];

$borrowerNo = '';
$fullName = '';
$borrowerType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die('Invalid request. Please refresh the page.');
    }

    $borrowerNo = trim($_POST['borrower_no'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $borrowerType = trim($_POST['borrower_type'] ?? '');

    if ($borrowerNo === '') {
        $errors[] = 'Borrower number is required.';
    }

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }

    if (!in_array($borrowerType, ['Student', 'Faculty', 'Staff'], true)) {
        $errors[] = 'Please select a valid borrower type.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO public.borrowers (
                    borrower_no,
                    full_name,
                    borrower_type
                )
                VALUES (
                    :borrower_no,
                    :full_name,
                    :borrower_type
                )
            ");

            $stmt->execute([
                'borrower_no' => $borrowerNo,
                'full_name' => $fullName,
                'borrower_type' => $borrowerType
            ]);

            header('Location: borrowers.php?status=added');
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                $errors[] = 'This borrower number already exists.';
            } else {
                error_log($e->getMessage());
                $errors[] = 'Unable to save borrower. Please try again.';
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

    <title>Add Borrower | Database Practical System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        body { background: #f4f6fb; }
        .main-content { flex: 1; min-width: 0; }
        .topbar {
            background: white;
            border-bottom: 1px solid #e9edf4;
            padding: 18px 30px;
        }
        .content-area { max-width: 1000px; padding: 30px; margin: auto; }
        .form-panel {
            background: white;
            padding: 30px;
            border: 1px solid #e9edf4;
            border-radius: 14px;
        }
        .form-label { font-size: 12px; font-weight: 700; color: #33415d; }
        .form-control, .form-select { padding: 11px 13px; font-size: 13px; }
        @media(max-width: 760px) {
            .content-area { padding: 18px; }
            .form-panel { padding: 20px; }
        }
    </style>
</head>

<body>

<div class="app-layout">

    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <header class="topbar">
            <a href="borrowers.php" class="text-decoration-none small">
                <i class="bi bi-arrow-left me-1"></i> Back to Borrowers
            </a>
        </header>

        <div class="content-area">

            <div class="mb-4">
                <h2 class="fw-bold">Add New Borrower</h2>
                <p class="text-secondary small">
                    Enter the borrower's information below.
                </p>
            </div>

            <div class="form-panel">

                <div class="d-flex align-items-center gap-2 mb-4">
                    <i class="bi bi-person-plus fs-4 text-primary"></i>
                    <h5 class="fw-bold mb-0">Borrower Information</h5>
                </div>

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
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >

                    <div class="mb-3">
                        <label class="form-label">Borrower Number</label>
                        <input
                            type="text"
                            name="borrower_no"
                            class="form-control"
                            placeholder="e.g. BRW-2026-001"
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
                            placeholder="Enter complete name"
                            value="<?= e($fullName) ?>"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Borrower Type</label>
                        <select name="borrower_type" class="form-select" required>
                            <option value="">Select borrower type</option>

                            <option value="Student" <?= $borrowerType === 'Student' ? 'selected' : '' ?>>
                                Student
                            </option>

                            <option value="Faculty" <?= $borrowerType === 'Faculty' ? 'selected' : '' ?>>
                                Faculty
                            </option>

                            <option value="Staff" <?= $borrowerType === 'Staff' ? 'selected' : '' ?>>
                                Staff
                            </option>
                        </select>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Borrower
                        </button>

                        <a href="borrowers.php" class="btn btn-light px-4">
                            Cancel
                        </a>
                    </div>

                </form>

            </div>
        </div>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
