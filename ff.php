<?php
// patients.php
$page_title = "Patients";
$active     = "patients";

require_once "auth.php";
require_login();
require_once "db.php";

$errors  = [];
$success = "";

// ---------- HELPERI ----------
function clean($v) {
    return trim($v ?? '');
}

function is_valid_email_custom($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    return (strpos($email, '@') !== false && strpos($email, '.') !== false);
}

function is_valid_phone_9($phone) {
    return preg_match('/^[0-9]{9}$/', $phone);
}

function is_valid_cnp($cnp) {
    // gol sau exact 13 cifre
    return ($cnp === '' || preg_match('/^[0-9]{13}$/', $cnp));
}

function is_valid_gender($g) {
    return in_array($g, ['M', 'F', 'Altul'], true);
}

// nume/prenume: doar litere, prima literă mare
function is_valid_capitalized_name($name) {
    return preg_match('/^[A-Z][a-zA-Z]+$/', $name);
}

// UI: Active, InTreatment, Recovered  <-> DB: activ, în tratament, externat
function status_ui_to_db($s) {
    switch ($s) {
        case 'Active':       return 'activ';
        case 'InTreatment':  return 'în tratament';
        case 'Recovered':    return 'externat';
        default:             return 'activ';
    }
}
function status_db_to_ui($s) {
    switch ($s) {
        case 'activ':         return 'Active';
        case 'în tratament':  return 'InTreatment';
        case 'externat':      return 'Recovered';
        default:              return 'Active';
    }
}

// ---------- ADD / EDIT PATIENT ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $mode        = $_POST["form_mode"] ?? "add";
    $id_patient  = (int)($_POST["id_patient"] ?? 0);

    $cnp         = clean($_POST["cnp"] ?? '');
    $first_name  = clean($_POST["first_name"] ?? '');
    $last_name   = clean($_POST["last_name"] ?? '');
    $birth_date  = clean($_POST["birth_date"] ?? '');
    $gender      = clean($_POST["gender"] ?? '');
    $phone       = clean($_POST["phone"] ?? '');
    $email       = clean($_POST["email"] ?? '');
    $address     = clean($_POST["address"] ?? '');
    $visit_reason = clean($_POST["visit_reason"] ?? '');
    $diagnosis   = clean($_POST["diagnosis"] ?? '');
    $doctor_id   = (int)($_POST["doctor_id"] ?? 0);
    $status_ui   = clean($_POST["status"] ?? 'Active');
    $status_db   = status_ui_to_db($status_ui);

    // --- VALIDARI ---

    // First name
    if ($first_name === '') {
        $errors[] = "First name este obligatoriu.";
    } elseif (!is_valid_capitalized_name($first_name)) {
        $errors[] = "First name trebuie să conțină doar litere și să înceapă cu literă mare.";
    }

    // Last name
    if ($last_name === '') {
        $errors[] = "Last name este obligatoriu.";
    } elseif (!is_valid_capitalized_name($last_name)) {
        $errors[] = "Last name trebuie să conțină doar litere și să înceapă cu literă mare.";
    }

    if (!is_valid_cnp($cnp)) {
        $errors[] = "CNP invalid. Trebuie să conțină exact 13 cifre (sau lasă gol).";
    }

    if ($email !== '' && !is_valid_email_custom($email)) {
        $errors[] = "Email invalid. Trebuie să conțină @ și .";
    }

    if (!is_valid_phone_9($phone)) {
        $errors[] = "Telefon invalid. Trebuie să conțină exact 9 cifre.";
    }

    if ($gender === '' || !is_valid_gender($gender)) {
        $errors[] = "Gender invalid. Alege M, F sau Altul.";
    }

   // Verificăm dacă birth_date este în formatul corect
