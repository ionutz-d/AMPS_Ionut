<?php
// appointments.php
$page_title = "Appointments";
$active     = "appointments";

require_once "auth.php";
require_login();
require_once "db.php";

$errors  = [];
$success = "";

/* ============================================================
    HELPERI
============================================================ */
function clean($v) { return trim($v ?? ''); }

function is_valid_date($d) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

function is_valid_time($t) {
    return preg_match('/^\d{2}:\d{2}$/', $t) || preg_match('/^\d{2}:\d{2}:\d{2}$/', $t);
}

function format_payment_label($amount, $method, $status) {
    if ($amount === null) return "—";
    $amount_str = number_format((float)$amount, 2, ',', '.');

    $method_label = [
        'cash'     => 'cash',
        'card'     => 'card',
        'transfer' => 'transfer'
    ][$method] ?? $method;

    return $amount_str . " (" . $method_label . ")";
}

/* ============================================================
    ADD / EDIT APPOINTMENT
============================================================ */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $mode           = $_POST["form_mode"] ?? "add";
    $id_appointment = (int)($_POST["id_appointment"] ?? 0);

    $patient_id  = (int)($_POST["patient_id"] ?? 0);
    $doctor_id   = (int)($_POST["doctor_id"] ?? 0);
    $nurse_raw   = (int)($_POST["nurse_id"] ?? 0);
    $nurse_id    = $nurse_raw > 0 ? $nurse_raw : null;

    $appointment_date = clean($_POST["appointment_date"] ?? '');
    $appointment_time = clean($_POST["appointment_time"] ?? '');
    $duration_minutes = (int)($_POST["duration_minutes"] ?? 30);
    $status           = clean($_POST["status"] ?? 'programata');
    $reason           = clean($_POST["reason"] ?? '');

    // payment inputs
    $payment_amount_raw = clean($_POST["payment_amount"] ?? ''); // Folosim raw pentru a verifica dacă e gol
    $payment_method     = clean($_POST["payment_method"] ?? '');
    $payment_status     = clean($_POST["payment_status"] ?? '');
    $cash_given_raw     = clean($_POST["cash_given"] ?? '');

    /* VALIDĂRI PRINCIPALE */
    if ($patient_id <= 0) $errors[] = "Patient is required.";
    if ($doctor_id <= 0)  $errors[] = "Doctor is required.";
    if (!is_valid_date($appointment_date)) $errors[] = "Invalid date format.";
    if (!is_valid_time($appointment_time)) $errors[] = "Invalid time format.";
    
    // Validare Durată
    if ($duration_minutes < 30 || $duration_minutes > 90) {
        $errors[] = "Duration must be between 30 and 90 minutes.";
    }

    $valid_status = ['programata','efectuata','anulata','neprezentat'];
    if (!in_array($status, $valid_status)) $errors[] = "Invalid status.";
    if ($reason === "" || strlen($reason) < 3) $errors[] = "Reason must have 3+ characters.";

    // Validare Dată (Viitor, Începând cu Mâine)
    if (is_valid_date($appointment_date)) {
        $today = new DateTime('tomorrow');
        $appointment_dt = new DateTime($appointment_date);
        
        if ($appointment_dt < $today) {
            $errors[] = "Appointment date must be tomorrow or later.";
        }
    }

    /* =========================================================
       VALIDARE STRICTĂ PLATĂ (NOU)
       Toate câmpurile de plată sunt OBLIGATORII
    ========================================================= */
    
    // 1. Amount (obligatoriu)
    if ($payment_amount_raw === '') {
        $errors[] = "Payment amount is required for all appointments.";
        $payment_value = null; // Asigurăm că nu procesăm plată incompletă
    } else {
        $payment_value = floatval(str_replace(',', '.', $payment_amount_raw));
        if ($payment_value < 0) $errors[] = "Payment amount cannot be negative.";
    }

    // Continuăm validarea doar dacă Amount e valid și există
    if ($payment_value !== null && $payment_value >= 0 && empty($errors)) {
        
        // 2. Method (obligatoriu)
        $allowed_methods = ['cash','card','transfer'];
        if (!in_array($payment_method, $allowed_methods)) {
            $errors[] = "Payment method is required.";
        }

        // 3. Status (obligatoriu)
        $allowed_pstatus = ['platita','neplatita','returnata'];
        if (!in_array($payment_status, $allowed_pstatus)) {
            $errors[] = "Payment status is required.";
        }

        // 4. Cash Given (obligatoriu doar pentru Cash)
        if ($payment_method === 'cash') {
            if ($cash_given_raw === '') {
                 $errors[] = "Cash given is required for cash payment.";
            } else {
                $cash_val = floatval(str_replace(',', '.', $cash_given_raw));
                if ($cash_val < $payment_value) {
                     $errors[] = "Cash given must be greater than or equal to the amount.";
                }
            }
        }
        
    } else {
        // Dacă Amount lipsește/e invalid, asigurăm că Method și Status sunt goale în DB
        $payment_method = "";
        $payment_status = "";
        $cash_given = null; // Setăm la null pentru logica DB/Notes
    }


    /* ELIMINAREA LOGICII DE VERIFICARE A DISPONIBILITĂȚII DOCTORULUI (schedule/suprapunere) */
    // Verifică doar suprapunerea ORA-O-ORĂ
    if (empty($errors)) {
        
        $appointment_check_query = "SELECT id_appointment FROM appointments 
                                    WHERE doctor_id = ? 
                                    AND appointment_date = ? 
                                    AND appointment_time = ? 
                                    AND id_appointment != ?";
        $stmt_check = mysqli_prepare($conn, $appointment_check_query);
        
        $time_check = substr($appointment_time, 0, 5); 

        mysqli_stmt_bind_param($stmt_check, "issi", $doctor_id, $appointment_date, $time_check, $id_appointment);
        mysqli_stmt_execute($stmt_check);
        $check_result = mysqli_stmt_get_result($stmt_check);
        
        if (mysqli_num_rows($check_result) > 0) {
            $errors[] = "This exact start time slot is already occupied by another patient for this doctor.";
        }
    }


    /* INSERT / UPDATE */
    if (empty($errors)) {

        // Logica INSERT/UPDATE pentru programare (Rămâne neschimbată)
        if ($mode === "add") {
            $sql = "INSERT INTO appointments (patient_id, doctor_id, nurse_id, appointment_date, appointment_time, duration_minutes, status, reason, created_by) VALUES (?,?,?,?,?,?,?,?,?)";
            $created_by = $_SESSION["user_id"] ?? null;
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt,"iiississi", $patient_id,$doctor_id,$nurse_id, $appointment_date,$appointment_time, $duration_minutes,$status,$reason,$created_by);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $id_appointment = mysqli_insert_id($conn);
            $success = "Appointment added successfully.";
        } else {
            $sql = "UPDATE appointments SET patient_id=?, doctor_id=?, nurse_id=?, appointment_date=?, appointment_time=?, duration_minutes=?, status=?, reason=? WHERE id_appointment=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iiississi", $patient_id,$doctor_id,$nurse_id, $appointment_date,$appointment_time, $duration_minutes,$status,$reason,$id_appointment);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $success = "Appointment updated successfully.";
        }

        /* LOGICA PLĂȚII */
        if ($id_appointment > 0 && $payment_value !== null) { // Dacă validarea plății a trecut

            $pid = null;
            $stmtp = mysqli_prepare($conn,"SELECT id_payment FROM payments WHERE appointment_id=? LIMIT 1");
            mysqli_stmt_bind_param($stmtp,"i",$id_appointment);
            mysqli_stmt_execute($stmtp);
            mysqli_stmt_bind_result($stmtp,$pid);
            mysqli_stmt_fetch($stmtp);
            mysqli_stmt_close($stmtp);

            $processed_by = $_SESSION["user_id"] ?? null;
            $notes = null;

            if ($payment_method === 'cash' && $cash_given_raw !== '') {
                $c = floatval(str_replace(',', '.', $cash_given_raw));
                $change = max(0, $c - $payment_value);
                $notes = "Cash given: $c, change: $change";
            }
            
            if ($pid === null) {
                // INSERT Payment
                $sqlp = "INSERT INTO payments (patient_id,appointment_id,amount,payment_date,payment_method, status,processed_by,notes) VALUES (?,?,?,?,?,?,?,?)";
                $payment_date = $appointment_date;
                $stmtp = mysqli_prepare($conn,$sqlp);
                mysqli_stmt_bind_param($stmtp,"iidsssis", $patient_id,$id_appointment,$payment_value,$payment_date, $payment_method,$payment_status,$processed_by,$notes);
                mysqli_stmt_execute($stmtp);
                mysqli_stmt_close($stmtp);
            } else {
                // UPDATE Payment
                $sqlp = "UPDATE payments SET patient_id=?, amount=?, payment_date=?, payment_method=?, status=?, processed_by=?, notes=? WHERE id_payment=?";
                $payment_date = $appointment_date;
                $stmtp = mysqli_prepare($conn,$sqlp);
                mysqli_stmt_bind_param($stmtp,"idsssisi", $patient_id,$payment_value,$payment_date,$payment_method, $payment_status,$processed_by,$notes,$pid);
                mysqli_stmt_execute($stmtp);
                mysqli_stmt_close($stmtp);
            }
        
        } else if ($id_appointment > 0 && $payment_value === null && $pid !== null) {
            // Caz special: dacă era o plată înregistrată, dar acum nu mai e validă (nu ar trebui să se întâmple cu validarea strictă, dar este o protecție)
             $stmtp = mysqli_prepare($conn,"DELETE FROM payments WHERE id_payment=? LIMIT 1");
             mysqli_stmt_bind_param($stmtp,"i",$pid);
             mysqli_stmt_execute($stmtp);
             mysqli_stmt_close($stmtp);
        }

        header("Location: appointments.php?msg=".urlencode($success));
        exit;
    }
}

