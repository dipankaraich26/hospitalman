<?php
// Initialize auth and functions
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'doctor', 'nurse', 'receptionist']);

$pdo = getDBConnection();

// Handle POST for appointment booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'book_appointment') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, 'scheduled', ?)");
            $stmt->execute([
                (int) $_POST['patient_id'],
                (int) $_POST['doctor_id'],
                $_POST['appointment_date'],
                $_POST['appointment_time'],
                trim($_POST['notes'])
            ]);

            $appointmentId = $pdo->lastInsertId();

            $pdo->commit();
            auditLog('create', 'clinical', 'appointments', $appointmentId, null, $_POST);
            setFlashMessage('success', 'Appointment booked successfully.');
            header('Location: opd_calendar.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlashMessage('error', 'Error booking appointment: ' . $e->getMessage());
        }
    }

    if ($_POST['action'] === 'update_status') {
        $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], (int) $_POST['id']]);
        auditLog('update', 'clinical', 'appointments', (int) $_POST['id'], null, ['status' => $_POST['status']]);
        setFlashMessage('success', 'Appointment status updated.');
        header('Location: opd_calendar.php');
        exit;
    }
}

// Get current month or selected month
$currentMonth = $_GET['month'] ?? date('Y-m');
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Get all doctors with department info
$doctors = $pdo->query("
    SELECT u.id, u.full_name, u.specialization, d.name as department_name, d.id as department_id
    FROM users u
    LEFT JOIN staff_info si ON u.id = si.user_id
    LEFT JOIN departments d ON si.department_id = d.id
    WHERE u.role = 'doctor' AND u.status = 'active'
    ORDER BY u.full_name
")->fetchAll();

// Get all patients
$patients = $pdo->query("SELECT id, patient_id, first_name, last_name FROM patients ORDER BY first_name")->fetchAll();

// Get appointments for current month
$appointmentsStmt = $pdo->prepare("
    SELECT a.*,
           p.first_name, p.last_name, p.patient_id as pid,
           u.full_name as doctor_name, u.specialization,
           d.name as department_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users u ON a.doctor_id = u.id
    LEFT JOIN staff_info si ON u.id = si.user_id
    LEFT JOIN departments d ON si.department_id = d.id
    WHERE DATE_FORMAT(a.appointment_date, '%Y-%m') = ?
    ORDER BY a.appointment_date, a.appointment_time
");
$appointmentsStmt->execute([$currentMonth]);
$appointments = $appointmentsStmt->fetchAll();

// Get appointments for selected date
$dailyAppointmentsStmt = $pdo->prepare("
    SELECT a.*,
           p.first_name, p.last_name, p.patient_id as pid, p.phone,
           u.full_name as doctor_name, u.specialization,
           d.name as department_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users u ON a.doctor_id = u.id
    LEFT JOIN staff_info si ON u.id = si.user_id
    LEFT JOIN departments d ON si.department_id = d.id
    WHERE a.appointment_date = ?
    ORDER BY a.appointment_time
");
$dailyAppointmentsStmt->execute([$selectedDate]);
$dailyAppointments = $dailyAppointmentsStmt->fetchAll();

// Department statistics
$deptStats = $pdo->query("
    SELECT d.name, COUNT(a.id) as appointment_count
    FROM departments d
    LEFT JOIN staff_info si ON d.id = si.department_id
    LEFT JOIN users u ON si.user_id = u.id AND u.role = 'doctor'
    LEFT JOIN appointments a ON u.id = a.doctor_id AND MONTH(a.appointment_date) = MONTH(CURDATE())
    WHERE d.status = 'active'
    GROUP BY d.id, d.name
    ORDER BY appointment_count DESC
")->fetchAll();

// Now include header
$pageTitle = 'OPD Schedule Calendar';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-calendar3"></i> OPD Schedule Calendar</h4>
    <div class="btn-group">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
            <i class="bi bi-plus-lg"></i> Book Appointment
        </button>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Department Statistics -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-building"></i> Department-wise OPD Load (This Month)</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($deptStats as $stat): ?>
                    <div class="col-md-3 mb-2">
                        <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                            <strong><?= sanitize($stat['name']) ?></strong>
                            <span class="badge bg-primary"><?= $stat['appointment_count'] ?> appointments</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Calendar and Daily Schedule -->
<div class="row">
    <!-- Calendar View -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-calendar-month"></i> Monthly Calendar</h6>
                <div class="btn-group">
                    <a href="?month=<?= date('Y-m', strtotime($currentMonth . '-01 -1 month')) ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-chevron-left"></i> Prev
                    </a>
                    <button class="btn btn-sm btn-primary">
                        <?= date('F Y', strtotime($currentMonth . '-01')) ?>
                    </button>
                    <a href="?month=<?= date('Y-m', strtotime($currentMonth . '-01 +1 month')) ?>" class="btn btn-sm btn-outline-primary">
                        Next <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="calendar"></div>
            </div>
        </div>

        <!-- Doctor Schedule Table -->
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-person-badge"></i> Doctor Department Details</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Doctor Name</th>
                                <th>Specialization</th>
                                <th>Department</th>
                                <th class="text-center">Today's Appointments</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($doctors as $doc):
                                // Count today's appointments
                                $todayCount = 0;
                                foreach ($appointments as $apt) {
                                    if ($apt['doctor_id'] == $doc['id'] && $apt['appointment_date'] == date('Y-m-d')) {
                                        $todayCount++;
                                    }
                                }
                            ?>
                            <tr>
                                <td><strong><?= sanitize($doc['full_name']) ?></strong></td>
                                <td><?= sanitize($doc['specialization']) ?></td>
                                <td>
                                    <?php if ($doc['department_name']): ?>
                                        <span class="badge bg-info"><?= sanitize($doc['department_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $todayCount > 0 ? 'primary' : 'secondary' ?>">
                                        <?= $todayCount ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($todayCount > 0): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Available</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Schedule -->
    <div class="col-lg-4">
        <div class="card sticky-top" style="top: 20px;">
            <div class="card-header bg-gradient-success text-white">
                <h6 class="mb-0">
                    <i class="bi bi-calendar-day"></i>
                    Schedule for <?= date('d M Y', strtotime($selectedDate)) ?>
                </h6>
            </div>
            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                <?php if (empty($dailyAppointments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-calendar-x" style="font-size: 3rem;"></i>
                    <p class="mt-2">No appointments scheduled</p>
                </div>
                <?php else:
                    $currentTime = '';
                    foreach ($dailyAppointments as $apt):
                        $timeSlot = date('h:i A', strtotime($apt['appointment_time']));
                        if ($timeSlot !== $currentTime) {
                            if ($currentTime !== '') echo '</div>';
                            echo '<div class="time-slot mb-3">';
                            echo '<div class="time-header text-primary fw-bold mb-2">' . $timeSlot . '</div>';
                            $currentTime = $timeSlot;
                        }
                ?>
                <div class="appointment-card p-2 mb-2 border-start border-3 border-<?=
                    $apt['status'] === 'completed' ? 'success' :
                    ($apt['status'] === 'cancelled' ? 'danger' : 'primary')
                ?> bg-light rounded">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <strong class="text-dark"><?= sanitize($apt['first_name'] . ' ' . $apt['last_name']) ?></strong>
                        <span class="badge bg-<?=
                            $apt['status'] === 'completed' ? 'success' :
                            ($apt['status'] === 'cancelled' ? 'danger' : 'primary')
                        ?>"><?= ucfirst($apt['status']) ?></span>
                    </div>
                    <small class="text-muted d-block">
                        <i class="bi bi-person"></i> <?= sanitize($apt['pid']) ?>
                        <?php if ($apt['phone']): ?>
                        | <i class="bi bi-telephone"></i> <?= sanitize($apt['phone']) ?>
                        <?php endif; ?>
                    </small>
                    <small class="text-muted d-block">
                        <i class="bi bi-person-badge"></i> Dr. <?= sanitize($apt['doctor_name']) ?>
                    </small>
                    <small class="text-muted d-block">
                        <i class="bi bi-building"></i> <?= sanitize($apt['department_name'] ?? 'N/A') ?>
                        | <?= sanitize($apt['specialization']) ?>
                    </small>
                    <?php if ($apt['notes']): ?>
                    <small class="text-muted d-block mt-1">
                        <i class="bi bi-chat-left-text"></i> <?= sanitize($apt['notes']) ?>
                    </small>
                    <?php endif; ?>

                    <?php if ($apt['status'] === 'scheduled'): ?>
                    <div class="mt-2">
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= $apt['id'] ?>">
                            <input type="hidden" name="status" value="completed">
                            <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Complete</button>
                        </form>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= $apt['id'] ?>">
                            <input type="hidden" name="status" value="cancelled">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Cancel</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php
                    endforeach;
                    echo '</div>'; // Close last time slot
                endif;
                ?>
            </div>
        </div>
    </div>
</div>

<!-- Book Appointment Modal -->
<div class="modal fade" id="bookAppointmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Book OPD Appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="book_appointment">

                    <div class="mb-3">
                        <label class="form-label">Patient *</label>
                        <select name="patient_id" class="form-select" required>
                            <option value="">Select Patient</option>
                            <?php foreach ($patients as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= sanitize($p['patient_id'] . ' - ' . $p['first_name'] . ' ' . $p['last_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Doctor *</label>
                        <select name="doctor_id" id="doctorSelect" class="form-select" required>
                            <option value="">Select Doctor</option>
                            <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id'] ?>"
                                    data-dept="<?= sanitize($doc['department_name'] ?? 'Not assigned') ?>"
                                    data-spec="<?= sanitize($doc['specialization']) ?>">
                                <?= sanitize($doc['full_name']) ?>
                                (<?= sanitize($doc['specialization']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="doctorInfo"></small>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Date *</label>
                            <input type="date" name="appointment_date" id="appointmentDate"
                                   class="form-control" value="<?= date('Y-m-d') ?>"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Time *</label>
                            <input type="time" name="appointment_time" class="form-control"
                                   value="09:00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Chief complaint or reason for visit"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Book Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- FullCalendar CSS -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/main.min.css" rel="stylesheet">

<style>
.fc {
    font-size: 0.9rem;
}
.fc-daygrid-day-number {
    font-weight: bold;
}
.fc-event {
    cursor: pointer;
    border-radius: 3px;
    padding: 2px 4px;
    font-size: 0.75rem;
}
.fc-event-title {
    font-weight: 500;
}
.time-header {
    font-size: 0.9rem;
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 5px;
}
.appointment-card {
    transition: all 0.2s;
}
.appointment-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.bg-gradient-success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
}
</style>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

<script>
// Doctor info display
document.getElementById('doctorSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const dept = selected.getAttribute('data-dept');
    const spec = selected.getAttribute('data-spec');
    document.getElementById('doctorInfo').textContent = dept ? `Department: ${dept} | Specialization: ${spec}` : '';
});

// Initialize FullCalendar
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');

    // Prepare events from PHP appointments
    const appointments = <?= json_encode($appointments) ?>;
    const events = appointments.map(apt => ({
        id: apt.id,
        title: apt.first_name + ' ' + apt.last_name,
        start: apt.appointment_date + 'T' + apt.appointment_time,
        backgroundColor: apt.status === 'completed' ? '#28a745' :
                        (apt.status === 'cancelled' ? '#dc3545' : '#0d6efd'),
        borderColor: apt.status === 'completed' ? '#28a745' :
                     (apt.status === 'cancelled' ? '#dc3545' : '#0d6efd'),
        extendedProps: {
            patient: apt.first_name + ' ' + apt.last_name,
            doctor: apt.doctor_name,
            department: apt.department_name,
            status: apt.status
        }
    }));

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        initialDate: '<?= $currentMonth ?>-01',
        headerToolbar: false,
        events: events,
        eventClick: function(info) {
            // Redirect to daily view
            window.location.href = '?date=' + info.event.startStr.split('T')[0] + '&month=<?= $currentMonth ?>';
        },
        dateClick: function(info) {
            // Set date in modal and open it
            document.getElementById('appointmentDate').value = info.dateStr;
            new bootstrap.Modal(document.getElementById('bookAppointmentModal')).show();
        },
        eventContent: function(arg) {
            return {
                html: '<div class="fc-event-title">' +
                      '<i class="bi bi-person-fill"></i> ' + arg.event.title +
                      '</div>'
            };
        },
        height: 'auto'
    });

    calendar.render();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