if ($birth_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
    $errors[] = "Birth date trebuie să fie în formatul YYYY-MM-DD.";
} elseif ($birth_date !== '' && strtotime($birth_date) > time()) {
    $errors[] = "Data de naștere nu poate fi în viitor.";
}


    if ($visit_reason === '' || strlen($visit_reason) < 3) {
        $errors[] = "Visit reason este obligatoriu și trebuie să aibă minim 3 caractere.";
    }

    if ($diagnosis === '') {
        $diagnosis = "Nediagnosticat";
    }

    if (!in_array($status_ui, ['Active','InTreatment','Recovered'], true)) {
        $errors[] = "Status invalid.";
    }

    if (empty($errors)) {
        if ($mode === "add") {
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

                $success = "Patient added successfully.";
            }
        } else { // edit
            if ($id_patient > 0) {
                $sql = "UPDATE patients SET
                        cnp=?,
                        first_name=?,
                        last_name=?,
                        birth_date=?,
                        gender=?,
                        phone=?,
                        email=?,
                        address=?,
                        visit_reason=?,
                        diagnosis=?,
                        doctor_id=?,
                        status=?
                    WHERE id_patient=?";

                $stmt = mysqli_prepare($conn, $sql);
                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt,
                        "ssssssssssisi",
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
                        $status_db,
                        $id_patient
                    );
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $success = "Patient updated successfully.";
                }
            }
        }

        header("Location: patients.php?msg=" . urlencode($success));
        exit;
    }
}

// ---------- DELETE PATIENT ----------
if (isset($_GET["delete"])) {
    $id = (int)$_GET["delete"];
    if ($id > 0) {
        $sql = "DELETE FROM patients WHERE id_patient = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("Location: patients.php?msg=" . urlencode("Patient deleted successfully."));
        exit;
    }
}

// ---------- SEARCH + SORT + PAGINARE ----------
$search = clean($_GET["q"] ?? '');
$sort   = $_GET["sort"] ?? 'name_asc';

// SORTARE
$sort_sql = "ORDER BY p.last_name ASC, p.first_name ASC";
if ($sort === "name_desc") $sort_sql = "ORDER BY p.last_name DESC, p.first_name DESC";
if ($sort === "newest")    $sort_sql = "ORDER BY p.created_at DESC";
if ($sort === "oldest")    $sort_sql = "ORDER BY p.created_at ASC";

if ($sort === "gender_f") {
    $sort_sql = "
        ORDER BY
            CASE 
                WHEN p.gender = 'F' THEN 0
                WHEN p.gender = 'M' THEN 1
                ELSE 2
            END,
            p.last_name ASC, p.first_name ASC
    ";
}
if ($sort === "gender_m") {
    $sort_sql = "
        ORDER BY
            CASE 
                WHEN p.gender = 'M' THEN 0
                WHEN p.gender = 'F' THEN 1
                ELSE 2
            END,
            p.last_name ASC, p.first_name ASC
    ";
}

// total patients (overview)
$res_total = mysqli_query($conn, "SELECT COUNT(*) AS total_patients FROM patients");
$row_total = mysqli_fetch_assoc($res_total);
$total_patients = (int)$row_total["total_patients"];

// doctors pentru dropdown
$doc_result = mysqli_query($conn, "SELECT id_doctor, first_name, last_name 
                                   FROM doctors 
                                   ORDER BY last_name, first_name");
$all_doctors = [];
if ($doc_result) {
    while ($d = mysqli_fetch_assoc($doc_result)) {
        $all_doctors[] = $d;
    }
}

// WHERE pentru search
$where  = "WHERE 1";
$params = [];
$types  = "";

if ($search !== "") {
    $where .= " AND (
        CONCAT(p.first_name,' ',p.last_name) LIKE ?
        OR p.visit_reason LIKE ?
        OR p.diagnosis LIKE ?
        OR CONCAT(d.first_name,' ',d.last_name) LIKE ?
    )";
    $like   = "%".$search."%";
    $params = [$like,$like,$like,$like];
    $types  = "ssss";
}

// PAGINARE (5 pacienți pe pagină)
$per_page = 5;
$page     = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

// COUNT FILTRAT pentru paginare
$sql_count = "
    SELECT COUNT(*) AS total_filtered
    FROM patients p
    LEFT JOIN doctors d ON d.id_doctor = p.doctor_id
    $where
";

if (!empty($params)) {
    $stmt_count = mysqli_prepare($conn, $sql_count);
    mysqli_stmt_bind_param($stmt_count, $types, ...$params);
    mysqli_stmt_execute($stmt_count);
    $res_c = mysqli_stmt_get_result($stmt_count);
    $count_row = mysqli_fetch_assoc($res_c);
    mysqli_stmt_close($stmt_count);
} else {
    $res_c = mysqli_query($conn, $sql_count);
    $count_row = mysqli_fetch_assoc($res_c);
}

$total_filtered = (int)$count_row['total_filtered'];
$total_pages    = max(1, ceil($total_filtered / $per_page));