/* Restul codului PHP pentru DELETE, SEARCH, LIST, și HTML/CSS Rămân APROAPE IDENTICE */

// ... (Secțiunea DELETE, SEARCH, LIST - Neschimbate) ...

/* ============================================================
    SEARCH + SORT + PAGINATION
============================================================ */
$search = clean($_GET["q"] ?? '');
$sort   = $_GET["sort"] ?? 'date_old';

$sort_sql = "ORDER BY a.appointment_date ASC, a.appointment_time ASC";

if ($sort === "date_new")       $sort_sql = "ORDER BY a.appointment_date DESC, a.appointment_time DESC";
if ($sort === "status")         $sort_sql = "ORDER BY a.status ASC";
if ($sort === "duration_high") $sort_sql = "ORDER BY a.duration_minutes DESC";
if ($sort === "duration_low")   $sort_sql = "ORDER BY a.duration_minutes ASC";

// Re-executăm interogările pentru dropdown-uri
$patients_list_res = mysqli_query($conn,"SELECT id_patient,first_name,last_name FROM patients ORDER BY last_name,first_name");
$doctors_list_res  = mysqli_query($conn,"SELECT id_doctor,first_name,last_name FROM doctors ORDER BY last_name,first_name");
$nurses_list_res   = mysqli_query($conn,"SELECT id_nurse,first_name,last_name FROM nurses ORDER BY last_name,first_name");

