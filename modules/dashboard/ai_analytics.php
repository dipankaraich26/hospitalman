<?php
// Initialize auth and functions
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/analytics.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

// Get AI analytics data
$cashFlow = forecastCashFlow($pdo, 12, 3);
$deptProfitability = analyzeDepartmentProfitability($pdo, 6);
$serviceCosts = estimateServiceCosts($pdo, 'all', 6);
$advancedMetrics = getAdvancedAnalytics($pdo);

// Now include header
$pageTitle = 'AI Analytics & Insights';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-brain"></i> AI-Driven Analytics & Decision Support</h4>
    <div class="btn-group">
        <button class="btn btn-outline-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Advanced KPI Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card bg-primary bg-opacity-10 border-primary">
            <div class="card-body text-center">
                <h6 class="text-primary mb-1"><i class="bi bi-graph-up-arrow"></i> Patient Growth</h6>
                <h3 class="mb-0 <?= $advancedMetrics['patient_growth_rate'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $advancedMetrics['patient_growth_rate'] > 0 ? '+' : '' ?><?= $advancedMetrics['patient_growth_rate'] ?>%
                </h3>
                <small class="text-muted">Month-over-Month</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success bg-opacity-10 border-success">
            <div class="card-body text-center">
                <h6 class="text-success mb-1"><i class="bi bi-currency-rupee"></i> Avg Treatment</h6>
                <h3 class="mb-0"><?= formatCurrency($advancedMetrics['avg_treatment_value']) ?></h3>
                <small class="text-muted">Per Patient</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning bg-opacity-10 border-warning">
            <div class="card-body text-center">
                <h6 class="text-warning mb-1"><i class="bi bi-bank"></i> Collection</h6>
                <h3 class="mb-0"><?= $advancedMetrics['collection_efficiency'] ?>%</h3>
                <small class="text-muted">Efficiency Rate</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info bg-opacity-10 border-info">
            <div class="card-body text-center">
                <h6 class="text-info mb-1"><i class="bi bi-building"></i> Utilization</h6>
                <h3 class="mb-0"><?= $advancedMetrics['facility_utilization'] ?>%</h3>
                <small class="text-muted">Facility Use</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-purple bg-opacity-10 border-purple">
            <div class="card-body text-center">
                <h6 class="text-purple mb-1"><i class="bi bi-people"></i> Productivity</h6>
                <h3 class="mb-0"><?= number_format($advancedMetrics['staff_productivity'], 1) ?></h3>
                <small class="text-muted">Patients/Staff</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-teal bg-opacity-10 border-teal">
            <div class="card-body text-center">
                <h6 class="text-teal mb-1"><i class="bi bi-star"></i> Satisfaction</h6>
                <h3 class="mb-0"><?= $advancedMetrics['satisfaction_proxy'] ?>%</h3>
                <small class="text-muted">Completion Rate</small>
            </div>
        </div>
    </div>
</div>

