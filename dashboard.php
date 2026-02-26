<?php
// dashboard.php
$page_title = "Dashboard";
$active     = "dashboard";

require_once "auth.php";
require_login();
require_once "db.php"; // $conn

// ---------------------- HELPERI ---------------------- //
function clean($v) { return trim($v ?? ''); }

function is_valid_email_custom($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    return (strpos($email, '@') !== false && strpos($email, '.') !== false);
}

function is_valid_phone_9($phone) {
    return preg_match('/^[0-9]{9}$/', $phone);
}

function is_valid_cnp($cnp) {
    return ($cnp === '' || preg_match('/^[0-9]{13}$/', $cnp));
}

function is_valid_gender($g) {
    return in_array($g, ['M','F','Altul'], true);
}

function status_ui_to_db($s) {
    switch ($s) {
        case 'Active':      return 'activ';
        case 'InTreatment': return 'în tratament';
        case 'Recovered':   return 'externat';
        default:            return 'activ';
    }
}

// ---------- MESAJ GLOBAL ---------- //
$dash_errors  = [];
$dash_success = "";

// ----------------------------------------------------
//      HANDLE ADD PATIENT (din modal Dashboard)
// ----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === 'add_patient_dash') {

    $cnp          = clean($_POST["cnp"] ?? '');
    $first_name   = clean($_POST["first_name"] ?? '');
    $last_name    = clean($_POST["last_name"] ?? '');
    $birth_date   = clean($_POST["birth_date"] ?? '');
    $gender       = clean($_POST["gender"] ?? '');
    $phone        = clean($_POST["phone"] ?? '');
    $email        = clean($_POST["email"] ?? '');
    $address      = clean($_POST["address"] ?? '');
    $visit_reason = clean($_POST["visit_reason"] ?? '');
    $diagnosis    = clean($_POST["diagnosis"] ?? '');
    $doctor_id    = (int)($_POST["doctor_id"] ?? 0);
    $status_ui    = clean($_POST["status"] ?? 'Active');
    $status_db    = status_ui_to_db($status_ui);

    // Validări
    if ($first_name === '' || !preg_match('/^[A-Z][a-zA-Z]+$/', $first_name)) {
        $dash_errors[] = "First name invalid.";
    }
    if ($last_name === '' || !preg_match('/^[A-Z][a-zA-Z]+$/', $last_name)) {
        $dash_errors[] = "Last name invalid.";
    }
    if (!is_valid_cnp($cnp)) {
        $dash_errors[] = "CNP invalid (13 cifre sau gol).";
    }
    if ($email !== '' && !is_valid_email_custom($email)) {
        $dash_errors[] = "Email invalid.";
    }
    if (!is_valid_phone_9($phone)) {
        $dash_errors[] = "Telefon invalid (9 cifre).";
    }
    if (!is_valid_gender($gender)) {
        $dash_errors[] = "Gender invalid.";
    }
    // Validarea PHP: data nașterii nu poate fi în viitor.
    if ($birth_date !== '' && strtotime($birth_date) > time()) {
        $dash_errors[] = "Birth date nu poate fi în viitor.";
    }
    if ($visit_reason === '' || strlen($visit_reason) < 3) {
        $dash_errors[] = "Visit reason trebuie să aibă minim 3 caractere.";
    }
    if (!in_array($status_ui, ['Active','InTreatment','Recovered'], true)) {
        $dash_errors[] = "Status invalid.";
    }
    if ($diagnosis === '') {
        $diagnosis = "Nediagnosticat";
    }

    if (empty($dash_errors)) {
        $sql = "INSERT INTO patients
                (cnp, first_name, last_name, birth_date, gender, phone, email,
                 address, visit_reason, diagnosis, doctor_id, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssis",
                $cnp,
                $first_name,
                $last_name,
                $birth_date,
                $gender,
                $phone,
                $email,
                $address,
                $visit_reason,
                $diagnosis,
                $doctor_id,
                $status_db
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $dash_success = "Patient added successfully from Dashboard.";
        } else {
            $dash_errors[] = "Database error (patient).";
        }
    }
}

