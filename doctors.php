<?php
// doctors.php
$page_title = "Doctors";
$active     = "doctors";

require_once "auth.php";
require_login();
require_once "db.php";

$success       = "";
$field_errors  = [];   // erori pe câmpuri (first_name, last_name etc.)
$has_errors    = false;

// valori implicite pentru formular (ca să nu fie variabile nedefinite)
$mode              = "add";
$id_doctor         = 0;
$first_name        = "";
$last_name         = "";
$email             = "";
$phone             = "";
$cnp               = "";
$address           = "";
$specialization    = "";
$experience_years  = 0;
$hire_date         = "";
$salary            = 0.0;
$notes             = "";

// ---------- FUNCTII DE VALIDARE ----------

function clean($v) {
    return trim($v ?? '');
}

function is_valid_email_custom($email) {
    // trebuie să conțină @ și .
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    return (strpos($email, '@') !== false && strpos($email, '.') !== false);
}

function is_valid_phone_9($phone) {
    // exact 9 cifre
    return preg_match('/^[0-9]{9}$/', $phone);
}

function is_valid_cnp($cnp) {
    // exact 13 cifre
    return preg_match('/^[0-9]{13}$/', $cnp);
}

function is_valid_name($name) {
    // doar litere, prima literă mare
    return preg_match('/^[A-Z][a-zA-Z]*$/', $name);
}

function is_valid_specialization($spec) {
    // prima literă mare, doar litere
    return preg_match('/^[A-Z][a-zA-Z]*$/', $spec);
}

function is_valid_experience_years($experience) {
    // număr între 1 și 70
    return ($experience >= 1 && $experience <= 70);
}

function is_valid_salary($salary) {
    // între 5000 și 60000
    return ($salary >= 5000 && $salary <= 60000);
}

function is_valid_hire_date($hire_date) {
    // nu poate fi în viitor
    $current_date = date('Y-m-d');
    return ($hire_date <= $current_date);
}

