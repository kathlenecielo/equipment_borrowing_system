<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

function escape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$search = trim($_GET['search'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');

$auditLogs = [];

$totalLogs = 0;
$totalUpdates = 0;
$totalInserts = 0;

try {

    // AUDIT LOG DIRECTORY
    $sql = "
        SELECT
            a.audit_id,
            a.payment_id,
            a.action_type,
            a.old_amount,
            a.new_amount,
            a.changed_at,
            a.note,

            p.student_id,
            p.payment_date,
            p.payment_method,

            s.student_no,
            s.student_name,
            s.program

        FROM week10_lab.payment_audit a

        LEFT JOIN week10_lab.payments p
            ON a.payment_id = p.payment_id

        LEFT JOIN week10_lab.students s
            ON p.student_id = s.student_id

        WHERE 1 = 1
    ";

    $params = [];

    // SEARCH FILTER
    if ($search !== '') {

        $sql .= "
            AND (
                CAST(a.audit_id AS TEXT) ILIKE :audit_id
                OR CAST(a.payment_id AS TEXT) ILIKE :payment_id
                OR a.action_type ILIKE :action_type
                OR COALESCE(a.note, '') ILIKE :note
                OR COALESCE(s.student_no, '') ILIKE :student_no
                OR COALESCE(s.student_name, '') ILIKE :student_name
                OR COALESCE(s.program, '') ILIKE :program
            )
        ";

        $term = '%' . $search . '%';

        $params['audit_id'] = $term;
        $params['payment_id'] = $term;
        $params['action_type'] = $term;
        $params['note'] = $term;
        $params['student_no'] = $term;
        $params['student_name'] = $term;
        $params['program'] = $term;
    }

    // ACTION FILTER
    if ($actionFilter !== '') {

        $sql .= "
            AND a.action_type = :action_filter
        ";

        $params['action_filter'] = $actionFilter;
    }

    $sql .= "
        ORDER BY a.changed_at DESC, a.audit_id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // AUDIT STATISTICS
    $statsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_logs,

            COUNT(*) FILTER (
                WHERE UPPER(action_type) = 'UPDATE'
            ) AS total_updates,

            COUNT(*) FILTER (
                WHERE UPPER(action_type) IN ('INSERT', 'CREATE')
            ) AS total_inserts

        FROM week10_lab.payment_audit
    ");

    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $totalLogs = (int)$stats['total_logs'];
    $totalUpdates = (int)$stats['total_updates'];
    $totalInserts = (int)$stats['total_inserts'];

    // AVAILABLE ACTION TYPES
    $actionStmt = $pdo->query("
        SELECT DISTINCT action_type
        FROM week10_lab.payment_audit
        ORDER BY action_type ASC
    ");

    $actionTypes = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {

    error_log($e->getMessage());

    die('Unable to load audit logs. Please check your Week 10 database tables and permissions.');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Audit Logs | CampusCore</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        body {
            background: #f4f6fb;
            font-family: Arial, sans-serif;
            color: #202b45;
        }

        .main-content {
            margin-left: 260px;
            padding: 35px;
            min-height: 100vh;
        }

        .page-title {
            color: #101b3d;
            font-weight: 700;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e9edf5;
            border-radius: 14px;
            padding: 22px;
            height: 100%;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #edf2ff;
            color: #263e83;
            font-size: 21px;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 700;
            color: #172858;
        }

        .content-card {
            background: #fff;
            border: 1px solid #e9edf5;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(16, 27, 61, .03);
        }

        .table thead th {
            background: #f7f8fc;
            color: #65708a;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: 15px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            color: #34415e;
        }

        .search-input,
        .filter-select {
            border-radius: 9px;
            padding: 10px 13px;
        }

        .search-input {
            min-width: 230px;
        }

        .filter-select {
            min-width: 145px;
        }

        .audit-id {
            font-weight: 700;
            color: #172858;
        }

        .student-name {
            font-weight: 600;
            color: #172858;
        }

        .amount-value {
            font-weight: 600;
            white-space: nowrap;
        }

        .action-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .action-update {
            background: #fff3dc;
            color: #a76b13;
        }

        .action-insert {
            background: #e5f7ee;
            color: #16845b;
        }

        .action-other {
            background: #eef0f5;
            color: #69758d;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
        }

        .empty-icon {
            font-size: 45px;
            color: #aab6d2;
        }

        .note-cell {
            min-width: 200px;
            max-width: 300px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        @media (max-width: 768px) {

            .main-content {
                margin-left: 0;
                padding: 20px 12px;
            }

            .content-card {
                padding: 15px;
            }

            .stat-number {
                font-size: 23px;
            }

            .search-input {
                min-width: 0;
                width: 100%;
            }

        }

    </style>

</head>

<body>

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">

        <!-- HEADER -->

        <div class="mb-4">

            <h2 class="page-title mb-1">
                Audit Logs
            </h2>

            <p class="text-secondary mb-0">
                Review payment activities and maintain a history of financial changes.
            </p>

        </div>

        <!-- STATISTICS -->

        <div class="row g-3 mb-4">

            <div class="col-md-4">

                <div class="stat-card">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <p class="text-secondary mb-1">
                                Total Audit Records
                            </p>

                            <div class="stat-number">
                                <?= number_format($totalLogs) ?>
                            </div>

                        </div>

                        <div class="stat-icon">
                            <i class="bi bi-journal-text"></i>
                        </div>

                    </div>

                    <small class="text-secondary">
                        All recorded payment activities
                    </small>

                </div>

            </div>

            <div class="col-md-4">

                <div class="stat-card">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <p class="text-secondary mb-1">
                                Payment Updates
                            </p>

                            <div class="stat-number">
                                <?= number_format($totalUpdates) ?>
                            </div>

                        </div>

                        <div class="stat-icon">
                            <i class="bi bi-pencil-square"></i>
                        </div>

                    </div>

                    <small class="text-secondary">
                        Recorded payment modifications
                    </small>

                </div>

            </div>

            <div class="col-md-4">

                <div class="stat-card">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <p class="text-secondary mb-1">
                                New Payment Records
                            </p>

                            <div class="stat-number">
                                <?= number_format($totalInserts) ?>
                            </div>

                        </div>

                        <div class="stat-icon">
                            <i class="bi bi-plus-circle"></i>
                        </div>

                    </div>

                    <small class="text-secondary">
                        Recorded payment insertions
                    </small>

                </div>

            </div>

        </div>

        <!-- AUDIT DIRECTORY -->

        <div class="content-card">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                <div>

                    <h5 class="fw-bold mb-1">
                        Activity History
                    </h5>

                    <p class="text-secondary small mb-0">
                        Review previous and updated payment information.
                    </p>

                </div>

                <!-- SEARCH AND FILTER -->

                <form method="GET" class="d-flex flex-wrap gap-2">

                    <input
                        type="search"
                        name="search"
                        class="form-control search-input"
                        placeholder="Search audit logs..."
                        value="<?= escape($search) ?>"
                    >

                    <select
                        name="action"
                        class="form-select filter-select"
                    >

                        <option value="">All Actions</option>

                        <?php foreach ($actionTypes as $type): ?>

                            <option
                                value="<?= escape($type) ?>"
                                <?= $actionFilter === $type ? 'selected' : '' ?>
                            >
                                <?= escape($type) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <button
                        type="submit"
                        class="btn btn-outline-primary"
                        title="Apply filters"
                    >
                        <i class="bi bi-search"></i>
                    </button>

                    <?php if ($search !== '' || $actionFilter !== ''): ?>

                        <a
                            href="audit_logs.php"
                            class="btn btn-outline-secondary"
                            title="Clear filters"
                        >
                            <i class="bi bi-x-lg"></i>
                        </a>

                    <?php endif; ?>

                </form>

            </div>

            <!-- TABLE -->

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead>

                        <tr>

                            <th>Audit ID</th>
                            <th>Payment ID</th>
                            <th>Student</th>
                            <th>Action</th>
                            <th>Previous Amount</th>
                            <th>New Amount</th>
                            <th>Date and Time</th>
                            <th>Notes</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (count($auditLogs) > 0): ?>

                            <?php foreach ($auditLogs as $log): ?>

                                <?php

                                $action = strtoupper(trim($log['action_type'] ?? ''));

                                if ($action === 'UPDATE') {
                                    $actionClass = 'action-update';
                                } elseif (in_array($action, ['INSERT', 'CREATE'], true)) {
                                    $actionClass = 'action-insert';
                                } else {
                                    $actionClass = 'action-other';
                                }

                                ?>

                                <tr>

                                    <!-- AUDIT ID -->

                                    <td>

                                        <span class="audit-id">
                                            #<?= (int)$log['audit_id'] ?>
                                        </span>

                                    </td>

                                    <!-- PAYMENT ID -->

                                    <td>

                                        <?php if ($log['payment_id'] !== null): ?>

                                            <span class="fw-semibold">
                                                #<?= (int)$log['payment_id'] ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="text-secondary">
                                                N/A
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- STUDENT -->

                                    <td>

                                        <?php if (!empty($log['student_name'])): ?>

                                            <div class="student-name">
                                                <?= escape($log['student_name']) ?>
                                            </div>

                                            <small class="text-secondary">
                                                <?= escape($log['student_no']) ?>
                                            </small>

                                        <?php else: ?>

                                            <span class="text-secondary">
                                                Unavailable
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- ACTION -->

                                    <td>

                                        <span class="action-badge <?= escape($actionClass) ?>">
                                            <?= escape($log['action_type']) ?>
                                        </span>

                                    </td>

                                    <!-- OLD AMOUNT -->

                                    <td>

                                        <span class="amount-value">

                                            <?php if ($log['old_amount'] !== null): ?>

                                                ₱<?= number_format((float)$log['old_amount'], 2) ?>

                                            <?php else: ?>

                                                <span class="text-secondary">—</span>

                                            <?php endif; ?>

                                        </span>

                                    </td>

                                    <!-- NEW AMOUNT -->

                                    <td>

                                        <span class="amount-value">

                                            <?php if ($log['new_amount'] !== null): ?>

                                                ₱<?= number_format((float)$log['new_amount'], 2) ?>

                                            <?php else: ?>

                                                <span class="text-secondary">—</span>

                                            <?php endif; ?>

                                        </span>

                                    </td>

                                    <!-- TIMESTAMP -->

                                    <td>

                                        <?php if (!empty($log['changed_at'])): ?>

                                            <div class="fw-semibold">
                                                <?= escape(date(
                                                    'M d, Y',
                                                    strtotime($log['changed_at'])
                                                )) ?>
                                            </div>

                                            <small class="text-secondary">
                                                <?= escape(date(
                                                    'h:i A',
                                                    strtotime($log['changed_at'])
                                                )) ?>
                                            </small>

                                        <?php else: ?>

                                            <span class="text-secondary">N/A</span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- NOTES -->

                                    <td class="note-cell">

                                        <?php if (!empty($log['note'])): ?>

                                            <span class="small">
                                                <?= escape($log['note']) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="text-secondary">
                                                No additional notes
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="8">

                                    <div class="empty-state">

                                        <div class="empty-icon mb-3">
                                            <i class="bi bi-journal-x"></i>
                                        </div>

                                        <h5 class="fw-bold">
                                            No Audit Records Found
                                        </h5>

                                        <p class="text-secondary mb-0">

                                            <?= ($search !== '' || $actionFilter !== '')
                                                ? 'No audit records matched your search or filter.'
                                                : 'There are currently no recorded audit activities.' ?>

                                        </p>

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <!-- FOOTER -->

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">

                <small class="text-secondary">
                    Showing <?= count($auditLogs) ?> audit record(s)
                </small>

                <small class="text-secondary">
                    Financial activity history
                </small>

            </div>

        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>
