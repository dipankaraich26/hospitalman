<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf_helpers.php';
requireLogin();
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? 0);

$inv = $pdo->prepare("SELECT i.*, p.first_name, p.last_name, p.patient_id as pid, p.phone, p.email, p.address, p.insurance_provider FROM invoices i JOIN patients p ON i.patient_id = p.id WHERE i.id = ?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) { die('Invoice not found.'); }

$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$payments = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ?");
$payments->execute([$id]);
$payments = $payments->fetchAll();
$totalPaid = array_sum(array_column($payments, 'amount'));

auditLog('export', 'billing', 'invoices', $id, null, ['format' => 'pdf']);

$itemRows = '';
foreach ($items as $item) {
    $itemRows .= '<tr>
        <td>' . htmlspecialchars($item['description']) . '</td>
        <td>' . ucfirst($item['category']) . '</td>
        <td style="text-align:center">' . $item['quantity'] . '</td>
        <td style="text-align:right">$' . number_format($item['unit_price'], 2) . '</td>
        <td style="text-align:right">$' . number_format($item['total_price'], 2) . '</td>
    </tr>';
}

$html = '<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Invoice ' . $inv['invoice_number'] . '</title>
<style>
body { font-family: Arial, sans-serif; font-size: 12px; color: #333; margin: 30px; }
h1 { color: #0d6efd; margin: 0; }
.header { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 2px solid #0d6efd; padding-bottom: 15px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
th { background: #f8f9fa; text-align: left; padding: 8px; border-bottom: 2px solid #dee2e6; font-size: 11px; text-transform: uppercase; }
td { padding: 8px; border-bottom: 1px solid #eee; }
.totals td { border: none; padding: 4px 8px; }
.totals .grand-total { font-size: 16px; font-weight: bold; color: #0d6efd; }
.status { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 11px; }
.status-paid { background: #d1e7dd; color: #0f5132; }
.status-unpaid { background: #f8d7da; color: #842029; }
.status-partial { background: #fff3cd; color: #664d03; }
</style></head><body>
<div class="header">
    <div><h1>INVOICE</h1><p style="margin:5px 0">' . $inv['invoice_number'] . '</p><p style="margin:0;color:#666">Date: ' . date('d M Y', strtotime($inv['invoice_date'])) . '</p></div>
    <div style="text-align:right"><strong>Hospital Management ERP</strong><br>Springfield Medical Center<br>123 Hospital Drive</div>
</div>
<div style="margin-bottom:20px">
    <strong>Bill To:</strong><br>
    ' . htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']) . ' (' . $inv['pid'] . ')<br>
    ' . htmlspecialchars($inv['address'] ?? '') . '<br>
    Phone: ' . htmlspecialchars($inv['phone'] ?? '') . '
</div>
<table>
    <thead><tr><th>Description</th><th>Category</th><th style="text-align:center">Qty</th><th style="text-align:right">Unit Price</th><th style="text-align:right">Total</th></tr></thead>
    <tbody>' . $itemRows . '</tbody>
</table>
<table class="totals" style="width:300px;margin-left:auto">
    <tr><td>Subtotal:</td><td style="text-align:right">$' . number_format($inv['subtotal'], 2) . '</td></tr>
    <tr><td>Discount (' . $inv['discount_percent'] . '%):</td><td style="text-align:right">-$' . number_format($inv['discount_amount'], 2) . '</td></tr>
    <tr><td>Tax:</td><td style="text-align:right">$' . number_format($inv['tax_amount'], 2) . '</td></tr>
    <tr style="border-top:2px solid #333"><td class="grand-total">Total:</td><td style="text-align:right" class="grand-total">$' . number_format($inv['total_amount'], 2) . '</td></tr>
    <tr><td>Paid:</td><td style="text-align:right;color:green">$' . number_format($totalPaid, 2) . '</td></tr>
    <tr><td><strong>Balance:</strong></td><td style="text-align:right;color:red"><strong>$' . number_format($inv['total_amount'] - $totalPaid, 2) . '</strong></td></tr>
</table>
<p>Status: <span class="status status-' . $inv['status'] . '">' . strtoupper($inv['status']) . '</span></p>
</body></html>';

generatePdfFromHtml($html, 'Invoice-' . $inv['invoice_number'] . '.pdf');
