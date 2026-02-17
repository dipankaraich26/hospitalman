<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
requireLogin();

$pdo = getDBConnection();
$type = $_GET['type'] ?? '';
$from = $_GET['from'] ?? date('Y-01-01');
$to = $_GET['to'] ?? date('Y-m-d');

auditLog('export', 'reports', null, null, null, ['type' => $type, 'from' => $from, 'to' => $to]);

switch ($type) {
    case 'patients':
        requireRole(['admin', 'doctor', 'nurse', 'receptionist']);
        $rows = $pdo->query("SELECT patient_id, first_name, last_name, dob, gender, blood_group, phone, email, address, emergency_contact_name, emergency_contact_phone, insurance_provider, insurance_id, created_at FROM patients ORDER BY created_at DESC")->fetchAll(PDO::FETCH_NUM);
        csvExport('patients_' . date('Y-m-d') . '.csv',
            ['Patient ID','First Name','Last Name','DOB','Gender','Blood Group','Phone','Email','Address','Emergency Contact','Emergency Phone','Insurance Provider','Insurance ID','Registered'],
            $rows
        );
        break;

    case 'financial':
        requireRole(['admin', 'receptionist']);
        $stmt = $pdo->prepare("
            SELECT py.payment_date, i.invoice_number, CONCAT(p.first_name,' ',p.last_name) as patient, py.amount, py.payment_method, py.reference_number
            FROM payments py
            JOIN invoices i ON py.invoice_id = i.id
            JOIN patients p ON i.patient_id = p.id
            WHERE py.payment_date BETWEEN ? AND ?
            ORDER BY py.payment_date DESC
        ");
        $stmt->execute([$from, $to]);
        csvExport('payments_' . $from . '_to_' . $to . '.csv',
            ['Date','Invoice','Patient','Amount','Method','Reference'],
            $stmt->fetchAll(PDO::FETCH_NUM)
        );
        break;

    case 'invoices':
        requireRole(['admin', 'receptionist']);
        $stmt = $pdo->prepare("
            SELECT i.invoice_number, CONCAT(p.first_name,' ',p.last_name) as patient, i.invoice_date, i.subtotal, i.discount_amount, i.tax_amount, i.total_amount, i.status
            FROM invoices i
            JOIN patients p ON i.patient_id = p.id
            WHERE i.invoice_date BETWEEN ? AND ?
            ORDER BY i.invoice_date DESC
        ");
        $stmt->execute([$from, $to]);
        csvExport('invoices_' . $from . '_to_' . $to . '.csv',
            ['Invoice #','Patient','Date','Subtotal','Discount','Tax','Total','Status'],
            $stmt->fetchAll(PDO::FETCH_NUM)
        );
        break;

    case 'clinical':
        requireRole(['admin', 'doctor', 'nurse']);
        $stmt = $pdo->prepare("
            SELECT c.created_at, CONCAT(p.first_name,' ',p.last_name) as patient, u.full_name as doctor, c.symptoms, c.diagnosis
            FROM consultations c
            JOIN patients p ON c.patient_id = p.id
            JOIN users u ON c.doctor_id = u.id
            WHERE c.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$from, $to]);
        csvExport('clinical_' . $from . '_to_' . $to . '.csv',
            ['Date','Patient','Doctor','Symptoms','Diagnosis'],
            $stmt->fetchAll(PDO::FETCH_NUM)
        );
        break;

    case 'pharmacy':
        requireRole(['admin', 'pharmacist']);
        $rows = $pdo->query("SELECT name, generic_name, category, batch_number, quantity_in_stock, unit_price, selling_price, expiry_date, reorder_level FROM medicines ORDER BY name")->fetchAll(PDO::FETCH_NUM);
        csvExport('pharmacy_inventory_' . date('Y-m-d') . '.csv',
            ['Name','Generic Name','Category','Batch','Stock','Buy Price','Sell Price','Expiry','Reorder Level'],
            $rows
        );
        break;

    case 'audit_logs':
        requireRole(['admin']);
        $stmt = $pdo->prepare("
            SELECT al.created_at, COALESCE(u.full_name, al.username) as user, al.action, al.module, al.record_table, al.record_id, al.ip_address
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY al.created_at DESC
        ");
        $stmt->execute([$from, $to]);
        csvExport('audit_logs_' . $from . '_to_' . $to . '.csv',
            ['Timestamp','User','Action','Module','Table','Record ID','IP Address'],
            $stmt->fetchAll(PDO::FETCH_NUM)
        );
        break;

    default:
        setFlashMessage('error', 'Unknown export type.');
        header('Location: index.php');
        exit;
}