/* COUNT TOTAL AND PAGINATION */
$sql_count = "
    SELECT COUNT(*) total
    FROM appointments a
    JOIN patients p ON p.id_patient=a.patient_id
    JOIN doctors d 	ON d.id_doctor=a.doctor_id
    WHERE 1
";

$count_params = [];
$count_types  = "";

if ($search !== "") {
    $sql_count .= " AND (
        CONCAT(p.first_name,' ',p.last_name) LIKE ? 
        OR CONCAT(d.first_name,' ',d.last_name) LIKE ? 
        OR a.reason LIKE ?
    )";
    $like = "%$search%";
    $count_params = [$like,$like,$like];
    $count_types = "sss";
}

if (!empty($count_params)) {
    $stmt_count = mysqli_prepare($conn,$sql_count);
    mysqli_stmt_bind_param($stmt_count,$count_types,...$count_params);
    mysqli_stmt_execute($stmt_count);
    $res_total = mysqli_stmt_get_result($stmt_count);
} else {
    $res_total = mysqli_query($conn,$sql_count);
}

$total_appointments = (int)mysqli_fetch_assoc($res_total)["total"];

$results_per_page = 5;
$total_pages = max(1, ceil($total_appointments / $results_per_page));
$page = max(1, (int)($_GET["page"] ?? 1));
$offset = ($page - 1) * $results_per_page;


