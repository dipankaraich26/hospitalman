<?php
/**
 * Simple Moving Average with forecast.
 * @param array $values Numeric time-series data points
 * @param int $window Window size for averaging
 * @param int $forecast Number of future periods to predict
 * @return array ['smoothed' => [...], 'predicted' => [...]]
 */
function simpleMovingAverage(array $values, int $window = 3, int $forecast = 3): array {
    $smoothed = [];
    $n = count($values);
    for ($i = 0; $i < $n; $i++) {
        $start = max(0, $i - $window + 1);
        $slice = array_slice($values, $start, $i - $start + 1);
        $smoothed[] = array_sum($slice) / count($slice);
    }
    $predicted = [];
    $lastWindow = array_slice($values, -$window);
    for ($i = 0; $i < $forecast; $i++) {
        $pred = array_sum($lastWindow) / count($lastWindow);
        $predicted[] = round($pred, 2);
        array_shift($lastWindow);
        $lastWindow[] = $pred;
    }
    return ['smoothed' => $smoothed, 'predicted' => $predicted];
}

/**
 * Simple Linear Regression with forecast.
 * @param array $values Numeric time-series data points (y-values; x = 0,1,2,...)
 * @param int $forecast Number of future periods
 * @return array ['slope', 'intercept', 'predicted' => [...], 'r_squared']
 */
function linearRegression(array $values, int $forecast = 3): array {
    $n = count($values);
    if ($n < 2) {
        return ['slope' => 0, 'intercept' => 0, 'predicted' => array_fill(0, $forecast, 0), 'r_squared' => 0];
    }

    $sumX = 0; $sumY = 0; $sumXY = 0; $sumX2 = 0; $sumY2 = 0;
    for ($i = 0; $i < $n; $i++) {
        $sumX += $i;
        $sumY += $values[$i];
        $sumXY += $i * $values[$i];
        $sumX2 += $i * $i;
        $sumY2 += $values[$i] * $values[$i];
    }

    $denom = ($n * $sumX2 - $sumX * $sumX);
    $slope = $denom != 0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0;
    $intercept = ($sumY - $slope * $sumX) / $n;

    // R-squared
    $ssTot = $sumY2 - ($sumY * $sumY / $n);
    $ssRes = 0;
    for ($i = 0; $i < $n; $i++) {
        $pred = $intercept + $slope * $i;
        $ssRes += ($values[$i] - $pred) ** 2;
    }
    $rSquared = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0;

    $predicted = [];
    for ($i = 0; $i < $forecast; $i++) {
        $predicted[] = round(max(0, $intercept + $slope * ($n + $i)), 2);
    }

    return [
        'slope' => round($slope, 4),
        'intercept' => round($intercept, 4),
        'predicted' => $predicted,
        'r_squared' => round($rSquared, 4)
    ];
}

/**
 * Predict stock-out date for a medicine.
 * @param int $currentStock Current quantity
 * @param array $dispensingHistory [quantity_dispensed, ...] last N days
 * @param int $historyDays Number of days the history spans
 * @return array ['daily_rate', 'days_until_stockout', 'stockout_date', 'confidence']
 */
function predictStockout(int $currentStock, array $dispensingHistory, int $historyDays = 90): array {
    $totalDispensed = array_sum($dispensingHistory);
    $dailyRate = $historyDays > 0 ? $totalDispensed / $historyDays : 0;

    if ($dailyRate <= 0) {
        return [
            'daily_rate' => 0,
            'days_until_stockout' => 999,
            'stockout_date' => null,
            'confidence' => 'low'
        ];
    }

    $daysUntil = (int) ceil($currentStock / $dailyRate);
    $stockoutDate = date('Y-m-d', strtotime("+$daysUntil days"));

    $confidence = 'medium';
    if (count($dispensingHistory) >= 30) $confidence = 'high';
    elseif (count($dispensingHistory) < 7) $confidence = 'low';

    return [
        'daily_rate' => round($dailyRate, 2),
        'days_until_stockout' => $daysUntil,
        'stockout_date' => $stockoutDate,
        'confidence' => $confidence
    ];
}

/**
 * Generate alert messages from predictions.
 */