// ----------------------------------------------------
//      HANDLE ADD APPOINTMENT (din modal Dashboard)
// ----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === 'add_appointment_dash') {

    $patient_id  = (int)($_POST["patient_id"] ?? 0);
    $doctor_id   = (int)($_POST["doctor_id"] ?? 0);
    $nurse_raw   = (int)($_POST["nurse_id"] ?? 0);
    $nurse_id    = $nurse_raw > 0 ? $nurse_raw : null;

    $appointment_date = clean($_POST["appointment_date"] ?? '');
    $appointment_time = clean($_POST["appointment_time"] ?? '');
    $duration_minutes = (int)($_POST["duration_minutes"] ?? 30);
    $status           = clean($_POST["status"] ?? 'programata');
    $reason           = clean($_POST["reason"] ?? '');

    $payment_amount = clean($_POST["payment_amount"] ?? '');
    $payment_method = clean($_POST["payment_method"] ?? '');
    $payment_status = clean($_POST["payment_status"] ?? '');

    // Validări basic
    if ($patient_id <= 0) $dash_errors[] = "Patient is required.";
    if ($doctor_id  <= 0) $dash_errors[] = "Doctor is required.";
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointment_date)) $dash_errors[] = "Invalid date.";
    if (!preg_match('/^\d{2}:\d{2}$/', $appointment_time))        $dash_errors[] = "Invalid time (HH:MM).";
    if ($duration_minutes <= 0) $dash_errors[] = "Duration must be positive.";
    if ($reason === '' || strlen($reason) < 3) $dash_errors[] = "Reason must have 3+ characters.";

    // ******* VALIDARE PHP: DATA PROGRAMĂRII NU POATE FI ÎN TRECUT *******
    if ($appointment_date !== '') {
        $appointment_datetime = strtotime($appointment_date . ' ' . $appointment_time);
        // Verificăm dacă data programării este în trecut (mai mică decât începutul zilei de azi)
        if (strtotime($appointment_date) < strtotime('today')) {
             $dash_errors[] = "Data programării nu poate fi în trecut.";
        }
    }
    // *******************************************************************

    $valid_status = ['programata','efectuata','anulata','neprezentat'];
    if (!in_array($status, $valid_status, true)) $dash_errors[] = "Invalid status.";

    $payment_value = null;
    if ($payment_amount !== '') {
        $payment_value = floatval(str_replace(',', '.', $payment_amount));
        if ($payment_value < 0) $dash_errors[] = "Payment cannot be negative.";
    }

    if (empty($dash_errors)) {

        // Insert appointment
        $sql = "INSERT INTO appointments
                (patient_id, doctor_id, nurse_id, appointment_date, appointment_time,
                 duration_minutes, status, reason, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)";

        $created_by = $_SESSION["user_id"] ?? null;

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "iiississi",
            $patient_id, $doctor_id, $nurse_id,
            $appointment_date, $appointment_time,
            $duration_minutes, $status, $reason, $created_by
        );
        mysqli_stmt_execute($stmt);
        $id_appointment_new = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        // Optional payment
        if ($payment_value !== null && $id_appointment_new > 0) {
            $allowed_methods = ['cash','card','transfer'];
            if (!in_array($payment_method, $allowed_methods, true)) {
                $payment_method = 'cash';
            }
            $allowed_pstatus = ['platita','neplatita','returnata'];
            if (!in_array($payment_status, $allowed_pstatus, true)) {
                $payment_status = 'neplatita';
            }

            $payment_date = $appointment_date;
            $processed_by = $_SESSION["user_id"] ?? null;
            $notes        = "Created from Dashboard";

            $sqlp = "INSERT INTO payments
                         (patient_id, appointment_id, amount, payment_date,
                          payment_method, status, processed_by, notes)
                       VALUES (?,?,?,?,?,?,?,?)";
            $stmtp = mysqli_prepare($conn, $sqlp);
            mysqli_stmt_bind_param(
                $stmtp,
                "iidsssis",
                $patient_id, $id_appointment_new, $payment_value, $payment_date,
                $payment_method, $payment_status, $processed_by, $notes
            );
            mysqli_stmt_execute($stmtp);
            mysqli_stmt_close($stmtp);
        }

        $dash_success = "Appointment added successfully from Dashboard.";
    }
}