/* MAIN LIST */
$sql_list = "
    SELECT a.*,
           CONCAT(p.first_name,' ',p.last_name) patient_name,
           CONCAT(d.first_name,' ',d.last_name) doctor_name,
           CONCAT(n.first_name,' ',n.last_name) nurse_name,
           pay.amount AS pay_amount,
           pay.payment_method AS pay_method,
           pay.status AS pay_status
    FROM appointments a
    JOIN patients p ON p.id_patient=a.patient_id
    JOIN doctors d 	ON d.id_doctor=a.doctor_id
    LEFT JOIN nurses n ON n.id_nurse=a.nurse_id
    LEFT JOIN payments pay ON pay.appointment_id=a.id_appointment
    WHERE 1
";

$params = $count_params; 
$types  = $count_types;

if ($search !== "") {
    $sql_list .= " AND (
        CONCAT(p.first_name,' ',p.last_name) LIKE ? 
        OR CONCAT(d.first_name,' ',d.last_name) LIKE ? 
        OR a.reason LIKE ?
    )";
}

$sql_list .= " $sort_sql LIMIT $results_per_page OFFSET $offset";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn,$sql_list);
    mysqli_stmt_bind_param($stmt,$types,...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn,$sql_list);
}

$appointments = [];
while ($r = mysqli_fetch_assoc($result)) {
    $appointments[] = $r;
}

/* mesaj */
if (isset($_GET["msg"])) $success = $_GET["msg"];
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Appointments - AMC Clinic</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 25px;
            margin-bottom: 10px;
        }
        .pagination a {
            padding: 7px 14px;
            background: #ffffff;
            border: 1px solid #d0d0d0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #003c46;
            text-decoration: none;
            transition: 0.2s ease;
        }
        .pagination a:hover {
            background: #00bfa6;
            border-color: #00bfa6;
            color: #fff;
        }
        .pagination a.active {
            background: #00796b;
            color: #fff !important;
            border-color: #00796b;
            font-weight: 600;
        }

        /* Stil pentru modal-overlay pentru a se deschide/închide */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-box {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 90%;
            transform: translateY(-50px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.show .modal-box {
            transform: translateY(0);
        }

        /* un pic de stil pentru popup-ul de delete */
        #deleteConfirm .modal-box {
            max-width: 420px;
        }
        #deleteConfirm .modal-body p {
            font-size: 16px;
            color: #444;
            margin-bottom: 20px;
        }
        .btn-danger {
            background: #d32f2f;
            color: #fff;
        }
        .btn-danger:hover {
            background: #b71c1c;
        }
    </style>
</head>
<body>

<?php include "sidebar.php"; ?>
<div class="main-content">
<?php include "header.php"; ?>

