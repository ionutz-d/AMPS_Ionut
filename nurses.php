<?php
// nurses.php
$page_title = "Nurses";
$active     = "nurses";

require_once "auth.php";
require_login();
require_once "db.php";

$errors  = [];
$success = "";

// ---------- FUNCTII DE VALIDARE ----------

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

// nume/prenume: prima literă mare, restul doar litere (acceptăm diacritice)
function is_valid_name_capital($name) {
    return preg_match('/^[A-ZĂÂÎȘŞȚŢ][a-zăâîșşțţ]+$/u', $name);
}

// qualification: începe cu literă mare, restul litere + spații
function is_valid_qualification_capital($q) {
    return preg_match('/^[A-ZĂÂÎȘȘȚŢ][a-zA-Zăâîșşțţ ]*$/u', $q);
}

// shift permis: dimineata, dupa_amiaza, noapte
function is_valid_shift($shift) {
    $allowed = ['dimineata', 'dupa_amiaza', 'noapte'];
    return in_array($shift, $allowed, true);
}

function is_valid_status($status) {
    $allowed = ['active', 'in concediu', 'demisionat'];
    return in_array($status, $allowed, true);
}

// ---------- VALIDARE LATERALĂ ----------

function is_valid_hire_date($hire_date) {
    $today = date("Y-m-d");
    return $hire_date <= $today; // Hire date can't be in the future
}

function is_valid_experience($experience_years) {
    return $experience_years >= 1 && $experience_years <= 70;
}

function is_valid_salary($salary) {
    return $salary >= 5000 && $salary <= 60000;
}

// ---------- ADD / EDIT NURSE ----------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $mode      = $_POST["form_mode"] ?? "add";
    $id_nurse  = (int)($_POST["id_nurse"] ?? 0);

    $first_name  = clean($_POST["first_name"] ?? '');
    $last_name   = clean($_POST["last_name"] ?? '');
    $email       = clean($_POST["email"] ?? '');
    $phone       = clean($_POST["phone"] ?? '');
    $address     = clean($_POST["address"] ?? '');
    $hire_date   = clean($_POST["hire_date"] ?? '');
    $shift       = clean($_POST["shift"] ?? '');
    $assigned_doctor_id = (int)($_POST["assigned_doctor_id"] ?? 0);
    $experience_years   = (int)($_POST["experience_years"] ?? 0);
    $qualification      = clean($_POST["qualification"] ?? '');
    $salary_raw         = str_replace(',', '.', clean($_POST["salary"] ?? ''));
    $salary             = (float)$salary_raw;
    $status             = clean($_POST["status"] ?? 'active');

    // --- VALIDARI ---
    if ($first_name === '') {
        $errors[] = "First name este obligatoriu.";
    } elseif (!is_valid_name_capital($first_name)) {
        $errors[] = "First name trebuie să conțină doar litere și să înceapă cu literă mare.";
    }

    if ($last_name === '') {
        $errors[] = "Last name este obligatoriu.";
    } elseif (!is_valid_name_capital($last_name)) {
        $errors[] = "Last name trebuie să conțină doar litere și să înceapă cu literă mare.";
    }

    if ($email !== '' && !is_valid_email_custom($email)) {
        $errors[] = "Email invalid. Trebuie să conțină @ și .";
    }

    if ($phone !== '' && !is_valid_phone_9($phone)) {
        $errors[] = "Telefon invalid. Trebuie să conțină exact 9 cifre.";
    }

    if ($shift === '' || !is_valid_shift($shift)) {
        $errors[] = "Shift invalid. Alege dimineata, dupa_amiaza sau noapte.";
    }

    if (!is_valid_status($status)) {
        $errors[] = "Status invalid.";
    }

    if ($experience_years < 0) {
        $errors[] = "Experience years nu poate fi negativ.";
    } elseif (!is_valid_experience($experience_years)) {
        $errors[] = "Experiența trebuie să fie între 1 și 70 de ani.";
    }

    if ($salary <= 0 || !is_valid_salary($salary)) {
        $errors[] = "Salariul trebuie să fie între 5000 și 60000.";
    }

    if ($hire_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date)) {
        $errors[] = "Hire date trebuie să fie în formatul YYYY-MM-DD.";
    } elseif (!is_valid_hire_date($hire_date)) {
        $errors[] = "Hire date nu poate fi în viitor.";
    }

    if ($qualification !== '' && !is_valid_qualification_capital($qualification)) {
        $errors[] = "Qualification trebuie să înceapă cu literă mare și să conțină doar litere și spații.";
    }

    if (empty($errors)) {
        if ($mode === "add") {
            $sql = "INSERT INTO nurses
                (first_name, last_name, email, phone, address, hire_date, shift,
                 assigned_doctor_id, experience_years, qualification, salary, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";

            $stmt = mysqli_prepare($conn, $sql);
            $created_by = $_SESSION["user_id"];

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "sssssssiisdsi",
                    $first_name,
                    $last_name,
                    $email,
                    $phone,
                    $address,
                    $hire_date,
                    $shift,
                    $assigned_doctor_id,
                    $experience_years,
                    $qualification,
                    $salary,
                    $status,
                    $created_by
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $success = "Nurse adăugată cu succes.";
            }
        } else { // edit
            if ($id_nurse > 0) {
                $sql = "UPDATE nurses SET
                        first_name=?,
                        last_name=?,
                        email=?,
                        phone=?,
                        address=?,
                        hire_date=?,
                        shift=?,
                        assigned_doctor_id=?,
                        experience_years=?,
                        qualification=?,
                        salary=?,
                        status=?
                    WHERE id_nurse=?";

                $stmt = mysqli_prepare($conn, $sql);
                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt,
                        "sssssssiisdsi",
                        $first_name,
                        $last_name,
                        $email,
                        $phone,
                        $address,
                        $hire_date,
                        $shift,
                        $assigned_doctor_id,
                        $experience_years,
                        $qualification,
                        $salary,
                        $status,
                        $id_nurse
                    );
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $success = "Datele asistentei au fost actualizate.";
                }
            }
        }

        header("Location: nurses.php?msg=" . urlencode($success));
        exit;
    }
}