// ----------------------------------------------------
//      STATISTICI PENTRU CARDURI
// ----------------------------------------------------

// Total Patients
$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM patients");
$row = mysqli_fetch_assoc($res);
$total_patients = (int)($row['c'] ?? 0);

// Today appointments
$today = date('Y-m-d');
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = ?");
mysqli_stmt_bind_param($stmt, "s", $today);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
$todays_appointments = (int)($row['c'] ?? 0);
mysqli_stmt_close($stmt);

// Active Doctors
$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM doctors");
$row = mysqli_fetch_assoc($res);
$active_doctors = (int)($row['c'] ?? 0);

// Total Revenue (anul curent)
$currentYear = date('Y');
$stmt = mysqli_prepare($conn, "
    SELECT COALESCE(SUM(amount),0) AS total_rev
    FROM payments
    WHERE status='platita' AND YEAR(payment_date) = ?
");
mysqli_stmt_bind_param($stmt, "s", $currentYear);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
$year_revenue = (float)($row['total_rev'] ?? 0);
mysqli_stmt_close($stmt);

// ----------------------------------------------------
//      RECENT APPOINTMENTS (ultimele 3)
// ----------------------------------------------------
$sql_recent = "
    SELECT a.*,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM appointments a
    JOIN patients p ON p.id_patient = a.patient_id
    JOIN doctors d  ON d.id_doctor = a.doctor_id
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT 3
";
$res_recent = mysqli_query($conn, $sql_recent);
$recent_appointments = [];
if ($res_recent) {
    while ($r = mysqli_fetch_assoc($res_recent)) {
        $recent_appointments[] = $r;
    }
}

// Dropdown lists pt modale
$doc_result = mysqli_query($conn,"SELECT id_doctor,first_name,last_name FROM doctors ORDER BY last_name,first_name");
$all_doctors = [];
while ($d = mysqli_fetch_assoc($doc_result)) { $all_doctors[] = $d; }

// Trebuie să reinițializăm rezultatele MySQL după ce le-am consumat în PHP,
// altfel buclele while nu vor mai funcționa în HTML.
$patients_list_re = mysqli_query($conn,"SELECT id_patient,first_name,last_name FROM patients ORDER BY last_name,first_name");
$nurses_list_re   = mysqli_query($conn,"SELECT id_nurse,first_name,last_name FROM nurses ORDER BY last_name,first_name");


// rol user (admin / staff etc.)
$current_role = user_role(); // definit în auth.php
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - AMC Clinic</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
/* ----------------------------------------- */
/* DASHBOARD STYLE                       */
/* ----------------------------------------- */

.content-box.dashboard-page {
    padding: 25px 32px 40px;
    min-height: calc(100vh - 90px);
}

.dash-header-title {
    font-size: 28px;
    font-weight: 700;
    color: #003c46;
    margin-bottom: 4px;
}

.dash-header-subtitle {
    font-size: 14px;
    color: #666;
    margin-bottom: 22px;
}

/* TOP STAT CARDS */
.dash-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 18px;
}

.dash-stat-card {
    border-radius: 18px;
    padding: 18px 22px;
    color: #fff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.10);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 110px;
}