<div class="content-box appointments-page">

    <div class="doctors-header">
        <h2>Appointments</h2>
        <p class="subtitle">Manage all appointments in the clinic.</p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="cards-row">
        <div class="card card-overview">
            <h3>Appointments overview</h3>
            <div class="overview-number"><?= $total_appointments ?></div>
            <p class="overview-text">Total appointments</p>
        </div>

        <div class="card card-search">
            <h3>Search & sort</h3>
            <form method="GET" class="search-form">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="q" placeholder="Ex: Ion, Popescu" value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="form-group">
                    <label>Sort by</label>
                    <select name="sort">
                        <option value="date_old"      <?= $sort=='date_old' ? 'selected':'' ?>>Date (old→new)</option>
                        <option value="date_new"      <?= $sort=='date_new' ? 'selected':'' ?>>Date (new→old)</option>
                        <option value="status"        <?= $sort=='status' ? 'selected':'' ?>>Status</option>
                        <option value="duration_high" <?= $sort=='duration_high' ? 'selected':'' ?>>Duration (high→low)</option>
                        <option value="duration_low"  <?= $sort=='duration_low' ? 'selected':'' ?>>Duration (low→high)</option>
                    </select>
                </div>

                <button class="btn btn-apply">Apply</button>
            </form>
        </div>
    </div>

    <div class="card doctors-list-card">
        <div class="card-header-row">
            <h3>Appointments list</h3>
            <button class="btn btn-add" id="btnAddAppointment">+ Add Appointment</button>
        </div>

        <div class="table-wrapper">
            <table class="doctors-table">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Date & Time</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th>Payment</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($appointments)): ?>
                    <tr><td colspan="8" class="no-data">No appointments found.</td></tr>
                <?php else: ?>
                <?php foreach ($appointments as $a): ?>
                    <?php 
                        $payment = format_payment_label($a["pay_amount"],$a["pay_method"],$a["pay_status"]);
                        $dt = $a["appointment_date"]." ".substr($a["appointment_time"],0,5);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($a["patient_name"]) ?></td>
                        <td><?= htmlspecialchars($a["doctor_name"]) ?></td>
                        <td><?= htmlspecialchars($dt) ?></td>
                        <td><?= (int)$a["duration_minutes"] ?> min</td>
                        <td><?= htmlspecialchars($a["status"]) ?></td>
                        <td><?= htmlspecialchars($a["reason"]) ?></td>
                        <td><?= htmlspecialchars($payment) ?></td>

                        <td class="actions">

                            <button class="link-btn edit-btn"
                                data-id="<?= $a['id_appointment'] ?>"
                                data-patient_id="<?= (int)$a['patient_id'] ?>"
                                data-doctor_id="<?= (int)$a['doctor_id'] ?>"
                                data-nurse_id="<?= (int)$a['nurse_id'] ?>"
                                data-date="<?= htmlspecialchars($a['appointment_date']) ?>"
                                data-time="<?= htmlspecialchars(substr($a['appointment_time'],0,5)) ?>"
                                data-duration="<?= (int)$a['duration_minutes'] ?>"
                                data-status="<?= htmlspecialchars($a['status']) ?>"
                                data-reason="<?= htmlspecialchars($a['reason']) ?>"
                                data-pay_amount="<?= htmlspecialchars($a['pay_amount'] ?? '') ?>"
                                data-pay_method="<?= htmlspecialchars($a['pay_method'] ?? '') ?>"
                                data-pay_status="<?= htmlspecialchars($a['pay_status'] ?? '') ?>"
                            >Edit</button>

                            <button class="link-btn delete-btn"
                                data-id="<?= $a['id_appointment'] ?>"
                            >Delete</button>

                        </td>

                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn">Prev</a>
            <?php endif; ?>

            <?php for ($i=1; $i<=$total_pages; $i++): ?>
                <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>"
                   class="pag-btn <?= $i==$page ? 'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="pag-btn">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

</div>
</div>