// ---------- ADD / EDIT DOCTOR ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $mode      = $_POST["form_mode"] ?? "add";
    $id_doctor = (int)($_POST["id_doctor"] ?? 0);

    $first_name     = clean($_POST["first_name"] ?? '');
    $last_name      = clean($_POST["last_name"] ?? '');
    $email          = clean($_POST["email"] ?? '');
    $phone          = clean($_POST["phone"] ?? '');
    $cnp            = clean($_POST["cnp"] ?? '');
    $address        = clean($_POST["address"] ?? '');
    $specialization = clean($_POST["specialization"] ?? '');
    $experience_raw = clean($_POST["experience_years"] ?? '');
    $hire_date      = clean($_POST["hire_date"] ?? '');
    $salary_raw     = str_replace(',', '.', clean($_POST["salary"] ?? ''));
    $salary         = (float)$salary_raw;
    $notes          = clean($_POST["notes"] ?? '');
    $experience_years = 0;

    // --- VALIDARI PE CÂMPURI ---

    // First name & Last name obligatoriu + doar litere + prima literă mare
    if ($first_name === '') {
        $field_errors['first_name'][] = "First name este obligatoriu.";
    } else {
        if (!is_valid_name($first_name)) {
            $field_errors['first_name'][] = "Trebuie, să conțină doar litere și să înceapă cu literă mare.";
        }
    }

    if ($last_name === '') {
        $field_errors['last_name'][] = "Last name este obligatoriu.";
    } else {
        if (!is_valid_name($last_name)) {
            $field_errors['last_name'][] = "Trebuie să conțină doar litere și să înceapă cu literă mare.";
        }
    }

    // Email – trebuie să conțină @ și .
    if ($email !== '' && !is_valid_email_custom($email)) {
        $field_errors['email'][] = "Email invalid. Trebuie să conțină @ și .";
    }

    // Telefon – exact 9 cifre
    if ($phone !== '' && !is_valid_phone_9($phone)) {
        $field_errors['phone'][] = "Telefonul trebuie să conțină exact 9 cifre.";
    }

    // CNP – exact 13 cifre
    if (!is_valid_cnp($cnp)) {
        $field_errors['cnp'][] = "CNP trebuie să conțină exact 13 cifre.";
    }

    // Specialization – trebuie să înceapă cu literă mare
    if ($specialization === '') {
        $field_errors['specialization'][] = "Specialization este obligatorie.";
    } else {
        if (!is_valid_specialization($specialization)) {
            $field_errors['specialization'][] = "Trebuie să înceapă cu literă mare și să conțină doar litere.";
        }
    }

    // Experience – trebuie să fie un număr între 1 și 70
    if ($experience_raw === '' || !ctype_digit($experience_raw) || !is_valid_experience_years((int)$experience_raw)) {
        $field_errors['experience_years'][] = "Experience years trebuie să fie un număr între 1 și 70.";
    } else {
        $experience_years = (int)$experience_raw;
    }

    // Salary – între 5000 și 60000
    if ($salary <= 0 || !is_valid_salary($salary)) {
        $field_errors['salary'][] = "Salary trebuie să fie între 5000 și 60000.";
    }

    // Hire date – nu poate fi în viitor
    if ($hire_date !== '' && !is_valid_hire_date($hire_date)) {
        $field_errors['hire_date'][] = "Hire date nu poate fi o dată în viitor.";
    }

    // Address & Notes – trebuie să fie completate
    if ($address === '') {
        $field_errors['address'][] = "Adresa este obligatorie.";
    }

    if ($notes === '') {
        $field_errors['notes'][] = "Notes este obligatoriu.";
    }

    $has_errors = !empty($field_errors);

    if (!$has_errors) {
        if ($mode === "add") {
            $sql = "INSERT INTO doctors
                (first_name, last_name, email, phone, cnp, address, specialization,
                 experience_years, hire_date, salary, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";

            $stmt = mysqli_prepare($conn, $sql);
            $created_by = $_SESSION["user_id"];

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "sssssssisdsi",
                    $first_name,
                    $last_name,
                    $email,
                    $phone,
                    $cnp,
                    $address,
                    $specialization,
                    $experience_years,
                    $hire_date,
                    $salary,
                    $notes,
                    $created_by
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $success = "Doctor adăugat cu succes.";
            }
        } else { // edit
            if ($id_doctor > 0) {
                $sql = "UPDATE doctors SET
                        first_name=?,
                        last_name=?,
                        email=?,
                        phone=?,
                        cnp=?,
                        address=?,
                        specialization=?,
                        experience_years=?,
                        hire_date=?,
                        salary=?,
                        notes=?
                    WHERE id_doctor=?";

                $stmt = mysqli_prepare($conn, $sql);
                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt,
                        "sssssssisdsi",
                        $first_name,
                        $last_name,
                        $email,
                        $phone,
                        $cnp,
                        $address,
                        $specialization,
                        $experience_years,
                        $hire_date,
                        $salary,
                        $notes,
                        $id_doctor
                    );
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $success = "Datele doctorului au fost actualizate.";
                }
            }
        }

        // redirect doar dacă NU sunt erori
        header("Location: doctors.php?msg=" . urlencode($success));
        exit;
    }
}

// ---------- DELETE DOCTOR ----------
if (isset($_GET["delete"])) {
    $id = (int)$_GET["delete"];
    if ($id > 0) {
        $sql = "DELETE FROM doctors WHERE id_doctor = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("Location: doctors.php?msg=" . urlencode("Doctor șters cu succes."));
        exit;
    }
}

// ---------- SEARCH + SORT ----------
$search = clean($_GET["q"] ?? '');
$sort   = $_GET["sort"] ?? 'name';

$sort_sql = "ORDER BY d.last_name ASC, d.first_name ASC";
if ($sort === "exp_desc") {
    $sort_sql = "ORDER BY d.experience_years DESC, d.last_name ASC";
} elseif ($sort === "exp_asc") {
    $sort_sql = "ORDER BY d.experience_years ASC, d.last_name ASC";
} elseif ($sort === "salary_desc") {
    $sort_sql = "ORDER BY d.salary DESC, d.last_name ASC";
} elseif ($sort === "salary_asc") {
    $sort_sql = "ORDER BY d.salary ASC, d.last_name ASC";
}

// număr total doctori
$res_total = mysqli_query($conn, "SELECT COUNT(*) AS total_doctors FROM doctors");
$row_total = mysqli_fetch_assoc($res_total);
$total_doctors = (int)$row_total["total_doctors"];