function generatePredictiveAlerts(PDO $pdo): array {
    $alerts = [];

    // Stock-out alerts
    $medicines = $pdo->query("SELECT id, name, quantity_in_stock, reorder_level FROM medicines WHERE quantity_in_stock > 0")->fetchAll();
    foreach ($medicines as $med) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) as total FROM medicine_dispensing WHERE medicine_id = ? AND dispensed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $stmt->execute([$med['id']]);
        $dispensed = (int) $stmt->fetch()['total'];

        if ($dispensed > 0) {
            $prediction = predictStockout($med['quantity_in_stock'], [$dispensed], 90);
            if ($prediction['days_until_stockout'] <= 7) {
                $alerts[] = [
                    'type' => 'danger',
                    'icon' => 'exclamation-triangle',
                    'message' => $med['name'] . ' predicted to stock out in ' . $prediction['days_until_stockout'] . ' days',
                    'module' => 'pharmacy'
                ];
            } elseif ($prediction['days_until_stockout'] <= 30) {
                $alerts[] = [
                    'type' => 'warning',
                    'icon' => 'exclamation-circle',
                    'message' => $med['name'] . ' may stock out in ~' . $prediction['days_until_stockout'] . ' days',
                    'module' => 'pharmacy'
                ];
            }
        }
    }

    // Revenue trend alert
    $revData = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total
        FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY month ORDER BY month
    ")->fetchAll();
    if (count($revData) >= 3) {
        $values = array_column($revData, 'total');
        $regression = linearRegression(array_map('floatval', $values), 1);
        $lastVal = end($values);
        if ($lastVal > 0 && $regression['predicted'][0] < $lastVal * 0.85) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'graph-down-arrow',
                'message' => 'Revenue trend shows potential decline next month',
                'module' => 'billing'
            ];
        }
    }

    // Sort by severity
    usort($alerts, function($a, $b) {
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        return ($order[$a['type']] ?? 3) - ($order[$b['type']] ?? 3);
    });

    return $alerts;
}

/**
 * Cash Flow Forecasting - Predict future cash position
 * @param PDO $pdo Database connection
 * @param int $monthsHistory Months of historical data to analyze
 * @param int $monthsForecast Months to forecast ahead
 * @return array ['historical' => [...], 'forecast' => [...], 'insights' => [...]]
 */