<div class="modal-overlay" id="appointmentModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Add Appointment</h3>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>

        <form method="POST" id="appointmentForm" class="modal-form">
            <input type="hidden" name="form_mode" id="formMode" value="add">
            <input type="hidden" name="id_appointment" id="appointmentId" value="0">

            <div class="modal-row">
                <div class="form-group">
                    <label>Patient*</label>
                    <select name="patient_id" id="patient_id" required>
                        <option value="">Select...</option>
                        <?php 
                        mysqli_data_seek($patients_list_res, 0);
                        while ($p = mysqli_fetch_assoc($patients_list_res)): 
                        ?>
                            <option value="<?= $p['id_patient'] ?>">
                                <?= htmlspecialchars($p['last_name'].' '.$p['first_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Doctor*</label>
                    <select name="doctor_id" id="doctor_id" required>
                        <option value="">Select...</option>
                        <?php 
                        mysqli_data_seek($doctors_list_res, 0);
                        while ($d = mysqli_fetch_assoc($doctors_list_res)): 
                        ?>
                            <option value="<?= $d['id_doctor'] ?>">
                                <?= htmlspecialchars($d['last_name'].' '.$d['first_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Nurse</label>
                    <select name="nurse_id" id="nurse_id">
                        <option value="0">None</option>
                        <?php 
                        mysqli_data_seek($nurses_list_res, 0);
                        while ($n = mysqli_fetch_assoc($nurses_list_res)): 
                        ?>
                            <option value="<?= $n['id_nurse'] ?>">
                                <?= htmlspecialchars($n['last_name'].' '.$n['first_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Duration (minutes)</label>
                    <input type="number" name="duration_minutes" id="duration_minutes" value="30" min="30" max="90" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Date*</label>
                    <input type="date" name="appointment_date" id="appointment_date" required>
                </div>

                <div class="form-group">
                    <label>Time*</label>
                    <input type="time" name="appointment_time" id="appointment_time" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="programata">Programată</option>
                        <option value="efectuata">Efectuată</option>
                        <option value="anulata">Anulată</option>
                        <option value="neprezentat">Neprezentat</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Reason*</label>
                    <input type="text" name="reason" id="reason" required>
                </div>
            </div>

            <h3 class="section-title">Payment - (Required)</h3>

            <div class="modal-row">
                <div class="form-group">
                    <label>Amount*</label>
                    <input type="number" step="0.01" name="payment_amount" id="payment_amount" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label>Method*</label>
                    <select name="payment_method" id="payment_method" required>
                        <option value="">Select...</option>
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="transfer">Transfer</option>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Status*</label>
                    <select name="payment_status" id="payment_status" required>
                        <option value="">Select...</option>
                        <option value="platita">Paid</option>
                        <option value="neplatita">Unpaid</option>
                        <option value="returnata">Returned</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Cash given (Required for Cash)</label>
                    <input type="number" step="0.01" name="cash_given" id="cash_given" disabled>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Change</label>
                    <input type="text" id="change_return" disabled>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save</button>
            </div>

        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteConfirm">
    <div class="modal-box delete-box">
        <div class="modal-header">
            <h3>Confirm delete</h3>
            <button type="button" class="modal-close" id="closeDeletePopup">&times;</button>
        </div>

        <div class="modal-body">
            <p>Are you sure you want to delete this appointment?</p>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<script>
// ---------- UTILITY: GET TOMORROW'S DATE ----------
function getTomorrowDate() {
    const today = new Date();
    today.setDate(today.getDate() + 1);
    
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    
    return `${year}-${month}-${day}`;
}


// ---------- MODAL ADD / EDIT ----------
const appointmentModal = document.getElementById('appointmentModal');
const btnAddAppointment = document.getElementById('btnAddAppointment');
const closeModalBtn = document.getElementById('closeModal');
const cancelModal = document.getElementById('cancelModal');
const modalTitle = document.getElementById('modalTitle');
const formModeInput = document.getElementById('formMode');
const appointmentIdInput = document.getElementById('appointmentId');
const saveBtn = document.getElementById('saveBtn');

// Elemente de plată
const paymentMethodSelect = document.getElementById('payment_method');
const paymentAmountInput = document.getElementById('payment_amount');
const paymentStatusSelect = document.getElementById('payment_status');
const cashGivenInput = document.getElementById('cash_given');
const changeReturnInput = document.getElementById('change_return');

function openModal() {
    appointmentModal.classList.add('show');
}
function closeModal() {
    appointmentModal.classList.remove('show');
}

closeModalBtn.addEventListener('click', closeModal);
cancelModal.addEventListener('click', closeModal);

// ---------- PAYMENT LOGIC ----------
function calculateChange() {
    const amountVal = parseFloat(paymentAmountInput.value.replace(',', '.') || "0");
    const cashVal = parseFloat(cashGivenInput.value.replace(',', '.') || "0");
    let change = cashVal - amountVal;
    
    if (isNaN(change) || change < 0) {
        changeReturnInput.value = "";
    } else {
        changeReturnInput.value = change.toFixed(2);
    }
}

function handlePaymentMethodChange() {
    if (paymentMethodSelect.value === 'cash') {
        cashGivenInput.disabled = false;
        // Asigură că Cash Given este marcat ca obligatoriu în interfață (deși validarea strictă e pe server/js submit)
        // cashGivenInput.required = true; 
        calculateChange();
    } else {
        cashGivenInput.disabled = true;
        // cashGivenInput.required = false;
        cashGivenInput.value = "";
        changeReturnInput.value = "";
    }
}

paymentMethodSelect.addEventListener('change', handlePaymentMethodChange);
paymentAmountInput.addEventListener('input', calculateChange);
cashGivenInput.addEventListener('input', calculateChange);


// ---------- BUTONUL ADD ----------
btnAddAppointment.addEventListener('click', () => {
    modalTitle.textContent = "Add Appointment";
    formModeInput.value = "add";
    appointmentIdInput.value = "0";

    // Resetare câmpuri programare
    document.getElementById('patient_id').value = "";
    document.getElementById('doctor_id').value = "";
    document.getElementById('nurse_id').value = "0";
    document.getElementById('duration_minutes').value = "30";
    document.getElementById('status').value = "programata";
    document.getElementById('reason').value = "";

    // Setare dată minimă la mâine
    document.getElementById('appointment_date').value = getTomorrowDate();
    document.getElementById('appointment_date').setAttribute('min', getTomorrowDate());
    document.getElementById('appointment_time').value = "";

    // Resetare câmpuri plată (cu select la Select...)
    document.getElementById('payment_amount').value = "";
    document.getElementById('payment_method').value = "";
    document.getElementById('payment_status').value = "";
    
    handlePaymentMethodChange(); // Setează cash_given ca disabled

    saveBtn.textContent = "Save appointment";
    openModal();
});

// ---------- EDIT BUTTONS ----------
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        modalTitle.textContent = "Edit Appointment";
        formModeInput.value = "edit";
        appointmentIdInput.value = btn.dataset.id;
        
        // Setare dată minimă la mâine (pentru a bloca schimbarea în trecut)
        document.getElementById('appointment_date').setAttribute('min', getTomorrowDate());

        // Populare câmpuri
        document.getElementById('patient_id').value = btn.dataset.patient_id;
        document.getElementById('doctor_id').value = btn.dataset.doctor_id;
        document.getElementById('nurse_id').value = btn.dataset.nurse_id || "0";
        document.getElementById('appointment_date').value = btn.dataset.date;
        document.getElementById('appointment_time').value = btn.dataset.time;
        document.getElementById('duration_minutes').value = btn.dataset.duration;
        document.getElementById('status').value = btn.dataset.status;
        document.getElementById('reason').value = btn.dataset.reason;

        document.getElementById('payment_amount').value = btn.dataset.pay_amount || "";
        document.getElementById('payment_method').value = btn.dataset.pay_method || "";
        document.getElementById('payment_status').value = btn.dataset.pay_status || "";
        
        // Nu avem data-cash_given, deci îl resetăm, dar activăm/dezactivăm corect
        cashGivenInput.value = ""; 
        handlePaymentMethodChange(); 
        
        // Dacă e cash, recalculăm change, chiar dacă cash_given e gol
        if (btn.dataset.pay_method === 'cash') {
            // Dacă valoarea cash_given nu e furnizată la edit, utilizatorul o va completa.
            // Puteți seta o valoare implicită aici (ex: amount-ul) dacă nu doriți să ceară reintroducerea sumei.
            // Pentru strictețe, lăsăm gol/0, forțând utilizatorul să introducă o valoare >= amount.
            calculateChange();
        }

        saveBtn.textContent = "Update appointment";
        openModal();
    });
});

// ---------- VALIDARE FINALĂ PE CLIENT (înainte de trimitere) ----------
const appointmentForm = document.getElementById('appointmentForm');
appointmentForm.addEventListener('submit', (e) => {
    // Colectare date
    const patient = document.getElementById('patient_id').value;
    const doctor = document.getElementById('doctor_id').value;
    const date = document.getElementById('appointment_date').value;
    const reason = document.getElementById('reason').value.trim();
    const duration = parseInt(document.getElementById('duration_minutes').value);

    const amount = document.getElementById('payment_amount').value.trim();
    const method = document.getElementById('payment_method').value;
    const pstatus = document.getElementById('payment_status').value;
    const cash = document.getElementById('cash_given').value.trim();

    // 1. Validări obligatorii de bază
    if (!patient || !doctor || !date || !reason) {
        alert("Patient, doctor, date, time and reason are required.");
        e.preventDefault();
        return;
    }
    
    // 2. Validare durată
    if (duration < 30 || duration > 90 || isNaN(duration)) {
        alert("Duration must be between 30 and 90 minutes.");
        e.preventDefault();
        return;
    }

    // 3. Validare Dată (împotriva revenirii în trecut după deschiderea modalului)
    if (date && date < getTomorrowDate()) {
        alert("Appointment date cannot be in the past (must be tomorrow or later).");
        e.preventDefault();
        return;
    }

    // 4. VALIDARE STRICTĂ PLATĂ: Toate câmpurile de plată sunt acum obligatorii.
    
    // 4a. Amount (Obligatoriu)
    if (amount === "") {
        alert("Payment Amount is required.");
        e.preventDefault();
        return;
    }
    const amountVal = parseFloat(amount);
    if (amountVal < 0 || isNaN(amountVal)) {
        alert("Payment amount must be a valid non-negative number.");
        e.preventDefault();
        return;
    }
    
    // 4b. Method și Status (Obligatorii)
    if (!method) {
        alert("Payment method is required.");
        e.preventDefault();
        return;
    }
    if (!pstatus) {
         alert("Payment status is required.");
        e.preventDefault();
        return;
    }

    // 4c. Cash Given (Obligatoriu doar pentru Cash)
    if (method === "cash") {
        if (cash === "") {
            alert("Cash given is required for cash payment.");
            e.preventDefault();
            return;
        }
        const cashVal = parseFloat(cash);
        if (cashVal < amountVal || isNaN(cashVal)) {
            alert("Cash given must be a valid number greater than or equal to the amount.");
            e.preventDefault();
            return;
        }
    }
});


// ---------- DELETE CONFIRM ----------
const deleteOverlay = document.getElementById('deleteConfirm');
const cancelDeleteBtn = document.getElementById('cancelDelete');
const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
const closeDeletePopupBtn = document.getElementById('closeDeletePopup');
let deleteId = null;

function openDeletePopup(id) {
    deleteId = id;
    deleteOverlay.classList.add('show');
}
function closeDeletePopup() {
    deleteOverlay.classList.remove('show');
    deleteId = null;
}

document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        openDeletePopup(btn.dataset.id);
    });
});

cancelDeleteBtn.addEventListener('click', closeDeletePopup);
closeDeletePopupBtn.addEventListener('click', closeDeletePopup);

confirmDeleteBtn.addEventListener('click', () => {
    if (deleteId) {
        window.location.href = "appointments.php?delete=" + deleteId;
    }
});

// Inițializează data minimă la încărcarea paginii
document.getElementById('appointment_date').setAttribute('min', getTomorrowDate());
</script>


</body>
</html>