.dash-stat-label {
    font-size: 14px;
    font-weight: 500;
    opacity: 0.9;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dash-stat-icon {
    width: 32px;
    height: 32px;
    border-radius: 999px;
    background: rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.dash-stat-value {
    margin-top: 10px;
    font-size: 26px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.dash-stat-bottom {
    margin-top: 6px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
}

.dash-badge-change {
    padding: 3px 9px;
    border-radius: 999px;
    background: rgba(255,255,255,0.20);
    font-weight: 600;
}

.dash-badge-change span {
    font-size: 11px;
}

.dash-small-text {
    opacity: 0.9;
}

.stat-patients {
    background: linear-gradient(135deg, #00897b, #26a69a);
}
.stat-appointments {
    background: linear-gradient(135deg,#ff2f6d,#ff6a8d);
}
.stat-doctors {
    background: linear-gradient(135deg,#7647ff,#9d6bff);
}
.stat-revenue {
    background: linear-gradient(135deg,#ffb020,#ffcb4c);
}

/* MAIN ROW */
.dash-main-row {
    margin-top: 26px;
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 2fr);
    gap: 22px;
}

.dash-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 20px 22px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.06);
}

.dash-card h3 {
    font-size: 18px;
    font-weight: 600;
    color: #003c46;
    margin-bottom: 14px;
}

/* QUICK ACTIONS */
.qa-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 4px;
}

.qa-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 15px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.qa-btn-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.qa-icon-circle {
    width: 30px;
    height: 30px;
    border-radius: 999px;
    background: rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.qa-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.18);
    opacity: 0.96;
}

.qa-add-patient   { background: #00bfa6; }
.qa-add-appoint   { background: #ff2f6d; }
.qa-medical-report{ background: #7647ff; }
.qa-view-doctors  { background: #ffb020; }

.qa-chevron {
    font-size: 16px;
}

/* RECENT APPOINTMENTS */
.recent-list {
    margin-top: 6px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.recent-item {
    background: #f8f9ff;
    border-radius: 14px;
    padding: 10px 14px;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 10px;
    align-items: center;
}

.recent-avatar {
    width: 38px;
    height: 38px;
    border-radius: 999px;
    background: #e3e6ff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #4a3fd9;
    font-weight: 600;
}

.recent-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.recent-name {
    font-size: 14px;
    font-weight: 600;
    color: #12212f;
}

.recent-doctor {
    font-size: 12px;
    color: #6c7a89;
}

.recent-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.recent-time {
    font-size: 14px;
    font-weight: 600;
    color: #003c46;
}

.status-pill {
    font-size: 11px;
    padding: 3px 9px;
    border-radius: 999px;
    font-weight: 600;
}

.status-confirmed { background: #e1f8e9; color:#1b9a4a; }
.status-pending   { background: #fff4d9; color:#c19119; }
.status-cancelled { background: #ffe3e3; color:#d64545; }
.status-noshow    { background: #f3e6ff; color:#7a3bbf; }

.recent-empty {
    font-size: 13px;
    color: #777;
    margin-top: 4px;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 999;
}
.modal-overlay.show {
    display: flex;
}
.modal-box {
    width: 680px;
    max-width: 95%;
    background: #fff;
    border-radius: 18px;
    padding: 20px 22px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}
.modal-header h3 {
    font-size: 18px;
    font-weight: 600;
    color: #003c46;
}
.modal-close {
    border: none;
    background: #f1f3f5;
    border-radius: 999px;
    width: 28px;
    height: 28px;
    font-size: 18px;
    cursor: pointer;
}
.modal-form .modal-row {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 14px;
    margin-bottom: 10px;
}
.modal-form .form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.modal-form label {
    font-size: 13px;
    color: #555;
}
.modal-form input,
.modal-form select,
.modal-form textarea {
    border-radius: 10px;
    border: 1px solid #d0d4da;
    padding: 7px 10px;
    font-size: 13px;
}
.modal-footer {
    margin-top: 14px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

@media (max-width: 1100px) {
    .dash-stat-grid {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
    .dash-main-row {
        grid-template-columns: minmax(0,1fr);
    }
}
@media (max-width: 800px) {
    .dash-stat-grid {
        grid-template-columns: minmax(0,1fr);
    }
    .modal-form .modal-row {
        grid-template-columns: minmax(0,1fr);
    }
}
    </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box dashboard-page">

        <div class="dash-header">
            <h2 class="dash-header-title">Dashboard</h2>
            <p class="dash-header-subtitle">
                Welcome back, <strong><?= htmlspecialchars(user_name()) ?></strong> — here is an overview of AMC Clinic today.
            </p>
        </div>

        <?php if (!empty($dash_errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($dash_errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($dash_success !== ""): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($dash_success) ?>
            </div>
        <?php endif; ?>

        <div class="dash-stat-grid">

            <div class="dash-stat-card stat-patients">
                <div class="dash-stat-label">
                    <div class="dash-stat-icon">👥</div>
                    <span>Total Patients</span>
                </div>
                <div class="dash-stat-value"><?= number_format($total_patients, 0, '.', ',') ?></div>
                <div class="dash-stat-bottom">
                    <div class="dash-badge-change"><span>+12.5%</span></div>
                    <div class="dash-small-text">vs last month</div>
                </div>
            </div>

            <div class="dash-stat-card stat-appointments">
                <div class="dash-stat-label">
                    <div class="dash-stat-icon">📅</div>
                    <span>Today's Appointments</span>
                </div>
                <div class="dash-stat-value"><?= number_format($todays_appointments, 0, '.', ',') ?></div>
                <div class="dash-stat-bottom">
                    <div class="dash-badge-change"><span>+8.2%</span></div>
                    <div class="dash-small-text">compared to yesterday</div>
                </div>
            </div>

            <div class="dash-stat-card stat-doctors">
                <div class="dash-stat-label">
                    <div class="dash-stat-icon">🩺</div>
                    <span>Active Doctors</span>
                </div>
                <div class="dash-stat-value"><?= number_format($active_doctors, 0, '.', ',') ?></div>
                <div class="dash-stat-bottom">
                    <div class="dash-badge-change"><span>+2</span></div>
                    <div class="dash-small-text">new this year</div>
                </div>
            </div>

            <div class="dash-stat-card stat-revenue">
                <div class="dash-stat-label">
                    <div class="dash-stat-icon">💶</div>
                    <span>Total Revenue (<?= htmlspecialchars($currentYear) ?>)</span>
                </div>
                <div class="dash-stat-value">
                    <?= number_format($year_revenue, 2, '.', ',') ?> MDL
                </div>
                <div class="dash-stat-bottom">
                    <div class="dash-badge-change"><span>+15.3%</span></div>
                    <div class="dash-small-text">paid payments only</div>
                </div>
            </div>

        </div><div class="dash-main-row">

            <div class="dash-card">
                <h3>Quick Actions</h3>
                <div class="qa-list">

                    <button type="button" class="qa-btn qa-add-patient" id="qaAddPatient">
                        <div class="qa-btn-left">
                            <div class="qa-icon-circle">➕</div>
                            <span>Add Patient</span>
                        </div>
                        <span class="qa-chevron">›</span>
                    </button>

                    <button type="button" class="qa-btn qa-add-appoint" id="qaAddAppointment">
                        <div class="qa-btn-left">
                            <div class="qa-icon-circle">📆</div>
                            <span>New Appointment</span>
                        </div>
                        <span class="qa-chevron">›</span>
                    </button>

                    <button type="button"
                            class="qa-btn qa-medical-report"
                            id="qaMedicalReport"
                            data-role="<?= htmlspecialchars($current_role) ?>">
                        <div class="qa-btn-left">
                            <div class="qa-icon-circle">📑</div>
                            <span>
                                <?php if ($current_role === 'admin'): ?>
                                    Medical Report
                                <?php else: ?>
                                    View Schedules
                                <?php endif; ?>
                            </span>
                        </div>
                        <span class="qa-chevron">›</span>
                    </button>

                    <button type="button" class="qa-btn qa-view-doctors" id="qaViewDoctors">
                        <div class="qa-btn-left">
                            <div class="qa-icon-circle">🧑‍⚕️</div>
                            <span>View Doctors</span>
                        </div>
                        <span class="qa-chevron">›</span>
                    </button>

                </div>
            </div>

            <div class="dash-card">
                <h3>Recent Appointments</h3>

                <?php if (empty($recent_appointments)): ?>
                    <p class="recent-empty">No appointments found yet.</p>
                <?php else: ?>
                    <div class="recent-list">
                        <?php foreach ($recent_appointments as $ra): ?>
                            <?php
                                $patient_name = $ra['patient_name'];
                                $doctor_name  = $ra['doctor_name'];
                                $time_short   = substr($ra['appointment_time'],0,5);
                                $date_fmt     = date("M d", strtotime($ra['appointment_date']));

                                $status = $ra['status'];
                                $status_label = 'Pending';
                                $status_class = 'status-pending';

                                if ($status === 'efectuata') {
                                    $status_label = 'Confirmed';
                                    $status_class = 'status-confirmed';
                                } elseif ($status === 'anulata') {
                                    $status_label = 'Cancelled';
                                    $status_class = 'status-cancelled';
                                } elseif ($status === 'neprezentat') {
                                    $status_label = 'No-show';
                                    $status_class = 'status-noshow';
                                }
                            ?>
                            <div class="recent-item">
                                <div class="recent-avatar">
                                    <?= strtoupper(substr($patient_name,0,1)) ?>
                                </div>
                                <div class="recent-main">
                                    <div class="recent-name"><?= htmlspecialchars($patient_name) ?></div>
                                    <div class="recent-doctor">Dr. <?= htmlspecialchars($doctor_name) ?></div>
                                    <div class="recent-doctor"><?= htmlspecialchars($date_fmt) ?></div>
                                </div>
                                <div class="recent-meta">
                                    <div class="recent-time"><?= htmlspecialchars($time_short) ?></div>
                                    <div class="status-pill <?= $status_class ?>">
                                        <?= htmlspecialchars($status_label) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>

        </div></div></div><div class="modal-overlay" id="patientModalDash">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Add Patient</h3>
            <button type="button" class="modal-close" data-close-patient>&times;</button>
        </div>

        <form method="POST" id="patientFormDash" class="modal-form">
            <input type="hidden" name="form_type" value="add_patient_dash">

            <div class="modal-row">
                <div class="form-group">
                    <label>First name*</label>
                    <input type="text" name="first_name" id="dp_first_name" required>
                </div>
                <div class="form-group">
                    <label>Last name*</label>
                    <input type="text" name="last_name" id="dp_last_name" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>CNP (optional, 13 digits)</label>
                    <input type="text" name="cnp" id="dp_cnp" maxlength="13">
                </div>
                <div class="form-group">
                    <label>Birth date</label>
                    <input type="date" name="birth_date" id="dp_birth_date">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Gender*</label>
                    <select name="gender" id="dp_gender" required>
                        <option value="">Select gender...</option>
                        <option value="F">F</option>
                        <option value="M">M</option>
                        <option value="Altul">Altul</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Doctor</label>
                    <select name="doctor_id" id="dp_doctor_id">
                        <option value="0">None</option>
                        <?php foreach ($all_doctors as $doc): ?>
                            <option value="<?= $doc['id_doctor'] ?>">
                                <?= htmlspecialchars($doc['last_name'].' '.$doc['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Phone (9 digits)*</label>
                    <input type="text" name="phone" id="dp_phone" maxlength="9" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="dp_email">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" id="dp_address">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="dp_status">
                        <option value="Active">Active</option>
                        <option value="InTreatment">InTreatment</option>
                        <option value="Recovered">Recovered</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Visit reason*</label>
                <textarea name="visit_reason" id="dp_visit_reason" rows="2" required></textarea>
            </div>

            <div class="form-group">
                <label>Diagnostic</label>
                <textarea name="diagnosis" id="dp_diagnosis" rows="2"></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-patient>Cancel</button>
                <button type="submit" class="btn btn-primary">Save patient</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="appointmentModalDash">
    <div class="modal-box">
        <div class="modal-header">
            <h3>New Appointment</h3>
            <button type="button" class="modal-close" data-close-appointment>&times;</button>
        </div>

        <form method="POST" id="appointmentFormDash" class="modal-form">
            <input type="hidden" name="form_type" value="add_appointment_dash">

            <div class="modal-row">
                <div class="form-group">
                    <label>Patient*</label>
                    <select name="patient_id" id="da_patient_id" required>
                        <option value="">Select patient...</option>
                        <?php while ($p = mysqli_fetch_assoc($patients_list_re)): ?>
                            <option value="<?= $p['id_patient'] ?>">
                                <?= htmlspecialchars($p['last_name'].' '.$p['first_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Doctor*</label>
                    <select name="doctor_id" id="da_doctor_id" required>
                        <option value="">Select doctor...</option>
                        <?php foreach ($all_doctors as $doc): ?>
                            <option value="<?= $doc['id_doctor'] ?>">
                                <?= htmlspecialchars($doc['last_name'].' '.$doc['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Nurse</label>
                    <select name="nurse_id" id="da_nurse_id">
                        <option value="0">None</option>
                        <?php while ($n = mysqli_fetch_assoc($nurses_list_re)): ?>
                            <option value="<?= $n['id_nurse'] ?>">
                                <?= htmlspecialchars($n['last_name'].' '.$n['first_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Duration (minutes)</label>
                    <input type="number" name="duration_minutes" id="da_duration" value="30" min="5">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Date*</label>
                    <input type="date" name="appointment_date" id="da_date" required>
                </div>
                <div class="form-group">
                    <label>Time (HH:MM)*</label>
                    <input type="time" name="appointment_time" id="da_time" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="da_status">
                        <option value="programata">Programată</option>
                        <option value="efectuata">Efectuată</option>
                        <option value="anulata">Anulată</option>
                        <option value="neprezentat">Neprezentat</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Reason*</label>
                    <input type="text" name="reason" id="da_reason" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Payment amount (optional)</label>
                    <input type="number" step="0.01" name="payment_amount" id="da_amount">
                </div>
                <div class="form-group">
                    <label>Payment method</label>
                    <select name="payment_method" id="da_method">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="transfer">Transfer</option>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Payment status</label>
                    <select name="payment_status" id="da_status_pay">
                        <option value="platita">Paid</option>
                        <option value="neplatita">Unpaid</option>
                        <option value="returnata">Returned</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-appointment>Cancel</button>
                <button type="submit" class="btn btn-primary">Save appointment</button>
            </div>
        </form>
    </div>
</div>

<script>
// Quick Actions -> open modals / navigate
document.getElementById('qaAddPatient')?.addEventListener('click', () => {
    document.getElementById('patientModalDash').classList.add('show');
});
document.getElementById('qaAddAppointment')?.addEventListener('click', () => {
    document.getElementById('appointmentModalDash').classList.add('show');
});

// Medical Report / Schedules logic by role
document.getElementById('qaMedicalReport')?.addEventListener('click', (e) => {
    const role = e.currentTarget.getAttribute("data-role");
    if (role === "admin") {
        window.location.href = "reports.php";
    } else {
        window.location.href = "schedules.php";
    }
});

document.getElementById('qaViewDoctors')?.addEventListener('click', () => {
    window.location.href = "doctors.php";
});

// Close modals
document.querySelectorAll('[data-close-patient]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('patientModalDash').classList.remove('show');
    });
});

document.querySelectorAll('[data-close-appointment]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('appointmentModalDash').classList.remove('show');
    });
});

window.addEventListener('keydown', (e) => {
    if (e.key === "Escape") {
        document.getElementById('patientModalDash').classList.remove('show');
        document.getElementById('appointmentModalDash').classList.remove('show');
    }
});

// Validare simplă JS pentru Add Patient
document.getElementById('patientFormDash')?.addEventListener('submit', (e) => {
    const fn    = document.getElementById('dp_first_name').value.trim();
    const ln    = document.getElementById('dp_last_name').value.trim();
    const phone = document.getElementById('dp_phone').value.trim();
    const gender= document.getElementById('dp_gender').value;
    const vr    = document.getElementById('dp_visit_reason').value.trim();
    const cnp   = document.getElementById('dp_cnp').value.trim();
    const bd    = document.getElementById('dp_birth_date').value; 

    const nameRegex = /^[A-Z][a-zA-Z]+$/;

    // *** VALIDĂRI CU ALERT() ***

    if (!nameRegex.test(fn) || !nameRegex.test(ln)) {
        alert("First/Last name trebuie cu literă mare și doar litere.");
        e.preventDefault();
        return;
    }
    
    // Validarea datei de naștere în viitor
    if (bd !== "") {
        const selectedDate = new Date(bd);
        const today = new Date();
        today.setHours(0, 0, 0, 0); 
        if (selectedDate > today) {
            alert("Data de naștere nu poate fi în viitor.");
            e.preventDefault();
            return;
        }
    }
    
    if (!/^[0-9]{9}$/.test(phone)) {
        alert("Telefonul trebuie să aibă 9 cifre.");
        e.preventDefault();
        return;
    }
    if (!gender) {
        alert("Selectează gender.");
        e.preventDefault();
        return;
    }
    if (vr.length < 3) {
        alert("Visit reason minim 3 caractere.");
        e.preventDefault();
        return;
    }
    if (cnp !== "" && !/^[0-9]{13}$/.test(cnp)) {
        alert("CNP = 13 cifre (sau lasă gol).");
        e.preventDefault();
        return;
    }

});

// Validare simplă JS pentru Add Appointment
document.getElementById('appointmentFormDash')?.addEventListener('submit', (e) => {
    const p      = document.getElementById('da_patient_id').value;
    const d      = document.getElementById('da_doctor_id').value;
    const date   = document.getElementById('da_date').value;
    const time   = document.getElementById('da_time').value;
    const reason = document.getElementById('da_reason').value.trim();
    const amount = document.getElementById('da_amount').value.trim();

    // *** VALIDĂRI CU ALERT() ***

    if (!p || !d || !date || !time) {
        alert("Patient, doctor, date și time sunt obligatorii.");
        e.preventDefault();
        return;
    }
    
    // ******* VALIDARE JS: DATA PROGRAMĂRII NU POATE FI ÎN TRECUT *******
    if (date !== "") {
        // Obținem data curentă la începutul zilei (fără oră/minut)
        const today = new Date();
        today.setHours(0, 0, 0, 0); 

        // Obținem data selectată
        const selectedDate = new Date(date);
        selectedDate.setHours(0, 0, 0, 0); 

        if (selectedDate < today) {
             alert("Data programării nu poate fi în trecut."); // Mesajul de alertă
             e.preventDefault();
             return;
        }
    }
    // *******************************************************************
    
    if (reason.length < 3) {
        alert("Reason minim 3 caractere.");
        e.preventDefault();
        return;
    }
    if (amount !== "" && parseFloat(amount) < 0) {
        alert("Amount nu poate fi negativ.");
        e.preventDefault();
    }
});
</script>

<script src="assets/main.js" defer></script>

</body>
</html>