function forecastCashFlow(PDO $pdo, int $monthsHistory = 12, int $monthsForecast = 3): array {
    // Get revenue data (income)
    $revenueStmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        GROUP BY month
        ORDER BY month
    ");
    $revenueStmt->execute([$monthsHistory]);
    $revenueData = $revenueStmt->fetchAll();

    // Get expense data (purchases, salaries, etc.)
    $expenseStmt = $pdo->prepare("
        SELECT DATE_FORMAT(purchase_date, '%Y-%m') as month, SUM(quantity * purchase_price) as total
        FROM medicine_purchases
        WHERE purchase_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        GROUP BY month
        ORDER BY month
    ");
    $expenseStmt->execute([$monthsHistory]);
    $expenseData = $expenseStmt->fetchAll();

    // Build historical cash flow
    $historical = [];
    $monthsArray = [];

    // Get all months
    for ($i = $monthsHistory - 1; $i >= 0; $i--) {
        $monthsArray[] = date('Y-m', strtotime("-$i months"));
    }

    foreach ($monthsArray as $month) {
        $revenue = 0;
        $expense = 0;

        foreach ($revenueData as $r) {
            if ($r['month'] === $month) $revenue = (float) $r['total'];
        }
        foreach ($expenseData as $e) {
            if ($e['month'] === $month) $expense = (float) $e['total'];
        }

        $netCashFlow = $revenue - $expense;
        $historical[] = [
            'month' => $month,
            'revenue' => $revenue,
            'expense' => $expense,
            'net_cash_flow' => $netCashFlow
        ];
    }

    // Forecast using linear regression
    $revenueValues = array_column($historical, 'revenue');
    $expenseValues = array_column($historical, 'expense');

    $revenueForecast = linearRegression($revenueValues, $monthsForecast);
    $expenseForecast = linearRegression($expenseValues, $monthsForecast);

    $forecast = [];
    for ($i = 1; $i <= $monthsForecast; $i++) {
        $month = date('Y-m', strtotime("+$i months"));
        $predictedRevenue = $revenueForecast['predicted'][$i - 1];
        $predictedExpense = $expenseForecast['predicted'][$i - 1];

        $forecast[] = [
            'month' => $month,
            'revenue' => $predictedRevenue,
            'expense' => $predictedExpense,
            'net_cash_flow' => $predictedRevenue - $predictedExpense,
            'confidence' => $revenueForecast['r_squared']
        ];
    }

    // Generate insights
    $insights = [];
    $avgHistoricalCashFlow = array_sum(array_column($historical, 'net_cash_flow')) / count($historical);
    $forecastCashFlow = array_sum(array_column($forecast, 'net_cash_flow')) / count($forecast);

    if ($forecastCashFlow < $avgHistoricalCashFlow * 0.8) {
        $insights[] = [
            'type' => 'warning',
            'message' => 'Forecasted cash flow is 20% lower than historical average',
            'recommendation' => 'Review expenses and consider revenue enhancement strategies'
        ];
    } elseif ($forecastCashFlow > $avgHistoricalCashFlow * 1.2) {
        $insights[] = [
            'type' => 'success',
            'message' => 'Forecasted cash flow shows 20% growth',
            'recommendation' => 'Consider investing in capacity expansion or new services'
        ];
    }

    // Check for negative cash flow
    foreach ($forecast as $f) {
        if ($f['net_cash_flow'] < 0) {
            $insights[] = [
                'type' => 'danger',
                'message' => 'Negative cash flow predicted in ' . date('M Y', strtotime($f['month'])),
                'recommendation' => 'Urgent: Review expenses and improve collections'
            ];
            break;
        }
    }

    return [
        'historical' => $historical,
        'forecast' => $forecast,
        'insights' => $insights,
        'avg_historical_flow' => round($avgHistoricalCashFlow, 2),
        'avg_forecast_flow' => round($forecastCashFlow, 2)
    ];
}

/**
 * Department Profitability Analysis
 * @param PDO $pdo Database connection
 * @param int $months Analysis period in months
 * @return array Department-wise profitability metrics
 */
function analyzeDepartmentProfitability(PDO $pdo, int $months = 6): array {
    $departments = [];

    // Get all active departments
    $deptStmt = $pdo->query("SELECT id, name FROM departments WHERE status = 'active'");
    $allDepts = $deptStmt->fetchAll();

    foreach ($allDepts as $dept) {
        // Revenue from department (via doctor's department in appointments)
        $revenueStmt = $pdo->prepare("
            SELECT COALESCE(SUM(i.total_amount), 0) as revenue
            FROM invoices i
            JOIN appointments a ON i.patient_id = a.patient_id
            JOIN staff_info si ON a.doctor_id = si.user_id
            WHERE si.department_id = ?
            AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        ");
        $revenueStmt->execute([$dept['id'], $months]);
        $revenue = (float) $revenueStmt->fetch()['revenue'];

        // Cost estimation (simplified: staff + resources)
        $staffCostStmt = $pdo->prepare("
            SELECT COALESCE(SUM(si.salary), 0) * ? as staff_cost
            FROM staff_info si
            WHERE si.department_id = ?
        ");
        $staffCostStmt->execute([$months, $dept['id']]);
        $staffCost = (float) $staffCostStmt->fetch()['staff_cost'];

        // Calculate margin
        $profit = $revenue - $staffCost;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        // Patient volume
        $patientStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT a.patient_id) as patient_count
            FROM appointments a
            JOIN staff_info si ON a.doctor_id = si.user_id
            WHERE si.department_id = ?
            AND a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        ");
        $patientStmt->execute([$dept['id'], $months]);
        $patientCount = (int) $patientStmt->fetch()['patient_count'];

        // Revenue per patient
        $revenuePerPatient = $patientCount > 0 ? $revenue / $patientCount : 0;

        $departments[] = [
            'id' => $dept['id'],
            'name' => $dept['name'],
            'revenue' => round($revenue, 2),
            'cost' => round($staffCost, 2),
            'profit' => round($profit, 2),
            'margin' => round($margin, 2),
            'patient_count' => $patientCount,
            'revenue_per_patient' => round($revenuePerPatient, 2),
            'performance' => $margin >= 20 ? 'excellent' : ($margin >= 10 ? 'good' : ($margin >= 0 ? 'fair' : 'poor'))
        ];
    }

    // Sort by profitability
    usort($departments, function($a, $b) {
        return $b['profit'] <=> $a['profit'];
    });

    return $departments;
}

/**
 * Service/Treatment Cost Estimation
 * @param PDO $pdo Database connection
 * @param string $serviceCategory Service category (consultation, lab, medicine, room, procedure)
 * @param int $months Historical data period
 * @return array Cost estimates and pricing recommendations
 */
function estimateServiceCosts(PDO $pdo, string $serviceCategory = 'all', int $months = 6): array {
    $estimates = [];

    // Get historical invoice items by category
    $stmt = $pdo->prepare("
        SELECT
            ii.category,
            COUNT(*) as service_count,
            AVG(ii.unit_price) as avg_price,
            MIN(ii.unit_price) as min_price,
            MAX(ii.unit_price) as max_price,
            STDDEV(ii.unit_price) as price_stddev,
            SUM(ii.total_price) as total_revenue
        FROM invoice_items ii
        JOIN invoices i ON ii.invoice_id = i.id
        WHERE i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        " . ($serviceCategory !== 'all' ? "AND ii.category = ?" : "") . "
        GROUP BY ii.category
    ");

    if ($serviceCategory !== 'all') {
        $stmt->execute([$months, $serviceCategory]);
    } else {
        $stmt->execute([$months]);
    }

    $data = $stmt->fetchAll();

    foreach ($data as $row) {
        $avgPrice = (float) $row['avg_price'];
        $stdDev = (float) ($row['price_stddev'] ?? 0);

        // Calculate recommended price range
        $recommendedMin = max(0, $avgPrice - $stdDev);
        $recommendedMax = $avgPrice + $stdDev;

        // Market position
        $marketPosition = 'average';
        if ($avgPrice < $row['min_price'] * 1.1) {
            $marketPosition = 'budget';
        } elseif ($avgPrice > $row['max_price'] * 0.9) {
            $marketPosition = 'premium';
        }

        $estimates[] = [
            'category' => $row['category'],
            'service_count' => (int) $row['service_count'],
            'average_price' => round($avgPrice, 2),
            'min_price' => round((float) $row['min_price'], 2),
            'max_price' => round((float) $row['max_price'], 2),
            'recommended_min' => round($recommendedMin, 2),
            'recommended_max' => round($recommendedMax, 2),
            'total_revenue' => round((float) $row['total_revenue'], 2),
            'market_position' => $marketPosition,
            'pricing_recommendation' => $avgPrice < $recommendedMin ? 'increase' : ($avgPrice > $recommendedMax ? 'decrease' : 'maintain')
        ];
    }

    return $estimates;
}

/**
 * Advanced Analytics Dashboard Metrics
 * @param PDO $pdo Database connection
 * @return array Comprehensive analytics metrics
 */
function getAdvancedAnalytics(PDO $pdo): array {
    $analytics = [];

    // Patient Growth Rate
    $patientGrowth = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM patients WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as current_month,
            (SELECT COUNT(*) FROM patients WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as previous_month
    ")->fetch();

    $growthRate = $patientGrowth['previous_month'] > 0
        ? (($patientGrowth['current_month'] - $patientGrowth['previous_month']) / $patientGrowth['previous_month']) * 100
        : 0;

    $analytics['patient_growth_rate'] = round($growthRate, 2);

    // Average Treatment Value
    $avgTreatment = $pdo->query("
        SELECT AVG(total_amount) as avg_value
        FROM invoices
        WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();

    $analytics['avg_treatment_value'] = round((float) ($avgTreatment['avg_value'] ?? 0), 2);

    // Collection Efficiency (payments vs invoices)
    $collection = $pdo->query("
        SELECT
            (SELECT SUM(amount) FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as collected,
            (SELECT SUM(total_amount) FROM invoices WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as billed
    ")->fetch();

    $collectionEfficiency = $collection['billed'] > 0
        ? ($collection['collected'] / $collection['billed']) * 100
        : 0;

    $analytics['collection_efficiency'] = round($collectionEfficiency, 2);

    // Bed Occupancy Rate (if tracking beds)
    // Simplified: Using appointment load as proxy
    $occupancy = $pdo->query("
        SELECT
            COUNT(DISTINCT DATE(appointment_date)) as days_with_appointments,
            30 as total_days
        FROM appointments
        WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();

    $occupancyRate = ($occupancy['days_with_appointments'] / $occupancy['total_days']) * 100;
    $analytics['facility_utilization'] = round($occupancyRate, 2);

    // Staff Productivity (patients per staff)
    $productivity = $pdo->query("
        SELECT
            COUNT(DISTINCT a.id) as appointment_count,
            (SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ('doctor', 'nurse')) as staff_count
        FROM appointments a
        WHERE a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();

    $staffProductivity = $productivity['staff_count'] > 0
        ? $productivity['appointment_count'] / $productivity['staff_count']
        : 0;

    $analytics['staff_productivity'] = round($staffProductivity, 2);

    // Patient Satisfaction Score (based on completed appointments)
    $satisfaction = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM appointments WHERE status = 'completed' AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as completed,
            (SELECT COUNT(*) FROM appointments WHERE status = 'cancelled' AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as cancelled
    ")->fetch();

    $totalAppointments = $satisfaction['completed'] + $satisfaction['cancelled'];
    $satisfactionScore = $totalAppointments > 0
        ? ($satisfaction['completed'] / $totalAppointments) * 100
        : 100;

    $analytics['satisfaction_proxy'] = round($satisfactionScore, 2);

    return $analytics;
}