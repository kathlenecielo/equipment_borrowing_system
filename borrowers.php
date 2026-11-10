<?php
session_start();
require_once '../includes/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_borrower'])) {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die('Invalid request. Please refresh the page.');
    }

    $borrowerId = filter_input(INPUT_POST, 'borrower_id', FILTER_VALIDATE_INT);

    if ($borrowerId) {
        try {
            $stmt = $pdo->prepare(
                "DELETE FROM public.borrowers WHERE borrower_id = :id"
            );
            $stmt->execute(['id' => $borrowerId]);

            header('Location: borrowers.php?status=deleted');
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === '23503') {
                $error = 'This borrower has existing borrowing transactions and cannot be deleted.';
            } else {
                error_log($e->getMessage());
                $error = 'Unable to delete borrower.';
            }
        }
    }
}

if (isset($_GET['status'])) {
    $messages = [
        'added' => 'Borrower successfully added.',
        'updated' => 'Borrower information successfully updated.',
        'deleted' => 'Borrower successfully deleted.'
    ];

    $success = $messages[$_GET['status']] ?? '';
}

$search = trim($_GET['search'] ?? '');

try {
    $totalBorrowers = (int)$pdo->query(
        "SELECT COUNT(*) FROM public.borrowers"
    )->fetchColumn();

    $totalStudents = (int)$pdo->query(
        "SELECT COUNT(*) FROM public.borrowers
         WHERE LOWER(borrower_type) = 'student'"
    )->fetchColumn();

    $totalFaculty = (int)$pdo->query(
        "SELECT COUNT(*) FROM public.borrowers
         WHERE LOWER(borrower_type) = 'faculty'"
    )->fetchColumn();

    $sql = "
        SELECT
            b.borrower_id,
            b.borrower_no,
            b.full_name,
            b.borrower_type,
            COUNT(bt.borrow_id) AS total_transactions
        FROM public.borrowers b
        LEFT JOIN public.borrow_transactions bt
            ON bt.borrower_id = b.borrower_id
    ";

    if ($search !== '') {
        $sql .= "
            WHERE
                b.borrower_no ILIKE :search1
                OR b.full_name ILIKE :search2
                OR b.borrower_type ILIKE :search3
        ";
    }

    $sql .= "
        GROUP BY
            b.borrower_id,
            b.borrower_no,
            b.full_name,
            b.borrower_type
        ORDER BY b.borrower_id DESC
    ";

    $stmt = $pdo->prepare($sql);

    if ($search !== '') {
        $term = "%{$search}%";
        $stmt->execute([
            'search1' => $term,
            'search2' => $term,
            'search3' => $term
        ]);
    } else {
        $stmt->execute();
    }

    $borrowers = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    die('Unable to load borrower records. Check database permissions.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Borrowers | Database Practical System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        body {
            background: #f4f6fb;
            color: #202b43;
        }

        .main-content {
            min-width: 0;
            flex: 1;
        }

        .topbar {
            min-height: 76px;
            background: #fff;
            border-bottom: 1px solid #e9edf4;
            padding: 15px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar h4 {
            margin: 0;
            color: #17264a;
            font-size: 17px;
            font-weight: 800;
        }

        .breadcrumb-text {
            font-size: 10px;
            color: #8b96aa;
            margin-bottom: 3px;
        }

        .content-area {
            max-width: 1600px;
            padding: 30px 32px;
            margin: auto;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-heading h2 {
            font-size: 23px;
            font-weight: 800;
            color: #142348;
            margin-bottom: 5px;
        }

        .page-heading p {
            color: #8490a5;
            font-size: 12px;
            margin: 0;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e9edf4;
            border-radius: 13px;
            padding: 19px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            display: grid;
            place-items: center;
            font-size: 19px;
        }

        .blue { background: #eaf0ff; color: #3563e9; }
        .purple { background: #f1eaff; color: #8053c6; }
        .cyan { background: #e5f8fc; color: #1595b1; }

        .stat-card small {
            display: block;
            color: #8490a5;
            font-size: 10px;
        }

        .stat-card strong {
            display: block;
            color: #18294f;
            font-size: 22px;
            font-weight: 800;
        }

        .panel {
            background: #fff;
            border: 1px solid #e9edf4;
            border-radius: 13px;
            padding: 22px;
        }

        .search-box {
            max-width: 350px;
        }

        .search-box input {
            border-radius: 9px;
            padding: 10px 13px;
            font-size: 12px;
        }

        .table {
            font-size: 12px;
        }

        .table thead th {
            background: #f7f9fc;
            color: #8490a5;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 13px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 13px;
            vertical-align: middle;
            color: #46546c;
        }

        .borrower-name {
            color: #1b2b50;
            font-weight: 700;
        }

        .borrower-number {
            color: #929db0;
            font-size: 10px;
            margin-top: 3px;
        }

        .type-badge {
            background: #edf3ff;
            color: #3563c9;
            border-radius: 20px;
            padding: 5px 10px;
            font-size: 10px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .action-btn {
            width: 31px;
            height: 31px;
            display: inline-grid;
            place-items: center;
            border: 1px solid #e8edf5;
            background: white;
            color: #52617b;
            border-radius: 7px;
            text-decoration: none;
        }

        .action-btn:hover {
            background: #edf3ff;
            color: #3563e9;
        }

        .empty-state {
            text-align: center;
            color: #8b96aa !important;
            padding: 35px !important;
        }

        @media(max-width: 760px) {
            .topbar { padding: 15px 18px; }
            .content-area { padding: 22px 17px; }
            .page-heading { align-items: flex-start; flex-direction: column; }
            .stat-grid { grid-template-columns: 1fr; }
            .search-box { max-width: 100%; }
        }
    </style>
</head>

<body>

<div class="app-layout">

    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <header class="topbar">
            <div>
                <div class="breadcrumb-text">PART 1 / WEEKS 4–5 / BORROWERS</div>
                <h4>Borrowers Management</h4>
            </div>
        </header>

        <div class="content-area">

            <div class="page-heading">
                <div>
                    <h2>Borrowers</h2>
                    <p>Manage registered borrowers and their information.</p>
                </div>

                <a href="add_borrower.php" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>
                    Add Borrower
                </a>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= e($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <div class="stat-grid">

                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="bi bi-people"></i>
                    </div>
                    <div>
                        <small>Total Borrowers</small>
                        <strong><?= number_format($totalBorrowers) ?></strong>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="bi bi-mortarboard"></i>
                    </div>
                    <div>
                        <small>Student Borrowers</small>
                        <strong><?= number_format($totalStudents) ?></strong>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon cyan">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div>
                        <small>Faculty Borrowers</small>
                        <strong><?= number_format($totalFaculty) ?></strong>
                    </div>
                </div>

            </div>

            <section class="panel">

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

                    <div>
                        <h5 class="fw-bold mb-1">Borrower Directory</h5>
                        <small class="text-secondary">
                            Records retrieved directly from PostgreSQL.
                        </small>
                    </div>

                    <form method="GET" class="search-box">
                        <div class="input-group">
                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Search borrower..."
                                value="<?= e($search) ?>"
                            >
                            <button class="btn btn-outline-primary" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>

                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">

                        <thead>
                            <tr>
                                <th>Borrower</th>
                                <th>Borrower Type</th>
                                <th>Transactions</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (empty($borrowers)): ?>

                                <tr>
                                    <td colspan="4" class="empty-state">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                        No borrower records found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($borrowers as $borrower): ?>

                                    <tr>

                                        <td>
                                            <div class="borrower-name">
                                                <?= e($borrower['full_name']) ?>
                                            </div>

                                            <div class="borrower-number">
                                                <?= e($borrower['borrower_no']) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="type-badge">
                                                <?= e($borrower['borrower_type']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= number_format((int)$borrower['total_transactions']) ?>
                                        </td>

                                        <td class="text-end">

                                            <a
                                                href="edit_borrower.php?id=<?= (int)$borrower['borrower_id'] ?>"
                                                class="action-btn"
                                                title="Edit borrower"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <form
                                                method="POST"
                                                class="d-inline"
                                                onsubmit="return confirm('Are you sure you want to delete this borrower? This action cannot be undone.');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_token']) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="borrower_id"
                                                    value="<?= (int)$borrower['borrower_id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    name="delete_borrower"
                                                    class="action-btn"
                                                    title="Delete borrower"
                                                >
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>
                </div>

                <div class="mt-3 text-secondary" style="font-size: 10px;">
                    Showing <?= count($borrowers) ?> borrower record(s).
                </div>

            </section>

        </div>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