// ---------- DELETE NURSE ----------

if (isset($_GET["delete"])) {
    $id = (int)$_GET["delete"];
    if ($id > 0) {
        $sql = "DELETE FROM nurses WHERE id_nurse = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("Location: nurses.php?msg=" . urlencode("Nurse ștearsă cu succes."));
        exit;
    }
}

// ---------- SEARCH + SORT + PAGINARE ----------
$search = clean($_GET["q"] ?? '');
$sort   = $_GET["sort"] ?? 'name';

// sortare
$sort_sql = "ORDER BY n.last_name ASC, n.first_name ASC";
if ($sort === "exp_desc")  $sort_sql = "ORDER BY n.experience_years DESC, n.last_name ASC";
if ($sort === "exp_asc")   $sort_sql = "ORDER BY n.experience_years ASC, n.last_name ASC";
if ($sort === "salary_desc") $sort_sql = "ORDER BY n.salary DESC, n.last_name ASC";
if ($sort === "salary_asc")  $sort_sql = "ORDER BY n.salary ASC, n.last_name ASC";

// total nurses (overview)
$res_total = mysqli_query($conn, "SELECT COUNT(*) AS total_nurses FROM nurses");
$row_total = mysqli_fetch_assoc($res_total);
$total_nurses = (int)$row_total["total_nurses"];

// pentru dropdown Assigned doctor - luăm toți doctorii
$doc_result = mysqli_query($conn, "SELECT id_doctor, first_name, last_name FROM doctors ORDER BY last_name, first_name");
$all_doctors = [];
if ($doc_result) {
    while ($d = mysqli_fetch_assoc($doc_result)) {
        $all_doctors[] = $d;
    }
}

// WHERE pt search
$where = "WHERE 1";
$params = [];
$types  = "";

if ($search !== "") {
    $where .= " AND (
        CONCAT(n.first_name,' ',n.last_name) LIKE ? 
        OR n.shift LIKE ?
        OR n.qualification LIKE ?
        OR CONCAT(d.first_name,' ',d.last_name) LIKE ?
    )";
    $like = "%".$search."%";
    $params = [$like,$like,$like,$like];
    $types  = "ssss";
}