// LISTA PACIENȚI CU LIMIT + OFFSET
$sql_list = "
    SELECT p.*,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM patients p
    LEFT JOIN doctors d ON d.id_doctor = p.doctor_id
    $where
    $sort_sql
    LIMIT $per_page OFFSET $offset
";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql_list);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql_list);
}

$patients = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['status_ui'] = status_db_to_ui($row['status']);
        $patients[] = $row;
    }
    if (isset($stmt)) mysqli_stmt_close($stmt);
}

// mesaj success din redirect
if (isset($_GET["msg"])) {
    $success = $_GET["msg"];
}

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Patients - AMC Clinic</title>
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
    </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box patients-page">

        <div class="doctors-header">
            <h2>Patients</h2>
            <p class="subtitle">Manage all patients in the clinic.</p>
        </div>

   

        <?php if ($success !== ""): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- OVERVIEW + SEARCH/SORT -->
        <div class="cards-row">

            <div class="card card-overview">
                <h3>Patients overview</h3>
                <div class="overview-number"><?= $total_patients ?></div>
                <p class="overview-text">Total patients registered</p>
            </div>

            <div class="card card-search">
                <h3>Search &amp; sort</h3>

                <form method="GET" class="search-form">
                    <div class="form-group">
                        <label for="q">Search by patient or doctor</label>
                        <input type="text"
                               id="q"
                               name="q"
                               placeholder="Ex: Ion, Maria, Dr. Popescu"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div class="form-group">
                        <label for="sort">Sort by</label>
                        <select name="sort" id="sort">
                            <option value="name_asc"   <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name (A → Z)</option>
                            <option value="name_desc"  <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name (Z → A)</option>
                            <option value="newest"     <?= $sort === 'newest' ? 'selected' : '' ?>>Newest patients</option>
                            <option value="oldest"     <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest patients</option>
                            <option value="gender_f"   <?= $sort === 'gender_f' ? 'selected' : '' ?>>Gender: F → M</option>
                            <option value="gender_m"   <?= $sort === 'gender_m' ? 'selected' : '' ?>>Gender: M → F</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-apply">Apply</button>
                </form>
            </div>

        </div>

        <!-- PATIENTS LIST -->
        <div class="card doctors-list-card">
            <div class="card-header-row">
                <h3>Patients list</h3>
                <button type="button" class="btn btn-add" id="btnAddPatient">+ Add Patient</button>
            </div>

            <div class="table-wrapper">
                <table class="doctors-table">
                    <thead>
                        <tr>
                            <th>NAME</th>
                            <th>DOCTOR</th>
                            <th>BIRTH DATE</th>
                            <th>GENDER</th>
                            <th>STATUS</th>
                            <th>VISIT REASON</th>
                            <th>DIAGNOSTIC</th>
                            <th>CONTACT</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($patients)): ?>
                        <tr>
                            <td colspan="9" class="no-data">No patients found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($patients as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p["first_name"] . " " . $p["last_name"]) ?></td>
                                <td><?= htmlspecialchars($p["doctor_name"] ?? '-') ?></td>
                                <td><?= htmlspecialchars($p["birth_date"]) ?></td>
                                <td><?= htmlspecialchars($p["gender"]) ?></td>
                                <td><?= htmlspecialchars($p["status_ui"]) ?></td>
                                <td><?= htmlspecialchars($p["visit_reason"]) ?></td>
                                <td><?= htmlspecialchars($p["diagnosis"]) ?></td>
                                <td><?= htmlspecialchars($p["phone"]) ?></td>
                                <td class="actions">
                                    <button
                                        type="button"
                                        class="link-btn edit-btn"
                                        data-id="<?= $p['id_patient'] ?>"
                                        data-cnp="<?= htmlspecialchars($p['cnp']) ?>"
                                        data-first_name="<?= htmlspecialchars($p['first_name']) ?>"
                                        data-last_name="<?= htmlspecialchars($p['last_name']) ?>"
                                        data-birth_date="<?= htmlspecialchars($p['birth_date']) ?>"
                                        data-gender="<?= htmlspecialchars($p['gender']) ?>"
                                        data-phone="<?= htmlspecialchars($p['phone']) ?>"
                                        data-email="<?= htmlspecialchars($p['email']) ?>"
                                        data-address="<?= htmlspecialchars($p['address']) ?>"
                                        data-visit_reason="<?= htmlspecialchars($p['visit_reason']) ?>"
                                        data-diagnosis="<?= htmlspecialchars($p['diagnosis']) ?>"
                                        data-doctor_id="<?= (int)$p['doctor_id'] ?>"
                                        data-status="<?= htmlspecialchars($p['status_ui']) ?>"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="link-btn delete-btn"
                                        data-id="<?= $p['id_patient'] ?>"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&sort=<?= htmlspecialchars($sort) ?>&q=<?= urlencode($search) ?>">« Prev</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>&sort=<?= htmlspecialchars($sort) ?>&q=<?= urlencode($search) ?>"
                       class="<?= $i == $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&sort=<?= htmlspecialchars($sort) ?>&q=<?= urlencode($search) ?>">Next »</a>
                <?php endif; ?>
            </div>

        </div>

    </div><!-- /content-box -->
