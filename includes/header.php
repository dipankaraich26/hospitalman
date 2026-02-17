<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$currentUser = getCurrentUser();
$currentPage = basename(dirname($_SERVER['SCRIPT_NAME']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?= $pageTitle ?? 'Hospital Management ERP' ?></title>

    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#1e293b">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="HospitalERP">
    <link rel="manifest" href="/hospitalman/manifest.json">
    <link rel="apple-touch-icon" href="/hospitalman/assets/icons/icon-192.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="/hospitalman/assets/css/style.css" rel="stylesheet">
    <link href="/hospitalman/assets/css/mobile.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <nav id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <i class="bi bi-hospital fs-3"></i>
            <span class="sidebar-title">HospitalERP</span>
        </div>
        <ul class="sidebar-nav">
            <?php if (canAccess('dashboard')): ?>
            <li class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <a href="/hospitalman/modules/dashboard/index.php">
                    <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (canAccess('patients')): ?>
            <li class="<?= $currentPage === 'patients' ? 'active' : '' ?>">
                <a href="/hospitalman/modules/patients/index.php">
                    <i class="bi bi-people"></i> <span>Patients</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (canAccess('clinical')): ?>
            <li class="sidebar-dropdown <?= in_array($currentPage, ['clinical']) ? 'active open' : '' ?>">
                <a href="#clinicalMenu" data-bs-toggle="collapse">
                    <i class="bi bi-clipboard2-pulse"></i> <span>Clinical</span>
                    <i class="bi bi-chevron-down dropdown-icon"></i>
                </a>
                <ul class="collapse <?= $currentPage === 'clinical' ? 'show' : '' ?>" id="clinicalMenu">
                    <li><a href="/hospitalman/modules/clinical/index.php">Appointments</a></li>
                    <li><a href="/hospitalman/modules/clinical/ai_diagnosis.php"><i class="bi bi-robot"></i> AI Diagnosis</a></li>
                    <li><a href="/hospitalman/modules/clinical/opd_calendar.php"><i class="bi bi-calendar3"></i> OPD Calendar</a></li>
                    <li><a href="/hospitalman/modules/clinical/consultation.php">Consultations</a></li>
                    <li><a href="/hospitalman/modules/clinical/lab_tests.php">Lab Tests</a></li>
                    <li><a href="/hospitalman/modules/clinical/medical_records.php">Medical Records</a></li>
                    <li><a href="/hospitalman/modules/clinical/vital_trends.php">Vital Trends</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (canAccess('billing')): ?>
            <li class="sidebar-dropdown <?= $currentPage === 'billing' ? 'active open' : '' ?>">
                <a href="#billingMenu" data-bs-toggle="collapse">
                    <i class="bi bi-receipt"></i> <span>Billing</span>
                    <i class="bi bi-chevron-down dropdown-icon"></i>
                </a>
                <ul class="collapse <?= $currentPage === 'billing' ? 'show' : '' ?>" id="billingMenu">
                    <li><a href="/hospitalman/modules/billing/index.php">Invoices</a></li>
                    <li><a href="/hospitalman/modules/billing/create_invoice.php">New Invoice</a></li>
                    <li><a href="/hospitalman/modules/billing/payments.php">Payments</a></li>
                    <li><a href="/hospitalman/modules/billing/insurance.php">Insurance Claims</a></li>
                    <li><a href="/hospitalman/modules/billing/insurance_providers.php">Insurance Providers</a></li>
                    <li><a href="/hospitalman/modules/billing/reports.php">Reports</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (canAccess('pharmacy')): ?>
            <li class="sidebar-dropdown <?= $currentPage === 'pharmacy' ? 'active open' : '' ?>">
                <a href="#pharmacyMenu" data-bs-toggle="collapse">
                    <i class="bi bi-capsule"></i> <span>Pharmacy</span>
                    <i class="bi bi-chevron-down dropdown-icon"></i>
                </a>
                <ul class="collapse <?= $currentPage === 'pharmacy' ? 'show' : '' ?>" id="pharmacyMenu">
                    <li><a href="/hospitalman/modules/pharmacy/index.php">Inventory</a></li>
                    <li><a href="/hospitalman/modules/pharmacy/add_medicine.php">Add Medicine</a></li>
                    <li><a href="/hospitalman/modules/pharmacy/dispense.php">Dispense</a></li>
                    <li><a href="/hospitalman/modules/pharmacy/purchase.php">Purchases</a></li>
                    <li><a href="/hospitalman/modules/pharmacy/reports.php">Reports</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (canAccess('reports')): ?>
            <li class="sidebar-dropdown <?= $currentPage === 'reports' ? 'active open' : '' ?>">
                <a href="#reportsMenu" data-bs-toggle="collapse">
                    <i class="bi bi-graph-up-arrow"></i> <span>Analytics</span>
                    <i class="bi bi-chevron-down dropdown-icon"></i>
                </a>
                <ul class="collapse <?= $currentPage === 'reports' ? 'show' : '' ?>" id="reportsMenu">
                    <li><a href="/hospitalman/modules/reports/index.php">Overview</a></li>
                    <li><a href="/hospitalman/modules/reports/patients.php">Patient Reports</a></li>
                    <li><a href="/hospitalman/modules/reports/financial.php">Financial Reports</a></li>
                    <li><a href="/hospitalman/modules/reports/clinical.php">Clinical Reports</a></li>
                    <li><a href="/hospitalman/modules/reports/pharmacy.php">Pharmacy Reports</a></li>
                    <li><a href="/hospitalman/modules/dashboard/predictions.php"><i class="bi bi-lightning"></i> Predictions</a></li>
                    <li><a href="/hospitalman/modules/dashboard/ai_analytics.php"><i class="bi bi-brain"></i> AI Analytics</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (canAccess('users')): ?>
            <li class="sidebar-dropdown <?= $currentPage === 'staff' ? 'active open' : '' ?>">
                <a href="#staffMenu" data-bs-toggle="collapse">
                    <i class="bi bi-people-fill"></i> <span>Staff Management</span>
                    <i class="bi bi-chevron-down dropdown-icon"></i>
                </a>
                <ul class="collapse <?= $currentPage === 'staff' ? 'show' : '' ?>" id="staffMenu">
                    <li><a href="/hospitalman/modules/auth/manage_users.php">Users & Roles</a></li>
                    <li><a href="/hospitalman/modules/staff/departments.php"><i class="bi bi-building"></i> Departments</a></li>
                    <li><a href="/hospitalman/modules/staff/doctor_departments.php">Doctor Departments</a></li>
                    <li><a href="/hospitalman/modules/staff/schedule.php">Schedules</a></li>
                    <li><a href="/hospitalman/modules/staff/leaves.php">Leave Management</a></li>
                    <li><a href="/hospitalman/modules/staff/performance.php">Performance</a></li>
                    <li><a href="/hospitalman/modules/staff/resources.php">Resources</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (canAccess('admin')): ?>
            <li class="sidebar-dropdown <?= $currentPage === 'admin' ? 'active open' : '' ?>">
                <a href="#adminMenu" data-bs-toggle="collapse">
                    <i class="bi bi-shield-lock"></i> <span>Administration</span>
                    <i class="bi bi-chevron-down dropdown-icon"></i>
                </a>
                <ul class="collapse <?= $currentPage === 'admin' ? 'show' : '' ?>" id="adminMenu">
                    <li><a href="/hospitalman/modules/admin/compliance.php"><i class="bi bi-shield-check"></i> Compliance</a></li>
                    <li><a href="/hospitalman/modules/admin/audit_logs.php">Audit Logs</a></li>
                    <li><a href="/hospitalman/modules/admin/api_keys.php">API Keys</a></li>
                </ul>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- Main Content -->
    <div id="main-content">
        <!-- Top Navbar -->
        <nav class="top-navbar">
            <button id="sidebarToggle" class="btn btn-link">
                <i class="bi bi-list fs-4"></i>
            </button>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted d-none d-md-inline">
                    <i class="bi bi-calendar3"></i> <?= date('d M Y') ?>
                </span>
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle text-dark text-decoration-none" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-inline"><?= sanitize($currentUser['full_name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?= ucfirst($currentUser['role']) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/hospitalman/modules/auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <div class="content-wrapper">
            <?php if ($success = getFlashMessage('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($error = getFlashMessage('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-circle"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Mobile Bottom Navigation -->
            <nav class="bottom-nav">
                <?php if (canAccess('dashboard')): ?>
                <a href="/hospitalman/modules/dashboard/index.php" class="bottom-nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('patients')): ?>
                <a href="/hospitalman/modules/patients/index.php" class="bottom-nav-item <?= $currentPage === 'patients' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i>
                    <span>Patients</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('clinical')): ?>
                <a href="/hospitalman/modules/clinical/index.php" class="bottom-nav-item <?= $currentPage === 'clinical' ? 'active' : '' ?>">
                    <i class="bi bi-clipboard2-pulse"></i>
                    <span>Clinical</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('pharmacy')): ?>
                <a href="/hospitalman/modules/pharmacy/index.php" class="bottom-nav-item <?= $currentPage === 'pharmacy' ? 'active' : '' ?>">
                    <i class="bi bi-capsule"></i>
                    <span>Pharmacy</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('reports')): ?>
                <a href="/hospitalman/modules/reports/index.php" class="bottom-nav-item <?= $currentPage === 'reports' ? 'active' : '' ?>">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Reports</span>
                </a>
                <?php endif; ?>
            </nav>