// PAGINARE
$per_page = 5;
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// COUNT filtrat
$sql_count = "
    SELECT COUNT(*) AS total_filtered
    FROM nurses n
    LEFT JOIN doctors d ON d.id_doctor = n.assigned_doctor_id
    $where
";

if (!empty($params)) {
    $stmt_count = mysqli_prepare($conn, $sql_count);
    mysqli_stmt_bind_param($stmt_count, $types, ...$params);
    mysqli_stmt_execute($stmt_count);
    $res_c = mysqli_stmt_get_result($stmt_count);
    $row_c = mysqli_fetch_assoc($res_c);
    mysqli_stmt_close($stmt_count);
} else {
    $res_c = mysqli_query($conn, $sql_count);
    $row_c = mysqli_fetch_assoc($res_c);
}

$total_filtered = (int)$row_c["total_filtered"];
$total_pages = max(1, ceil($total_filtered / $per_page));

// LISTA NURSES cu LIMIT + OFFSET
$sql_list = "
    SELECT n.*,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM nurses n
    LEFT JOIN doctors d ON d.id_doctor = n.assigned_doctor_id
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

$nurses = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $nurses[] = $row;
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
    <title>Nurses - AMC Clinic</title>
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


        /* Stiluri pentru eroare modală */
.error-modal {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background-color: rgba(255, 0, 0, 0.8);
    color: #fff;
    padding: 20px;
    border-radius: 8px;
    z-index: 1001;
    max-width: 400px;
    width: 100%;
}

.error-modal-content {
    padding: 20px;
}

.error-modal .close-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    color: #fff;
    font-size: 20px;
    cursor: pointer;
}



/* Modal background (overlay) */
.modal-overlay {
    display: none; /* Ascunde overlay-ul implicit */
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5); /* Fundal gri semi-transparent */
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