</div><!-- /main-content -->

<!-- MODAL ADD / EDIT PATIENT -->
<div class="modal-overlay" id="patientModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Add Patient</h3>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>

        <form method="POST" id="patientForm" class="modal-form">
            <input type="hidden" name="form_mode" id="formMode" value="add">
            <input type="hidden" name="id_patient" id="patientId" value="0">

            <div class="modal-row">
                <div class="form-group">
                    <label>First name*</label>
                    <input type="text" name="first_name" id="first_name" required>
                </div>
                <div class="form-group">
                    <label>Last name*</label>
                    <input type="text" name="last_name" id="last_name" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>CNP (13 digits, optional)</label>
                    <input type="text" name="cnp" id="cnp" maxlength="13">
                </div>
                <div class="form-group">
                    <label>Birth date</label>
                    <input type="date" name="birth_date" id="birth_date">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Gender*</label>
                    <select name="gender" id="gender" required>
                        <option value="">Select gender...</option>
                        <option value="F">F</option>
                        <option value="M">M</option>
                        <option value="Altul">Altul</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Doctor</label>
                    <select name="doctor_id" id="doctor_id">
                        <option value="0">None</option>
                        <?php foreach ($all_doctors as $doc): ?>
                            <option value="<?= $doc['id_doctor'] ?>">
                                <?= htmlspecialchars($doc['last_name'] . ' ' . $doc['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Phone (9 digits)*</label>
                    <input type="text" name="phone" id="phone" maxlength="9" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="email">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" id="address">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="Active">Active</option>
                        <option value="InTreatment">InTreatment</option>
                        <option value="Recovered">Recovered</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Visit reason*</label>
                <textarea name="visit_reason" id="visit_reason" rows="2" required></textarea>
            </div>

            <div class="form-group">
                <label>Diagnostic</label>
                <textarea name="diagnosis" id="diagnosis" rows="2"></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- CONFIRM DELETE POPUP -->
<div class="confirm-overlay" id="deleteConfirm">
    <div class="confirm-box">
        <h3>Delete patient</h3>
        <p>Are you sure you want to delete this patient? This action cannot be undone.</p>
        <div class="confirm-buttons">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, delete</button>
        </div>
    </div>
</div>

<script>
// ---------- MODAL ADD / EDIT ----------
const patientModal   = document.getElementById('patientModal');
const btnAddPatient  = document.getElementById('btnAddPatient');
const closeModalBtn  = document.getElementById('closeModal');
const cancelModal    = document.getElementById('cancelModal');
const modalTitle     = document.getElementById('modalTitle');
const formModeInput  = document.getElementById('formMode');
const patientIdInput = document.getElementById('patientId');
const saveBtn        = document.getElementById('saveBtn');

function openModal() {
    patientModal.classList.add('show');
}
function closeModal() {
    patientModal.classList.remove('show');
}

btnAddPatient.addEventListener('click', () => {
    modalTitle.textContent = "Add Patient";
    formModeInput.value    = "add";
    patientIdInput.value   = "0";

    document.getElementById('cnp').value          = "";
    document.getElementById('first_name').value   = "";
    document.getElementById('last_name').value    = "";
    document.getElementById('birth_date').value   = "";
    document.getElementById('gender').value       = "";
    document.getElementById('phone').value        = "";
    document.getElementById('email').value        = "";
    document.getElementById('address').value      = "";
    document.getElementById('visit_reason').value = "";
    document.getElementById('diagnosis').value    = "";
    document.getElementById('doctor_id').value    = "0";
    document.getElementById('status').value       = "Active";

    saveBtn.textContent = "Save patient";
    openModal();
});

closeModalBtn.addEventListener('click', closeModal);
cancelModal.addEventListener('click', closeModal);

window.addEventListener('keydown', (e) => {
    if (e.key === "Escape") {
        closeModal();
        closeDeletePopup();
    }
});

// ---------- EDIT BUTTONS ----------
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        modalTitle.textContent = "Edit Patient";
        formModeInput.value    = "edit";
        patientIdInput.value   = btn.dataset.id;

        document.getElementById('cnp').value          = btn.dataset.cnp || "";
        document.getElementById('first_name').value   = btn.dataset.first_name;
        document.getElementById('last_name').value    = btn.dataset.last_name;
        document.getElementById('birth_date').value   = btn.dataset.birth_date;
        document.getElementById('gender').value       = btn.dataset.gender;
        document.getElementById('phone').value        = btn.dataset.phone;
        document.getElementById('email').value        = btn.dataset.email;
        document.getElementById('address').value      = btn.dataset.address;
        document.getElementById('visit_reason').value = btn.dataset.visit_reason;
        document.getElementById('diagnosis').value    = btn.dataset.diagnosis;
        document.getElementById('doctor_id').value    = btn.dataset.doctor_id;
        document.getElementById('status').value       = btn.dataset.status;

        saveBtn.textContent = "Update patient";
        openModal();
    });
});

