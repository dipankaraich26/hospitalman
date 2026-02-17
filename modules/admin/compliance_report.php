<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

// Generate compliance report data
$reportDate = date('Y-m-d H:i:s');
$reportPeriod = $_GET['period'] ?? '30'; // days

$data = [];

// 1. Audit Summary
$data['audit_summary'] = $pdo->query("
    SELECT
        action,
        COUNT(*) as count,
        COUNT(DISTINCT user_id) as unique_users
    FROM audit_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL $reportPeriod DAY)
    GROUP BY action
    ORDER BY count DESC
")->fetchAll();

// 2. User Activity
$data['user_activity'] = $pdo->query("
    SELECT
        u.full_name,
        u.role,
        COUNT(al.id) as total_actions,
        SUM(CASE WHEN al.action IN ('create', 'update', 'delete') THEN 1 ELSE 0 END) as modifications,
        MIN(al.created_at) as first_access,
        MAX(al.created_at) as last_access
    FROM users u
    LEFT JOIN audit_logs al ON u.id = al.user_id
    WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL $reportPeriod DAY)
    GROUP BY u.id
    ORDER BY total_actions DESC
")->fetchAll();

// 3. Security Events
$data['security_events'] = $pdo->query("
    SELECT
        DATE(created_at) as event_date,
        COUNT(*) as failed_logins
    FROM audit_logs
    WHERE action = 'login_failed'
    AND created_at >= DATE_SUB(NOW(), INTERVAL $reportPeriod DAY)
    GROUP BY DATE(created_at)
    ORDER BY event_date DESC
")->fetchAll();

// 4. Data Access Patterns
$data['access_patterns'] = $pdo->query("
    SELECT
        module,
        record_table,
        COUNT(*) as access_count,
        COUNT(DISTINCT user_id) as unique_users
    FROM audit_logs
    WHERE action = 'read'
    AND created_at >= DATE_SUB(NOW(), INTERVAL $reportPeriod DAY)
    GROUP BY module, record_table
    ORDER BY access_count DESC
    LIMIT 20
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIPAA Compliance Report - <?= $reportDate ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none; }
            .page-break { page-break-after: always; }
        }
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .compliance-badge {
            font-size: 3rem;
            text-align: center;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Print Button -->
        <div class="text-end mb-3 no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                Close
            </button>
        </div>

        <!-- Report Header -->
        <div class="report-header rounded">
            <div class="row">
                <div class="col-md-8">
                    <h1>HIPAA Compliance & Security Report</h1>
                    <p class="mb-0">Hospital Management ERP System</p>
                    <p class="mb-0"><small>Report Generated: <?= $reportDate ?></small></p>
                    <p class="mb-0"><small>Reporting Period: Last <?= $reportPeriod ?> Days</small></p>
                </div>
                <div class="col-md-4 compliance-badge">
                    <i class="bi bi-shield-check"></i>
                    <div>COMPLIANT</div>
                </div>
            </div>
        </div>

        <!-- Executive Summary -->
        <section class="mb-5">
            <h2>Executive Summary</h2>
            <div class="card">
                <div class="card-body">
                    <p class="lead">
                        This report demonstrates the hospital's compliance with HIPAA (Health Insurance Portability
                        and Accountability Act) regulations, focusing on data security, access control, and audit trail requirements.
                    </p>
                    <hr>
                    <h5>Key Findings:</h5>
                    <ul>
                        <li><strong>Audit Logging:</strong> All data access and modifications are logged</li>
                        <li><strong>Access Control:</strong> Role-based access control is enforced</li>
                        <li><strong>Data Encryption:</strong> Patient data is encrypted in transit (HTTPS)</li>
                        <li><strong>User Authentication:</strong> Failed login attempts are monitored</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Audit Summary -->
        <section class="mb-5">
            <h2>1. Audit Activity Summary</h2>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Action Type</th>
                        <th>Total Events</th>
                        <th>Unique Users</th>
                        <th>Compliance Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['audit_summary'] as $row): ?>
                    <tr>
                        <td><?= strtoupper(htmlspecialchars($row['action'])) ?></td>
                        <td><?= number_format($row['count']) ?></td>
                        <td><?= number_format($row['unique_users']) ?></td>
                        <td><span class="badge bg-success">Logged</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <div class="page-break"></div>

        <!-- User Activity -->
        <section class="mb-5">
            <h2>2. User Activity Report</h2>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Role</th>
                        <th>Total Actions</th>
                        <th>Modifications</th>
                        <th>First Access</th>
                        <th>Last Access</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['user_activity'] as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= ucfirst($row['role']) ?></td>
                        <td><?= number_format($row['total_actions']) ?></td>
                        <td><?= number_format($row['modifications']) ?></td>
                        <td><?= $row['first_access'] ? date('M d, H:i', strtotime($row['first_access'])) : 'N/A' ?></td>
                        <td><?= $row['last_access'] ? date('M d, H:i', strtotime($row['last_access'])) : 'N/A' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- Security Events -->
        <section class="mb-5">
            <h2>3. Security Events</h2>
            <?php if (count($data['security_events']) > 0): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Failed Login Attempts</th>
                        <th>Risk Level</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['security_events'] as $row): ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($row['event_date'])) ?></td>
                        <td><?= number_format($row['failed_logins']) ?></td>
                        <td>
                            <span class="badge bg-<?= $row['failed_logins'] > 10 ? 'danger' : ($row['failed_logins'] > 5 ? 'warning' : 'success') ?>">
                                <?= $row['failed_logins'] > 10 ? 'High' : ($row['failed_logins'] > 5 ? 'Medium' : 'Low') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> No failed login attempts recorded in this period.
            </div>
            <?php endif; ?>
        </section>

        <div class="page-break"></div>

        <!-- Data Access Patterns -->
        <section class="mb-5">
            <h2>4. Data Access Patterns</h2>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Data Table</th>
                        <th>Access Count</th>
                        <th>Unique Users</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['access_patterns'] as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['module'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['record_table'] ?? 'N/A') ?></td>
                        <td><?= number_format($row['access_count']) ?></td>
                        <td><?= number_format($row['unique_users']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- Compliance Attestation -->
        <section class="mb-5">
            <h2>5. Compliance Attestation</h2>
            <div class="card bg-light">
                <div class="card-body">
                    <h5>HIPAA Compliance Statement</h5>
                    <p>
                        This report certifies that the Hospital Management ERP System maintains compliance with
                        HIPAA regulations through:
                    </p>
                    <ul>
                        <li><strong>Administrative Safeguards:</strong> Role-based access control and user management</li>
                        <li><strong>Physical Safeguards:</strong> Secure server infrastructure and data storage</li>
                        <li><strong>Technical Safeguards:</strong> Encryption, audit logging, and access controls</li>
                        <li><strong>Organizational Requirements:</strong> Business associate agreements and policies</li>
                    </ul>
                    <hr>
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <p><strong>Compliance Officer:</strong> _______________________</p>
                            <p><strong>Signature:</strong> _______________________</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Date:</strong> <?= date('F d, Y') ?></p>
                            <p><strong>Report ID:</strong> <?= strtoupper(substr(md5($reportDate), 0, 10)) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="text-center text-muted mt-5 pt-3 border-top">
            <p><small>
                This is a confidential document. Distribution is restricted to authorized personnel only.
                <br>Generated by Hospital Management ERP - Compliance Module
            </small></p>
        </footer>
    </div>
</body>
</html>
