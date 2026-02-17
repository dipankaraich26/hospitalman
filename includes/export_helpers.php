<?php
/**
 * Export data as CSV download.
 */
function csvExport(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

/**
 * Parse uploaded CSV file and validate columns.
 * Returns ['rows' => array, 'errors' => array].
 */
function csvImport(array $file, array $requiredColumns): array {
    $errors = [];
    $rows = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['rows' => [], 'errors' => ['File upload failed.']];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return ['rows' => [], 'errors' => ['File must be a CSV file.']];
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        return ['rows' => [], 'errors' => ['Cannot read file.']];
    }

    // Read header row
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['Empty file.']];
    }
    $header = array_map('trim', $header);

    // Check required columns
    $missing = array_diff($requiredColumns, $header);
    if ($missing) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['Missing required columns: ' . implode(', ', $missing)]];
    }

    // Read data rows
    $lineNum = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $lineNum++;
        if (count($data) !== count($header)) {
            $errors[] = "Row $lineNum: Column count mismatch.";
            continue;
        }
        $row = array_combine($header, $data);
        // Validate required fields are not empty
        foreach ($requiredColumns as $col) {
            if (empty(trim($row[$col] ?? ''))) {
                $errors[] = "Row $lineNum: Missing required value for '$col'.";
                continue 2;
            }
        }
        $rows[] = $row;
    }

    fclose($handle);
    return ['rows' => $rows, 'errors' => $errors];
}
