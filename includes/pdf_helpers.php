<?php
/**
 * Generate a PDF from HTML and stream it to the browser.
 * Falls back to a print-friendly HTML page if DomPDF is not installed.
 */
function generatePdfFromHtml(string $html, string $filename): void {
    $dompdfPath = __DIR__ . '/../vendor/dompdf/autoload.inc.php';
    if (file_exists($dompdfPath)) {
        require_once $dompdfPath;
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    // Fallback: deliver as print-friendly HTML
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    echo '<script>window.print();</script>';
    exit;
}