/* Când modalul este activ */
.modal-overlay.show {
    display: flex; /* Afișează overlay-ul când modalul este activ */
}


    </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box nurses-page">

        <div class="doctors-header">
            <h2>Nurses</h2>
            <p class="subtitle">Manage all nurses in the clinic.</p>
        </div> 

        <?php if (!empty($errors)): ?>
            <script>alert("<?= implode("\n", $errors) ?>");</script>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- OVERVIEW + SEARCH/SORT -->
        <div class="cards-row">

            <div class="card card-overview">
                <h3>Nurses overview</h3>
                <div class="overview-number"><?= $total_nurses ?></div>
                <p class="overview-text">Total nurses assigned in the clinic</p>
            </div>

            <div class="card card-search">
                <h3>Search &amp; sort</h3>

                <form method="GET" class="search-form">
                    <div class="form-group">
                        <label for="q">Search by name or shift</label>
                        <input type="text"
                               id="q"
                               name="q"
                               placeholder="Ex: Maria, Ana, dimineata, noapte"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div class="form-group">
                        <label for="sort">Sort by</label>
                        <select name="sort" id="sort">
                            <option value="name"        <?= $sort === 'name' ? 'selected' : '' ?>>Default (name)</option>
                            <option value="exp_desc"    <?= $sort === 'exp_desc' ? 'selected' : '' ?>>Experience (high → low)</option>
                            <option value="exp_asc"     <?= $sort === 'exp_asc' ? 'selected' : '' ?>>Experience (low → high)</option>
                            <option value="salary_desc" <?= $sort === 'salary_desc' ? 'selected' : '' ?>>Salary (high → low)</option>
                            <option value="salary_asc"  <?= $sort === 'salary_asc' ? 'selected' : '' ?>>Salary (low → high)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-apply">Apply</button>
                </form>
            </div>

        </div>

        <!-- NURSES LIST -->
        <div class="card doctors-list-card">
            <div class="card-header-row">
                <h3>Nurses list</h3>
                <button type="button" class="btn btn-add" id="btnAddNurse">+ Add Nurse</button>
            </div>

            <div class="table-wrapper">
                <table class="doctors-table">
                    <thead>
                        <tr>
                            <th>NAME</th>
                            <th>ASSIGNED DOCTOR</th>
                            <th>SHIFT</th>
                            <th>EXPERIENCE</th>
                            <th>QUALIFICATION</th>
                            <th>SALARY</th>
                            <th>CONTACT</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($nurses)): ?>
                        <tr>
                            <td colspan="8" class="no-data">No nurses found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($nurses as $n): ?>
                            <tr>
                                <td><?= htmlspecialchars($n["first_name"] . " " . $n["last_name"]) ?></td>
                                <td><?= htmlspecialchars($n["doctor_name"] ?? '-') ?></td>
                                <td><?= htmlspecialchars($n["shift"]) ?></td>
                                <td><?= (int)$n["experience_years"] ?> yrs</td>
                                <td><?= htmlspecialchars($n["qualification"]) ?></td>
                                <td><?= number_format((float)$n["salary"], 2, ',', ' ') ?></td>
                                <td>
                                    <?= htmlspecialchars($n["phone"]) ?>
                                    <?php if ($n["email"]): ?>
                                        / <?= htmlspecialchars($n["email"]) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <button
                                        type="button"
                                        class="link-btn edit-btn"
                                        data-id="<?= $n['id_nurse'] ?>"
                                        data-first_name="<?= htmlspecialchars($n['first_name']) ?>"
                                        data-last_name="<?= htmlspecialchars($n['last_name']) ?>"
                                        data-email="<?= htmlspecialchars($n['email']) ?>"
                                        data-phone="<?= htmlspecialchars($n['phone']) ?>"
                                        data-address="<?= htmlspecialchars($n['address']) ?>"
                                        data-hire_date="<?= htmlspecialchars($n['hire_date']) ?>"
                                        data-shift="<?= htmlspecialchars($n['shift']) ?>"
                                        data-assigned_doctor_id="<?= (int)$n['assigned_doctor_id'] ?>"
                                        data-experience="<?= (int)$n['experience_years'] ?>"
                                        data-qualification="<?= htmlspecialchars($n['qualification']) ?>"
                                        data-salary="<?= htmlspecialchars($n['salary']) ?>"
                                        data-status="<?= htmlspecialchars($n['status']) ?>"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="link-btn delete-btn"
                                        data-id="<?= $n['id_nurse'] ?>"
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
                    <a href="?page=<?= $page - 1 ?>&sort=<?= $sort ?>&q=<?= urlencode($search) ?>">« Prev</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>&sort=<?= $sort ?>&q=<?= urlencode($search) ?>"
                       class="<?= $i == $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&sort=<?= $sort ?>&q=<?= urlencode($search) ?>">Next »</a>
                <?php endif; ?>
            </div>

        </div>

    </div><!-- /content-box -->
</div><!-- /main-content -->

<!-- MODAL ADD / EDIT NURSE -->
<div class="modal-overlay" id="nurseModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Add Nurse</h3>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>

        <form method="POST" id="nurseForm" class="modal-form">
            <input type="hidden" name="form_mode" id="formMode" value="add">
            <input type="hidden" name="id_nurse" id="nurseId" value="0">

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
                    <label>Email</label>
                    <input type="email" name="email" id="email">
                </div>
                <div class="form-group">
                    <label>Phone (9 digits)</label>
                    <input type="text" name="phone" id="phone" maxlength="9">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" id="address">
                </div>
                <div class="form-group">
                    <label>Hire date</label>
                    <input type="date" name="hire_date" id="hire_date">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Shift*</label>
                    <select name="shift" id="shift" required>
                        <option value="">Select shift...</option>
                        <option value="dimineata">dimineata</option>
                        <option value="dupa_amiaza">dupa_amiaza</option>
                        <option value="noapte">noapte</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assigned doctor</label>
                    <select name="assigned_doctor_id" id="assigned_doctor_id">
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
                    <label>Experience (years)</label>
                    <input type="number" name="experience_years" id="experience_years" min="0">
                </div>
                <div class="form-group">
                    <label>Salary</label>
                    <input type="number" step="0.01" name="salary" id="salary" min="0">
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Qualification</label>
                    <input type="text" name="qualification" id="qualification">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="active">active</option>
                        <option value="in concediu">in concediu</option>
                        <option value="demisionat">demisionat</option>
                    </select>
                </div>
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
        <h3>Delete nurse</h3>
        <p>Are you sure you want to delete this nurse? This action cannot be undone!</p>
        <div class="confirm-buttons">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, delete</button>
        </div>
    </div>
