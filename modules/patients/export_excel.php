<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/excel_helpers.php';
requireLogin();
requireRole(['admin', 'doctor', 'nurse', 'receptionist']);

$pdo = getDBConnection();

// Get all patients
$patients = $pdo->query("
    SELECT patient_id, first_name, last_name, dob, gender, blood_group,
           phone, email, address, emergency_contact_name, emergency_contact_phone,
           insurance_provider, insurance_id, created_at
    FROM patients
    ORDER BY created_at DESC
")->fetchAll();

$headers = [
    'Patient ID',
    'First Name',
    'Last Name',
    'Date of Birth',
    'Gender',
    'Blood Group',
    'Phone',
    'Email',
    'Address',
    'Emergency Contact Name',
    'Emergency Contact Phone',
    'Insurance Provider',
    'Insurance ID',
    'Registered Date'
];

$data = [];
foreach ($patients as $p) {
    $data[] = [
        $p['patient_id'],
        $p['first_name'],
        $p['last_name'],
        $p['dob'] ?? '',
        $p['gender'],
        $p['blood_group'],
        $p['phone'] ?? '',
        $p['email'] ?? '',
        $p['address'] ?? '',
        $p['emergency_contact_name'] ?? '',
        $p['emergency_contact_phone'] ?? '',
        $p['insurance_provider'] ?? '',
        $p['insurance_id'] ?? '',
        $p['created_at']
    ];
}

excelExport('patients_export_' . date('Y-m-d'), $headers, $data);
