<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/excel_helpers.php';
requireLogin();
requireRole(['admin', 'receptionist']);

$headers = [
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
    'Insurance ID'
];

$sampleData = [
    [
        'John',
        'Doe',
        '1990-05-15',
        'Male',
        'O+',
        '555-0123',
        'john.doe@email.com',
        '123 Main St, City',
        'Jane Doe',
        '555-0124',
        'HealthCare Inc',
        'HC123456'
    ],
    [
        'Mary',
        'Smith',
        '1985-08-22',
        'Female',
        'A+',
        '555-0125',
        'mary.smith@email.com',
        '456 Oak Ave, Town',
        'Bob Smith',
        '555-0126',
        'MediCare Plus',
        'MC789012'
    ]
];

excelTemplate('patient_import_template', $headers, $sampleData);
