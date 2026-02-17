<?php
/**
 * Excel Export/Import Helpers using PhpSpreadsheet
 * Falls back to CSV if PhpSpreadsheet is not available
 */

/**
 * Export data to Excel (.xlsx) format
 * @param string $filename The filename for download
 * @param array $headers Column headers
 * @param array $data Data rows (array of arrays)
 */
function excelExport(string $filename, array $headers, array $data): void {
    // Check if PhpSpreadsheet is available
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        excelExportWithLibrary($filename, $headers, $data);
    } else {
        // Fallback to CSV export
        csvExport($filename . '.csv', $headers, $data);
    }
}

/**
 * Export using PhpSpreadsheet library
 */
function excelExportWithLibrary(string $filename, array $headers, array $data): void {
    require_once __DIR__ . '/../vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set headers
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $sheet->getStyle($col . '1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('4472C4');
        $sheet->getStyle($col . '1')->getFont()->getColor()->setRGB('FFFFFF');
        $col++;
    }

    // Set data
    $row = 2;
    foreach ($data as $dataRow) {
        $col = 'A';
        foreach ($dataRow as $value) {
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }

    // Auto-size columns
    foreach (range('A', $col) as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Generate Excel template for import
 * @param string $filename Template filename
 * @param array $headers Column headers
 * @param array $sampleData Optional sample data rows
 */
function excelTemplate(string $filename, array $headers, array $sampleData = []): void {
    // Check if PhpSpreadsheet is available
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        excelTemplateWithLibrary($filename, $headers, $sampleData);
    } else {
        // Fallback to CSV template
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        foreach ($sampleData as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}

/**
 * Generate Excel template using PhpSpreadsheet
 */
function excelTemplateWithLibrary(string $filename, array $headers, array $sampleData): void {
    require_once __DIR__ . '/../vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set headers with styling
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $sheet->getStyle($col . '1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('4472C4');
        $sheet->getStyle($col . '1')->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($col . '1')->getAlignment()->setHorizontal('center');
        $col++;
    }

    // Add sample data if provided
    $row = 2;
    foreach ($sampleData as $dataRow) {
        $col = 'A';
        foreach ($dataRow as $value) {
            $sheet->setCellValue($col . $row, $value);
            $sheet->getStyle($col . $row)->getFont()->setItalic(true);
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('999999');
            $col++;
        }
        $row++;
    }

    // Auto-size columns
    foreach (range('A', chr(ord('A') + count($headers) - 1)) as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Add instructions sheet
    $instructionSheet = $spreadsheet->createSheet(1);
    $instructionSheet->setTitle('Instructions');
    $instructionSheet->setCellValue('A1', 'Import Instructions');
    $instructionSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $instructionSheet->setCellValue('A3', '1. Fill in the data in the first sheet');
    $instructionSheet->setCellValue('A4', '2. Do not modify the header row');
    $instructionSheet->setCellValue('A5', '3. Sample data is shown in gray (delete before importing)');
    $instructionSheet->setCellValue('A6', '4. Save and upload the file');

    // Set active sheet back to data sheet
    $spreadsheet->setActiveSheetIndex(0);

    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Import data from Excel file
 * @param string $filePath Path to uploaded file
 * @return array Array of rows (each row is associative array with headers as keys)
 */
function excelImport(string $filePath): array {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // Check if PhpSpreadsheet is available
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet') && in_array($extension, ['xlsx', 'xls'])) {
        return excelImportWithLibrary($filePath);
    } elseif ($extension === 'csv') {
        // Use CSV import
        require_once __DIR__ . '/export_helpers.php';
        return csvImport($filePath);
    } else {
        throw new Exception('Unsupported file format. Please upload .xlsx, .xls, or .csv file.');
    }
}

/**
 * Import Excel file using PhpSpreadsheet
 */
function excelImportWithLibrary(string $filePath): array {
    require_once __DIR__ . '/../vendor/autoload.php';

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray();

    if (empty($data)) {
        throw new Exception('The Excel file is empty.');
    }

    $headers = array_shift($data); // First row as headers
    $result = [];

    foreach ($data as $rowIndex => $row) {
        // Skip empty rows
        if (empty(array_filter($row))) {
            continue;
        }

        $rowData = [];
        foreach ($headers as $colIndex => $header) {
            $rowData[$header] = $row[$colIndex] ?? '';
        }
        $result[] = $rowData;
    }

    return $result;
}