</div>

<script>
// ---------- MODAL ADD / EDIT ----------
const nurseModal    = document.getElementById('nurseModal');
const btnAddNurse   = document.getElementById('btnAddNurse');
const closeModalBtn = document.getElementById('closeModal');
const cancelModal   = document.getElementById('cancelModal');
const modalTitle    = document.getElementById('modalTitle');
const formModeInput = document.getElementById('formMode');
const nurseIdInput  = document.getElementById('nurseId');
const saveBtn       = document.getElementById('saveBtn');

function openModal() {
    nurseModal.classList.add('show');
}

function closeModal() {
    nurseModal.classList.remove('show');
}

btnAddNurse.addEventListener('click', () => {
    modalTitle.textContent = "Add Nurse";
    formModeInput.value    = "add";
    nurseIdInput.value     = "0";

    document.getElementById('first_name').value       = "";
    document.getElementById('last_name').value        = "";
    document.getElementById('email').value            = "";
    document.getElementById('phone').value            = "";
    document.getElementById('address').value          = "";
    document.getElementById('hire_date').value        = "";
    document.getElementById('shift').value            = "";
    document.getElementById('assigned_doctor_id').value = "0";
    document.getElementById('experience_years').value = "";
    document.getElementById('qualification').value    = "";
    document.getElementById('salary').value           = "";
    document.getElementById('status').value           = "active";

    saveBtn.textContent = "Save nurse";
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
        modalTitle.textContent = "Edit Nurse";
        formModeInput.value    = "edit";
        nurseIdInput.value     = btn.dataset.id;

        document.getElementById('first_name').value       = btn.dataset.first_name;
        document.getElementById('last_name').value        = btn.dataset.last_name;
        document.getElementById('email').value            = btn.dataset.email;
        document.getElementById('phone').value            = btn.dataset.phone;
        document.getElementById('address').value          = btn.dataset.address;
        document.getElementById('hire_date').value        = btn.dataset.hire_date;
        document.getElementById('shift').value            = btn.dataset.shift;
        document.getElementById('assigned_doctor_id').value = btn.dataset.assigned_doctor_id;
        document.getElementById('experience_years').value = btn.dataset.experience;
        document.getElementById('qualification').value    = btn.dataset.qualification;
        document.getElementById('salary').value           = btn.dataset.salary;
        document.getElementById('status').value           = btn.dataset.status;

        saveBtn.textContent = "Update nurse";
        openModal();
    });
});

// ---------- VALIDARE SIMPLĂ PE CLIENT ----------
const nurseForm = document.getElementById('nurseForm');
nurseForm.addEventListener('submit', (e) => {
    const firstName = document.getElementById('first_name').value.trim();
    const lastName  = document.getElementById('last_name').value.trim();
    const email     = document.getElementById('email').value.trim();
    const phone     = document.getElementById('phone').value.trim();
    const shift     = document.getElementById('shift').value;
    const salary    = document.getElementById('salary').value.trim();
    const qualification = document.getElementById('qualification').value.trim();

    const nameRegex = /^[A-ZĂÂÎȘŞȚŢ][a-zăâîșşțţ]+$/u;
    const qualificationRegex = /^[A-ZĂÂÎȘŞȚŢ][a-zA-Zăâîșşțţ ]*$/u;

    if (!nameRegex.test(firstName)) {
        alert("First name trebuie să conțină doar litere și să înceapă cu literă mare.");
        e.preventDefault();
        return;
    }

    if (!nameRegex.test(lastName)) {
        alert("Last name trebuie să conțină doar litere și să înceapă cu literă mare.");
        e.preventDefault();
        return;
    }

    if (email !== "" && (!email.includes("@") || !email.includes("."))) {
        alert("Email invalid. Trebuie să conțină @ și .");
        e.preventDefault();
        return;
    }

    if (phone !== "" && !/^[0-9]{9}$/.test(phone)) {
        alert("Telefonul trebuie să conțină exact 9 cifre.");
        e.preventDefault();
        return;
    }

    if (shift === "") {
        alert("Te rog selectează tura (shift).");
        e.preventDefault();
        return;
    }

    if (salary === "" || parseFloat(salary) <= 0) {
        alert("Salary trebuie să fie un număr pozitiv.");
        e.preventDefault();
        return;
    }

    if (qualification !== "" && !qualificationRegex.test(qualification)) {
        alert("Qualification trebuie să înceapă cu literă mare și să conțină doar litere și spații.");
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
        window.location.href = "nurses.php?delete=" + deleteId;
    }
});