// WHERE pentru search
$where = "WHERE 1";
$params = [];
$types  = "";

if ($search !== "") {
    $where .= " AND (
        CONCAT(d.first_name, ' ', d.last_name) LIKE ? 
        OR d.first_name LIKE ? 
        OR d.last_name LIKE ? 
        OR d.specialization LIKE ? 
    )";
    $like  = "%" . $search . "%";
    $params = [$like, $like, $like, $like];
    $types  = "ssss";
}

// PAGINARE
$per_page = 5;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

$sql_count = "SELECT COUNT(*) AS total_filtered FROM doctors d $where";

if (!empty($params)) {
    $stmt_count = mysqli_prepare($conn, $sql_count);
    mysqli_stmt_bind_param($stmt_count, $types, ...$params);
    mysqli_stmt_execute($stmt_count);
    $res_count = mysqli_stmt_get_result($stmt_count);
    $row_count = mysqli_fetch_assoc($res_count);
    mysqli_stmt_close($stmt_count);
} else {
    $res_count = mysqli_query($conn, $sql_count);
    $row_count = mysqli_fetch_assoc($res_count);
}

$total_filtered = (int)$row_count['total_filtered'];
$total_pages    = max(1, (int)ceil($total_filtered / $per_page));

// DOCTORS LIST
$sql_list = "
    SELECT d.*, COALESCE(p.cnt, 0) AS patients_count
    FROM doctors d
    LEFT JOIN (
        SELECT doctor_id, COUNT(*) AS cnt
        FROM patients
        GROUP BY doctor_id
    ) p ON p.doctor_id = d.id_doctor
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

$doctors = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $doctors[] = $row;
    }
    if (isset($stmt)) mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Doctors - AMC Clinic</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        .field-error {
            color: #d32f2f;
            font-size: 12px;
            margin-top: 3px;
        }

        /* -------------------------------------------------- */
        /*                  PAGINATION STYLE                  */
        /* -------------------------------------------------- */

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
            color: #003c46;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: 0.2s ease;
        }

        .pagination a:hover {
            background: #00bfa6;
            color: #ffffff;
            border-color: #00bfa6;
        }

        .pagination a.active {
            background: #00796b;
            border-color: #00796b;
            color: #ffffff !important;
            font-weight: 600;
            cursor: default;
        }

        .pagination a.disabled {
            pointer-events: none;
            opacity: 0.4;
        }

        /* Dacă vrei extra spacing pentru card */
        .doctors-list-card .pagination {
            margin-top: 20px;
        }

    </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box doctors-page">

        <div class="doctors-header">
            <h2>Doctors</h2>
            <p class="subtitle">Manage all doctors in the clinic.</p>
        </div>

        <?php if ($success !== "" && !$has_errors): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- ROW CARDS: OVERVIEW + SEARCH/SORT -->
        <div class="cards-row">

            <div class="card card-overview">
                <h3>Doctors Overview</h3>
                <div class="overview-number"><?= $total_doctors ?></div>
                <p class="overview-text">Total doctors in the clinic</p>
            </div>

            <div class="card card-search">
                <h3>Search &amp; Sort</h3>

                <form method="GET" class="search-form">
                    <div class="form-group">
                        <label for="q">Search doctor</label>
                        <input type="text"
                               id="q"
                               name="q"
                               placeholder="Ion, Maria, Cardiologie"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div class="form-group">
                        <label for="sort">Sort by</label>
                        <select name="sort" id="sort">
                            <option value="name"        <?= $sort === 'name' ? 'selected' : '' ?>>Default (name)</option>
                            <option value="exp_desc"    <?= $sort === 'exp_desc' ? 'selected' : '' ?>>Experience high → low</option>
                            <option value="exp_asc"     <?= $sort === 'exp_asc' ? 'selected' : '' ?>>Experience low → high</option>
                            <option value="salary_desc" <?= $sort === 'salary_desc' ? 'selected' : '' ?>>Salary high → low</option>
                            <option value="salary_asc"  <?= $sort === 'salary_asc' ? 'selected' : '' ?>>Salary low → high</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-apply">Apply</button>
                </form>
            </div>

        </div>

        <!-- DOCTORS LIST -->
        <div class="card doctors-list-card">
            <div class="card-header-row">
                <h3>Doctors List</h3>
                <button type="button" class="btn btn-add" id="btnAddDoctor">+ Add Doctor</button>
            </div>

            <div class="table-wrapper">
                <table class="doctors-table">
                    <thead>
                        <tr>
                            <th>NAME</th>
                            <th>SPECIALIZATION</th>
                            <th>EXPERIENCE</th>
                            <th>SALARY</th>
                            <th>PATIENTS</th>
                            <th>CONTACT</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($doctors)): ?>
                        <tr>
                            <td colspan="7" class="no-data">No doctors found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($doctors as $doc): ?>
                            <tr>
                                <td><?= htmlspecialchars($doc["first_name"] . " " . $doc["last_name"]) ?></td>
                                <td><?= htmlspecialchars($doc["specialization"]) ?></td>
                                <td><?= (int)$doc["experience_years"] ?> yrs</td>
                                <td><?= number_format((float)$doc["salary"], 2, ',', ' ') ?></td>
                                <td><?= (int)$doc["patients_count"] ?></td>
                                <td class="contact-cell">
                                    <?= htmlspecialchars($doc["phone"]) ?>
                                    <?php if ($doc["email"]): ?>
                                        / <?= htmlspecialchars($doc["email"]) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <button
                                        type="button"
                                        class="link-btn edit-btn"
                                        data-id="<?= $doc['id_doctor'] ?>"
                                        data-first_name="<?= htmlspecialchars($doc['first_name']) ?>"
                                        data-last_name="<?= htmlspecialchars($doc['last_name']) ?>"
                                        data-email="<?= htmlspecialchars($doc['email']) ?>"
                                        data-phone="<?= htmlspecialchars($doc['phone']) ?>"
                                        data-cnp="<?= htmlspecialchars($doc['cnp']) ?>"
                                        data-address="<?= htmlspecialchars($doc['address']) ?>"
                                        data-specialization="<?= htmlspecialchars($doc['specialization']) ?>"
                                        data-experience="<?= (int)$doc['experience_years'] ?>"
                                        data-hire_date="<?= htmlspecialchars($doc['hire_date']) ?>"
                                        data-salary="<?= htmlspecialchars($doc['salary']) ?>"
                                        data-notes="<?= htmlspecialchars($doc['notes']) ?>"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="link-btn delete-btn"
                                        data-id="<?= $doc['id_doctor'] ?>"
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

            <!-- PAGINARE -->
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

    </div> <!-- /content-box -->