// ---------- VALIDARE SIMPLĂ PE CLIENT ----------
// ---------- VALIDARE SIMPLĂ PE CLIENT ----------
const patientForm = document.getElementById('patientForm');
patientForm.addEventListener('submit', (e) => {
    const firstName = document.getElementById('first_name').value.trim();
    const lastName  = document.getElementById('last_name').value.trim();
    const email     = document.getElementById('email').value.trim();
    const phone     = document.getElementById('phone').value.trim();
    const cnp       = document.getElementById('cnp').value.trim();
    const gender    = document.getElementById('gender').value;
    const vr        = document.getElementById('visit_reason').value.trim();
    const diag      = document.getElementById('diagnosis').value.trim();

    const nameRegex = /^[A-Z][a-zA-Z]+$/;

    // Verifică primul nume
    if (!nameRegex.test(firstName)) {
        alert("First name trebuie să conțină doar litere și să înceapă cu literă mare.");
        e.preventDefault();
        return;
    }

    // Verifică numele de familie
    if (!nameRegex.test(lastName)) {
        alert("Last name trebuie să conțină doar litere și să înceapă cu literă mare.");
        e.preventDefault();
        return;
    }

    // Verifică email-ul
    if (email !== "" && (!email.includes("@") || !email.includes("."))) {
        alert("Email invalid. Trebuie să conțină @ și .");
        e.preventDefault();
        return;
    }

    // Verifică telefonul
    if (!/^[0-9]{9}$/.test(phone)) {
        alert("Telefonul trebuie să conțină exact 9 cifre.");
        e.preventDefault();
        return;
    }

    // Verifică CNP-ul
    if (cnp !== "" && !/^[0-9]{13}$/.test(cnp)) {
        alert("CNP-ul trebuie să conțină exact 13 cifre (sau lasă gol).");
        e.preventDefault();
        return;
    }

    // Verifică gender-ul
    if (!gender) {
        alert("Te rog selectează gender.");
        e.preventDefault();
        return;
    }

    // Verifică motivul vizitei
    if (vr.length < 3) {
        alert("Visit reason trebuie să aibă minim 3 caractere.");
        e.preventDefault();
        return;
    }

    // Verifică diagnosticul
    if (diag.trim() === "") {
        document.getElementById('diagnosis').value = "Nediagnosticat";
    }

    // Verifică data nașterii
    const birthDate = document.getElementById('birth_date').value;
    if (birthDate && new Date(birthDate) > new Date()) {
        alert("Data de naștere nu poate fi în viitor.");
        e.preventDefault();
        return;
    }
});


// ---------- DELETE CONFIRM ----------
const deleteOverlay    = document.getElementById('deleteConfirm');
const cancelDeleteBtn  = document.getElementById('cancelDelete');
const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
let deleteId           = null;

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

confirmDeleteBtn.addEventListener('click', () => {
    if (deleteId) {
        window.location.href = "patients.php?delete=" + deleteId;
    }
});
</script>

</body>
</html>