// Functia pentru afisarea unui mesaj de eroare personalizat
function showErrorMessage(message) {
    // Creează un div pentru eroare
    const errorModal = document.createElement('div');
    errorModal.classList.add('error-modal');
    errorModal.innerHTML = `
        <div class="error-modal-content">
            <span class="close-btn">&times;</span>
            <p>${message}</p>
        </div>
    `;

    // Adăugăm div-ul pentru eroare în document
    document.body.appendChild(errorModal);

    // Afișăm eroarea
    errorModal.style.display = 'block';

    // Închidem eroarea când se apasă pe "x"
    errorModal.querySelector('.close-btn').addEventListener('click', function() {
        errorModal.style.display = 'none';
        document.body.removeChild(errorModal);
    });
}

// Modificarea validărilor pentru a arăta eroarea personalizată
nurseForm.addEventListener('submit', (e) => {
    const firstName = document.getElementById('first_name').value.trim();
    const lastName  = document.getElementById('last_name').value.trim();
    const email     = document.getElementById('email').value.trim();
    const phone     = document.getElementById('phone').value.trim();
    const shift     = document.getElementById('shift').value;
    const salary    = document.getElementById('salary').value.trim();
    const qualification = document.getElementById('qualification').value.trim();

    const nameRegex = /^[A-ZĂÂÎȘŞȚŢ][a-zăâîșşțţ]+$/u;
    const qualificationRegex = /^[A-ZĂÂÎȘȘȚŢ][a-zA-Zăâîșşțţ ]*$/u;

    if (!nameRegex.test(firstName)) {
        showErrorMessage("First name trebuie să conțină doar litere și să înceapă cu literă mare.");
        e.preventDefault();
        return;
    }

    if (!nameRegex.test(lastName)) {
        showErrorMessage("Last name trebuie să conțină doar litere și să înceapă cu literă mare.");
        e.preventDefault();
        return;
    }

    if (email !== "" && (!email.includes("@") || !email.includes("."))) {
        showErrorMessage("Email invalid. Trebuie să conțină @ și .");
        e.preventDefault();
        return;
    }

    if (phone !== "" && !/^[0-9]{9}$/.test(phone)) {
        showErrorMessage("Telefonul trebuie să conțină exact 9 cifre.");
        e.preventDefault();
        return;
    }

    if (shift === "") {
        showErrorMessage("Te rog selectează tura (shift).");
        e.preventDefault();
        return;
    }

    if (salary === "" || parseFloat(salary) <= 0) {
        showErrorMessage("Salary trebuie să fie un număr pozitiv.");
        e.preventDefault();
        return;
    }

    if (qualification !== "" && !qualificationRegex.test(qualification)) {
        showErrorMessage("Qualification trebuie să înceapă cu literă mare și să conțină doar litere și spații.");
        e.preventDefault();
        return;
    }

    // Validarea hire date (nu poate fi în viitor)
    const hireDate = document.getElementById('hire_date').value;
    const today = new Date().toISOString().split('T')[0]; // Formatarea datei curente
    if (hireDate > today) {
        showErrorMessage("Hire date nu poate fi în viitor.");
        e.preventDefault();
        return;
    }
});

</script>
<script src="assets/main.js" defer></script>

</body>
</html>