</div> <!-- /main-content -->

<!-- MODAL ADD / EDIT DOCTOR -->
<div class="modal-overlay <?= $has_errors ? 'show' : '' ?>" id="doctorModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle"><?= $mode === 'edit' ? 'Edit Doctor' : 'Add Doctor' ?></h3>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>

        <form method="POST" id="doctorForm" class="modal-form">
            <input type="hidden" name="form_mode" id="formMode" value="<?= htmlspecialchars($mode) ?>">
            <input type="hidden" name="id_doctor" id="doctorId" value="<?= (int)$id_doctor ?>">

            <div class="modal-row">
                <div class="form-group">
                    <label>First name*</label>
                    <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($first_name) ?>" required>
                    <?php if (!empty($field_errors['first_name'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['first_name'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Last name*</label>
                    <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($last_name) ?>" required>
                    <?php if (!empty($field_errors['last_name'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['last_name'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="email" value="<?= htmlspecialchars($email) ?>">
                    <?php if (!empty($field_errors['email'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['email'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Phone (9 digits)</label>
                    <input type="text" name="phone" id="phone" maxlength="9" value="<?= htmlspecialchars($phone) ?>">
                    <?php if (!empty($field_errors['phone'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['phone'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>CNP (13 digits)*</label>
                    <input type="text" name="cnp" id="cnp" maxlength="13" value="<?= htmlspecialchars($cnp) ?>" required>
                    <?php if (!empty($field_errors['cnp'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['cnp'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Specialization*</label>
                    <input type="text" name="specialization" id="specialization" value="<?= htmlspecialchars($specialization) ?>" required>
                    <?php if (!empty($field_errors['specialization'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['specialization'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Experience (years)</label>
                    <input type="number" name="experience_years" id="experience_years" min="0" value="<?= htmlspecialchars((string)$experience_years) ?>">
                    <?php if (!empty($field_errors['experience_years'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['experience_years'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Hire date (YYYY-MM-DD)</label>
                    <input type="date" name="hire_date" id="hire_date" value="<?= htmlspecialchars($hire_date) ?>">
                    <?php if (!empty($field_errors['hire_date'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['hire_date'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Salary</label>
                    <input type="number" step="0.01" name="salary" id="salary" min="0" value="<?= htmlspecialchars((string)$salary_raw ?? (string)$salary) ?>">
                    <?php if (!empty($field_errors['salary'])): ?>
                        <div class="field-error">
                            <?= implode('<br>', array_map('htmlspecialchars', $field_errors['salary'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" id="address" value="<?= htmlspecialchars($address) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" id="notes" rows="3"><?= htmlspecialchars($notes) ?></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">
                    <?= $mode === 'edit' ? 'Update doctor' : 'Save doctor' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- CONFIRM DELETE POPUP -->
<div class="confirm-overlay" id="deleteConfirm">
    <div class="confirm-box">
        <h3>Delete doctor</h3>
        <p>Are you sure you want to delete this doctor? This action cannot be undone.</p>
        <div class="confirm-buttons">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, delete</button>
        </div>
    </div>
</div>



<script>
// ---------- MODAL ADD / EDIT ----------
const doctorModal = document.getElementById('doctorModal');
const btnAddDoctor = document.getElementById('btnAddDoctor');
const closeModalBtn = document.getElementById('closeModal');
const cancelModal = document.getElementById('cancelModal');
const modalTitle = document.getElementById('modalTitle');
const formModeInput = document.getElementById('formMode');
const doctorIdInput = document.getElementById('doctorId');
const saveBtn = document.getElementById('saveBtn');

function openModal() {
    doctorModal.classList.add('show');
}

function closeModal() {
    doctorModal.classList.remove('show');
}

btnAddDoctor.addEventListener('click', () => {
    modalTitle.textContent = "Add Doctor";
    formModeInput.value = "add";
    doctorIdInput.value = "0";

    document.getElementById('first_name').value = "";
    document.getElementById('last_name').value = "";
    document.getElementById('email').value = "";
    document.getElementById('phone').value = "";
    document.getElementById('cnp').value = "";
    document.getElementById('address').value = "";
    document.getElementById('specialization').value = "";
    document.getElementById('experience_years').value = "";
    document.getElementById('hire_date').value = "";
    document.getElementById('salary').value = "";
    document.getElementById('notes').value = "";

    saveBtn.textContent = "Save doctor";
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
        modalTitle.textContent = "Edit Doctor";
        formModeInput.value = "edit";
        doctorIdInput.value = btn.dataset.id;

        document.getElementById('first_name').value = btn.dataset.first_name;
        document.getElementById('last_name').value = btn.dataset.last_name;
        document.getElementById('email').value = btn.dataset.email;
        document.getElementById('phone').value = btn.dataset.phone;
        document.getElementById('cnp').value = btn.dataset.cnp;
        document.getElementById('address').value = btn.dataset.address;
        document.getElementById('specialization').value = btn.dataset.specialization;
        document.getElementById('experience_years').value = btn.dataset.experience;
        document.getElementById('hire_date').value = btn.dataset.hire_date;
        document.getElementById('salary').value = btn.dataset.salary;
        document.getElementById('notes').value = btn.dataset.notes;

        saveBtn.textContent = "Update doctor";
        openModal();
    });
});

// ---------- CLIENT VALIDATION SIMPLĂ ----------
const doctorForm = document.getElementById('doctorForm');
doctorForm.addEventListener('submit', (e) => {
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const cnp = document.getElementById('cnp').value.trim();

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

    if (!/^[0-9]{13}$/.test(cnp)) {
        alert("CNP-ul trebuie să conțină exact 13 cifre.");
        e.preventDefault();
        return;
    }
});

// ---------- DELETE CONFIRM ----------
const deleteOverlay = document.getElementById('deleteConfirm');
const cancelDeleteBtn = document.getElementById('cancelDelete');
const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
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

confirmDeleteBtn.addEventListener('click', () => {
    if (deleteId) {
        window.location.href = "doctors.php?delete=" + deleteId;
    }
});
</script>
<script src="assets/main.js" defer></script>

</body>
</html>
