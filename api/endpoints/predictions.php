<?php
/**
 * Predictions API Endpoint
 * GET /predictions/admissions   — Admission forecast
 * GET /predictions/revenue      — Revenue prediction
 * GET /predictions/stockouts    — Medicine stock-out predictions
 * GET /predictions/alerts       — Aggregated predictive alerts
 */

require_once __DIR__ . '/../../includes/analytics.php';

$pdo = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    apiError('Method not allowed', 405);
}

// Sub-resource routing: /predictions/{type}
$subResource = $id !== null ? null : (isset($parts[1]) ? $parts[1] : null);
if ($id !== null) {
    $subResource = null;
}
// Re-parse: predictions/admissions, predictions/revenue, etc.
$type = $parts[1] ?? null;

switch ($type) {
    case 'admissions':
        $months = isset($_GET['months']) ? min(max((int)$_GET['months'], 3), 24) : 12;
        $forecast = isset($_GET['forecast']) ? min(max((int)$_GET['forecast'], 1), 6) : 3;

        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(appointment_date, '%Y-%m') as month,
                   COUNT(*) as total
            FROM appointments
            WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute([$months]);
        $data = $stmt->fetchAll();

        $values = array_map('intval', array_column($data, 'total'));
        $labels = array_column($data, 'month');

        $sma = simpleMovingAverage($values, 3, $forecast);
        $regression = linearRegression(array_map('floatval', $values), $forecast);

        $lastMonth = !empty($labels) ? end($labels) : date('Y-m');
        $forecastLabels = [];
        for ($i = 1; $i <= $forecast; $i++) {
            $forecastLabels[] = date('Y-m', strtotime($lastMonth . '-01 +' . $i . ' months'));
        }

        jsonResponse([
            'data' => [
                'historical' => [
                    'labels' => $labels,
                    'values' => $values,
                    'smoothed' => $sma['smoothed']
                ],
                'forecast' => [
                    'labels' => $forecastLabels,
                    'sma_predicted' => $sma['predicted'],
                    'regression_predicted' => $regression['predicted']
                ],
                'model' => [
                    'slope' => $regression['slope'],
                    'intercept' => $regression['intercept'],
                    'r_squared' => $regression['r_squared']
                ]
            ]
        ]);
        break;

    case 'revenue':
        $months = isset($_GET['months']) ? min(max((int)$_GET['months'], 3), 24) : 12;
        $forecast = isset($_GET['forecast']) ? min(max((int)$_GET['forecast'], 1), 6) : 3;

        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
                   SUM(amount) as total
            FROM payments
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute([$months]);
        $data = $stmt->fetchAll();

        $values = array_map('floatval', array_column($data, 'total'));
        $labels = array_column($data, 'month');

        $sma = simpleMovingAverage($values, 3, $forecast);
        $regression = linearRegression($values, $forecast);

        $lastMonth = !empty($labels) ? end($labels) : date('Y-m');
        $forecastLabels = [];
        for ($i = 1; $i <= $forecast; $i++) {
            $forecastLabels[] = date('Y-m', strtotime($lastMonth . '-01 +' . $i . ' months'));
        }

        jsonResponse([
            'data' => [
                'historical' => [
                    'labels' => $labels,
                    'values' => $values,
                    'smoothed' => $sma['smoothed']
                ],
                'forecast' => [
                    'labels' => $forecastLabels,
                    'sma_predicted' => $sma['predicted'],
                    'regression_predicted' => $regression['predicted']
                ],
                'model' => [
                    'slope' => $regression['slope'],
                    'intercept' => $regression['intercept'],
                    'r_squared' => $regression['r_squared']
                ]
            ]
        ]);
        break;

    case 'stockouts':
        $medicines = $pdo->query("
            SELECT id, name, quantity_in_stock, reorder_level
            FROM medicines WHERE quantity_in_stock > 0 ORDER BY name
        ")->fetchAll();

        $predictions = [];
        foreach ($medicines as $med) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0) as total
                FROM medicine_dispensing
                WHERE medicine_id = ? AND dispensed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ");
            $stmt->execute([$med['id']]);
            $dispensed = (int) $stmt->fetch()['total'];

            if ($dispensed > 0) {
                $prediction = predictStockout($med['quantity_in_stock'], [$dispensed], 90);
                $predictions[] = [
                    'medicine_id' => $med['id'],
                    'name' => $med['name'],
                    'current_stock' => $med['quantity_in_stock'],
                    'reorder_level' => $med['reorder_level'],
                    'daily_rate' => $prediction['daily_rate'],
                    'days_until_stockout' => $prediction['days_until_stockout'],
                    'stockout_date' => $prediction['stockout_date'],
                    'confidence' => $prediction['confidence']
                ];
            }
        }

        // Sort by urgency
        usort($predictions, fn($a, $b) => $a['days_until_stockout'] - $b['days_until_stockout']);

        // Optional filter: critical, warning, safe
        $severity = $_GET['severity'] ?? null;
        if ($severity === 'critical') {
            $predictions = array_values(array_filter($predictions, fn($p) => $p['days_until_stockout'] <= 7));
        } elseif ($severity === 'warning') {
            $predictions = array_values(array_filter($predictions, fn($p) => $p['days_until_stockout'] <= 30));
        }

        jsonResponse([
            'data' => $predictions,
            'meta' => ['total' => count($predictions)]
        ]);
        break;

    case 'alerts':
        $alerts = generatePredictiveAlerts($pdo);
        jsonResponse([
            'data' => $alerts,
            'meta' => ['total' => count($alerts)]
        ]);
        break;

    default:
        jsonResponse([
            'data' => [
                'available_endpoints' => [
                    'GET /api/predictions/admissions' => 'Admission forecast with SMA and linear regression',
                    'GET /api/predictions/revenue' => 'Revenue prediction with trend analysis',
                    'GET /api/predictions/stockouts' => 'Medicine stock-out predictions',
                    'GET /api/predictions/alerts' => 'Aggregated predictive alerts'
                ],
                'parameters' => [
                    'months' => 'History period (3-24, default 12)',
                    'forecast' => 'Forecast periods (1-6, default 3)',
                    'severity' => 'Filter stockouts: critical, warning, safe'
                ]
            ]
        ]);
}