<!-- Cash Flow Forecasting -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-gradient-primary text-white">
                <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Cash Flow Forecast (Next 3 Months)</h5>
            </div>
            <div class="card-body">
                <canvas id="cashFlowChart" height="80"></canvas>
                <div class="mt-3 row">
                    <div class="col-md-6">
                        <strong>Historical Average:</strong> <?= formatCurrency($cashFlow['avg_historical_flow']) ?>/month
                    </div>
                    <div class="col-md-6">
                        <strong>Forecast Average:</strong>
                        <span class="<?= $cashFlow['avg_forecast_flow'] >= $cashFlow['avg_historical_flow'] ? 'text-success' : 'text-danger' ?>">
                            <?= formatCurrency($cashFlow['avg_forecast_flow']) ?>/month
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-gradient-warning">
                <h6 class="mb-0"><i class="bi bi-lightbulb"></i> AI Insights</h6>
            </div>
            <div class="card-body">
                <?php if (empty($cashFlow['insights'])): ?>
                <div class="alert alert-success mb-0">
                    <i class="bi bi-check-circle"></i> Cash flow is healthy and stable. No immediate concerns detected.
                </div>
                <?php else: foreach ($cashFlow['insights'] as $insight): ?>
                <div class="alert alert-<?= $insight['type'] ?> mb-2">
                    <strong><?= sanitize($insight['message']) ?></strong>
                    <hr class="my-1">
                    <small><?= sanitize($insight['recommendation']) ?></small>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Department Profitability Matrix -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-gradient-success text-white">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Department Profitability Analysis (Last 6 Months)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-end">Revenue</th>
                                <th class="text-end">Cost</th>
                                <th class="text-end">Profit</th>
                                <th class="text-end">Margin %</th>
                                <th class="text-center">Patients</th>
                                <th class="text-end">Revenue/Patient</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($deptProfitability)): ?>
                            <tr><td colspan="8" class="text-center text-muted">No department data available</td></tr>
                            <?php else: foreach ($deptProfitability as $dept): ?>
                            <tr>
                                <td><strong><?= sanitize($dept['name']) ?></strong></td>
                                <td class="text-end"><?= formatCurrency($dept['revenue']) ?></td>
                                <td class="text-end text-danger"><?= formatCurrency($dept['cost']) ?></td>
                                <td class="text-end <?= $dept['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <strong><?= formatCurrency($dept['profit']) ?></strong>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $dept['margin'] >= 20 ? 'success' : ($dept['margin'] >= 10 ? 'info' : ($dept['margin'] >= 0 ? 'warning' : 'danger')) ?>">
                                        <?= $dept['margin'] ?>%
                                    </span>
                                </td>
                                <td class="text-center"><?= $dept['patient_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($dept['revenue_per_patient']) ?></td>
                                <td>
                                    <?php
                                    $perfColors = ['excellent' => 'success', 'good' => 'primary', 'fair' => 'warning', 'poor' => 'danger'];
                                    $perfIcons = ['excellent' => 'star-fill', 'good' => 'hand-thumbs-up', 'fair' => 'dash-circle', 'poor' => 'exclamation-triangle'];
                                    ?>
                                    <span class="badge bg-<?= $perfColors[$dept['performance']] ?>">
                                        <i class="bi bi-<?= $perfIcons[$dept['performance']] ?>"></i>
                                        <?= ucfirst($dept['performance']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Service Cost Estimation & Pricing Optimization -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-gradient-info text-white">
                <h5 class="mb-0"><i class="bi bi-calculator"></i> Service Cost Estimation & Pricing Recommendations</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Service Category</th>
                                <th class="text-center">Count</th>
                                <th class="text-end">Avg Price</th>
                                <th class="text-end">Min-Max Range</th>
                                <th class="text-end">Recommended Range</th>
                                <th class="text-end">Total Revenue</th>
                                <th>Market Position</th>
                                <th>Recommendation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($serviceCosts)): ?>
                            <tr><td colspan="8" class="text-center text-muted">No service data available</td></tr>
                            <?php else: foreach ($serviceCosts as $service): ?>
                            <tr>
                                <td><strong><?= ucfirst($service['category']) ?></strong></td>
                                <td class="text-center"><?= $service['service_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($service['average_price']) ?></td>
                                <td class="text-end">
                                    <small class="text-muted">
                                        <?= formatCurrency($service['min_price']) ?> - <?= formatCurrency($service['max_price']) ?>
                                    </small>
                                </td>
                                <td class="text-end text-primary">
                                    <?= formatCurrency($service['recommended_min']) ?> - <?= formatCurrency($service['recommended_max']) ?>
                                </td>
                                <td class="text-end text-success"><strong><?= formatCurrency($service['total_revenue']) ?></strong></td>
                                <td>
                                    <?php
                                    $posColors = ['budget' => 'success', 'average' => 'info', 'premium' => 'warning'];
                                    ?>
                                    <span class="badge bg-<?= $posColors[$service['market_position']] ?>">
                                        <?= ucfirst($service['market_position']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $recIcons = ['increase' => 'arrow-up-circle text-success', 'decrease' => 'arrow-down-circle text-danger', 'maintain' => 'dash-circle text-secondary'];
                                    ?>
                                    <i class="bi bi-<?= $recIcons[$service['pricing_recommendation']] ?>"></i>
                                    <?= ucfirst($service['pricing_recommendation']) ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Department Performance Chart -->
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Department Revenue Distribution</h6>
            </div>
            <div class="card-body">
                <canvas id="deptRevenueChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-bar-chart"></i> Department Profit Margins</h6>
            </div>
            <div class="card-body">
                <canvas id="deptMarginChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Cash Flow Forecast Chart
const cashFlowCtx = document.getElementById('cashFlowChart').getContext('2d');
const cashFlowData = {
    historical: <?= json_encode($cashFlow['historical']) ?>,
    forecast: <?= json_encode($cashFlow['forecast']) ?>
};

const allMonths = [
    ...cashFlowData.historical.map(h => h.month),
    ...cashFlowData.forecast.map(f => f.month)
];

const revenueData = [
    ...cashFlowData.historical.map(h => h.revenue),
    ...cashFlowData.forecast.map(f => f.revenue)
];

const expenseData = [
    ...cashFlowData.historical.map(h => h.expense),
    ...cashFlowData.forecast.map(f => f.expense)
];

const netCashFlowData = [
    ...cashFlowData.historical.map(h => h.net_cash_flow),
    ...cashFlowData.forecast.map(f => f.net_cash_flow)
];

const historicalLength = cashFlowData.historical.length;

new Chart(cashFlowCtx, {
    type: 'line',
    data: {
        labels: allMonths,
        datasets: [
            {
                label: 'Revenue',
                data: revenueData,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.3,
                segment: {
                    borderDash: ctx => ctx.p0DataIndex >= historicalLength ? [5, 5] : []
                }
            },
            {
                label: 'Expenses',
                data: expenseData,
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                tension: 0.3,
                segment: {
                    borderDash: ctx => ctx.p0DataIndex >= historicalLength ? [5, 5] : []
                }
            },
            {
                label: 'Net Cash Flow',
                data: netCashFlowData,
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                tension: 0.3,
                borderWidth: 2,
                segment: {
                    borderDash: ctx => ctx.p0DataIndex >= historicalLength ? [5, 5] : []
                }
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ₹' + context.parsed.y.toLocaleString('en-IN');
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: value => '₹' + value.toLocaleString('en-IN')
                }
            }
        }
    }
});

// Department Revenue Pie Chart
const deptData = <?= json_encode($deptProfitability) ?>;
const deptRevenueCtx = document.getElementById('deptRevenueChart').getContext('2d');

new Chart(deptRevenueCtx, {
    type: 'pie',
    data: {
        labels: deptData.map(d => d.name),
        datasets: [{
            data: deptData.map(d => d.revenue),
            backgroundColor: [
                'rgba(255, 99, 132, 0.8)',
                'rgba(54, 162, 235, 0.8)',
                'rgba(255, 206, 86, 0.8)',
                'rgba(75, 192, 192, 0.8)',
                'rgba(153, 102, 255, 0.8)',
                'rgba(255, 159, 64, 0.8)',
                'rgba(199, 199, 199, 0.8)',
                'rgba(83, 102, 255, 0.8)'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                        return context.label + ': ₹' + context.parsed.toLocaleString('en-IN') + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

// Department Margin Bar Chart
const deptMarginCtx = document.getElementById('deptMarginChart').getContext('2d');

new Chart(deptMarginCtx, {
    type: 'bar',
    data: {
        labels: deptData.map(d => d.name),
        datasets: [{
            label: 'Profit Margin %',
            data: deptData.map(d => d.margin),
            backgroundColor: deptData.map(d =>
                d.margin >= 20 ? 'rgba(75, 192, 192, 0.8)' :
                d.margin >= 10 ? 'rgba(54, 162, 235, 0.8)' :
                d.margin >= 0 ? 'rgba(255, 206, 86, 0.8)' :
                'rgba(255, 99, 132, 0.8)'
            ),
            borderColor: deptData.map(d =>
                d.margin >= 20 ? 'rgb(75, 192, 192)' :
                d.margin >= 10 ? 'rgb(54, 162, 235)' :
                d.margin >= 0 ? 'rgb(255, 206, 86)' :
                'rgb(255, 99, 132)'
            ),
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: context => 'Margin: ' + context.parsed.y + '%'
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: value => value + '%'
                }
            }
        }
    }
});
</script>

<style>
.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.bg-gradient-success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
}
.bg-gradient-warning {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}
.bg-gradient-info {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
