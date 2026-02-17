# Excel Import/Export Setup

The system supports Excel (.xlsx, .xls) and CSV file formats for patient import/export.

## Default Behavior (No Installation Required)

By default, the system uses **CSV format** as a fallback:
- Export Excel button → Downloads CSV file
- Template button → Downloads CSV template
- Import Excel → Accepts CSV files

This works immediately without any additional setup.

## Optional: Enable Full Excel Support

To enable native Excel (.xlsx, .xls) support with formatting and multiple sheets:

### Option 1: Using Composer (Recommended)

1. **Install Composer** (if not already installed):
   - Download from: https://getcomposer.org/download/
   - Run the installer

2. **Navigate to project directory**:
   ```bash
   cd c:\xampp\htdocs\hospitalman
   ```

3. **Install PhpSpreadsheet**:
   ```bash
   composer require phpoffice/phpspreadsheet
   ```

### Option 2: Manual Installation

1. **Download PhpSpreadsheet**:
   - Visit: https://github.com/PHPOffice/PhpSpreadsheet/releases
   - Download the latest release

2. **Extract to vendor folder**:
   - Create folder: `c:\xampp\htdocs\hospitalman\vendor`
   - Extract PhpSpreadsheet to: `vendor/phpoffice/phpspreadsheet`

3. **Create autoload file**:
   - Create `vendor/autoload.php` that includes PhpSpreadsheet's autoloader

## Features Comparison

| Feature | CSV (Default) | Excel (Optional) |
|---------|---------------|------------------|
| Import/Export | ✅ Yes | ✅ Yes |
| File Format | .csv | .xlsx, .xls, .csv |
| Formatting | ❌ No | ✅ Yes (colors, bold) |
| Multiple Sheets | ❌ No | ✅ Yes (data + instructions) |
| Auto-size Columns | ❌ No | ✅ Yes |
| Setup Required | ❌ No | ✅ Yes (Composer) |

## Verify Installation

After installing PhpSpreadsheet, try:
1. Go to **Patients** page
2. Click **"Template"** button
3. If installed correctly, you'll download an .xlsx file with:
   - Formatted headers (blue background, white text)
   - Sample data in gray italic
   - Separate "Instructions" sheet
4. If PhpSpreadsheet is not detected, you'll download a .csv file

## Troubleshooting

**Error: "Class 'PhpOffice\PhpSpreadsheet\Spreadsheet' not found"**
- PhpSpreadsheet is not installed
- System will automatically fall back to CSV format

**Excel import not working:**
- Ensure file extension is .xlsx, .xls, or .csv
- Check file size (max 5MB)
- Verify first row contains exact column headers from template

## System Paths

- Excel helpers: `includes/excel_helpers.php`
- Patient export: `modules/patients/export_excel.php`
- Patient import: `modules/patients/import_excel.php`
- Template download: `modules/patients/download_template.php`
