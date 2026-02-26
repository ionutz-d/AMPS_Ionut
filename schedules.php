<?php
// schedules.php
$page_title = "Schedules";
$active     = "schedules";

require_once "auth.php";
require_login();
require_once "db.php";

$errors  = [];
$success = "";

// zile disponibile (în română, cum sunt în DB)
$DAYS = ['Luni','Marti','Miercuri','Joi','Vineri','Sambata','Duminica'];

// ---------- HELPERI ----------
function clean($v) {
    return trim($v ?? '');
}

function is_valid_time_hm($t) {
    // HH:MM
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

// ---------- ADD / EDIT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mode          = $_POST['form_mode'] ?? 'add';
    $id_schedule   = (int)($_POST['id_schedule'] ?? 0);

    $doctor_id  = (int)($_POST['doctor_id'] ?? 0);
    $room       = clean($_POST['room'] ?? '');
    $start_time = clean($_POST['start_time'] ?? '');
    $end_time   = clean($_POST['end_time'] ?? '');
    $is_active  = clean($_POST['is_active'] ?? 'active');
    $days       = $_POST['days'] ?? [];

    if (!is_array($days)) {
        $days = [];
    }

    // ---- VALIDĂRI ----
    if ($doctor_id <= 0) {
        $errors[] = "Doctorul este obligatoriu.";
    }

    if ($room === '') {
        $errors[] = "Cabinetul este obligatoriu.";
    }

    if (!is_valid_time_hm($start_time) || !is_valid_time_hm($end_time)) {
        $errors[] = "Ora de început și ora de sfârșit trebuie să fie în format HH:MM.";
    } else {
        if (strtotime($start_time) >= strtotime($end_time)) {
            $errors[] = "Ora de început trebuie să fie mai mică decât ora de sfârșit.";
        }
    }

    if (!in_array($is_active, ['active','inactive'], true)) {
        $errors[] = "Status invalid.";
    }

    // filtrăm zilele valide
    $valid_days = [];
    foreach ($days as $d) {
        if (in_array($d, $DAYS, true)) {
            $valid_days[] = $d;
        }
    }

    if (empty($valid_days)) {
        $errors[] = "Bifează cel puțin o zi din săptămână.";
    }

    if (empty($errors)) {

        if ($mode === 'add') {
            // inserăm câte un rând pentru fiecare zi
            $sql = "INSERT INTO schedules
                    (doctor_id, day_of_week, start_time, end_time, room, is_active)
                    VALUES (?,?,?,?,?,?)";
            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                foreach ($valid_days as $day) {
                    mysqli_stmt_bind_param(
                        $stmt,
                        "isssss",
                        $doctor_id,
                        $day,
                        $start_time,
                        $end_time,
                        $room,
                        $is_active
                    );
                    mysqli_stmt_execute($stmt);
                }
                mysqli_stmt_close($stmt);
                $success = "Schedule(s) added successfully.";
            }

        } else { // edit – actualizăm UN singur rând (zi)
            if ($id_schedule > 0) {
                // luăm doar prima zi selectată
                $day_single = $valid_days[0];

                $sql = "UPDATE schedules SET
                            doctor_id = ?,
                            day_of_week = ?,
                            start_time = ?,
                            end_time   = ?,
                            room       = ?,
                            is_active  = ?
                        WHERE id_schedule = ?";

                $stmt = mysqli_prepare($conn, $sql);
                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt,
                        "isssssi",
                        $doctor_id,
                        $day_single,
                        $start_time,
                        $end_time,
                        $room,
                        $is_active,
                        $id_schedule
                    );
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $success = "Schedule updated successfully.";
                }
            }
        }

        header("Location: schedules.php?msg=" . urlencode($success));
        exit;
    }
}

// ---------- DELETE ----------
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM schedules WHERE id_schedule = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("Location: schedules.php?msg=" . urlencode("Schedule deleted successfully."));
        exit;
    }
}

// ---------- SEARCH + SORT + PAGINARE ----------
$search = clean($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'doctor_day';

// sort implicit: doctor, apoi zi
$sort_sql = "ORDER BY d.last_name, d.first_name,
             FIELD(s.day_of_week,'Luni','Marti','Miercuri','Joi','Vineri','Sambata','Duminica'),
             s.start_time";

switch ($sort) {
    case 'day':
        $sort_sql = "ORDER BY
             FIELD(s.day_of_week,'Luni','Marti','Miercuri','Joi','Vineri','Sambata','Duminica'),
             d.last_name, d.first_name, s.start_time";
        break;
    case 'start_time':
        $sort_sql = "ORDER BY s.start_time ASC, d.last_name, d.first_name";
        break;
    case 'status':
        $sort_sql = "ORDER BY
             CASE WHEN s.is_active='active' THEN 0 ELSE 1 END,
             d.last_name, d.first_name,
             FIELD(s.day_of_week,'Luni','Marti','Miercuri','Joi','Vineri','Sambata','Duminica')";
        break;
    default:
        // doctor_day – deja setat
        break;
}

// total active slots (pentru card overview)
$res_total = mysqli_query($conn, "SELECT COUNT(*) AS total_active FROM schedules WHERE is_active='active'");
$row_total = mysqli_fetch_assoc($res_total);
$total_active = (int)$row_total['total_active'];

// doctori pentru dropdown
$doctors = [];
$res_doc = mysqli_query($conn, "SELECT id_doctor, first_name, last_name FROM doctors ORDER BY last_name, first_name");
if ($res_doc) {
    while ($d = mysqli_fetch_assoc($res_doc)) {
        $doctors[] = $d;
    }
}

// WHERE comun pentru search
$where = "WHERE 1";
$params = [];
$types  = "";

if ($search !== "") {
    $where .= " AND (
        CONCAT(d.first_name,' ',d.last_name) LIKE ?
        OR s.day_of_week LIKE ?
        OR s.room LIKE ?
    )";
    $like   = "%" . $search . "%";
    $params = [$like, $like, $like];
    $types  = "sss";
}

// PAGINARE
$per_page = 5;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

// COUNT filtrat
$sql_count = "
    SELECT COUNT(*) AS total_filtered
    FROM schedules s
    JOIN doctors d ON d.id_doctor = s.doctor_id
    $where
";

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

// lista schedules cu LIMIT/OFFSET
$sql_list = "
    SELECT s.*,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM schedules s
    JOIN doctors d ON d.id_doctor = s.doctor_id
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

$schedules = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $schedules[] = $row;
    }
    if (isset($stmt)) mysqli_stmt_close($stmt);
}

// mesaj succes
if (isset($_GET['msg'])) {
    $success = $_GET['msg'];
}

// BLOCUL PHP CARE GENERA JSON ȘI DISTRUGEA PAGINA A FOST ELIMINAT DE AICI
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Schedules - AMC Clinic</title>
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
        
        /* Ajustări pentru spațiul din partea de sus */
        .main-content {
            padding-top: 20px; /* Asigură un spațiu rezonabil sub header-ul fix, dacă există */
        }
        .schedules-page {
             padding-top: 0; /* Elimină padding-ul excesiv dacă era aplicat de o altă clasă */
        }
        /* Asigură că titlul și subtitlul sunt bine poziționate */
        .doctors-header {
             margin-top: 0; 
             margin-bottom: 20px; 
        }

        /* Stiluri pentru Modal */
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
        .modal-close {
            /* Stil specific pentru butonul X din modal */
        }

        /* Stiluri pentru Day Checkboxes */
        .days-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .day-check-label {
            display: flex;
            align-items: center;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .day-check-label:has(input:checked) {
            background-color: #e0f7fa; /* Culoare deschisă când este bifat */
            border-color: #00796b;
            font-weight: 600;
        }
        .day-checkbox {
            margin-right: 8px;
        }
      </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box schedules-page">

        <div class="doctors-header">
            <h2>Schedules</h2>
            <p class="subtitle">Manage time slots for doctors.</p>
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

        <?php if ($success !== ""): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="cards-row">
            <div class="card card-overview">
                <h3>Schedules overview</h3>
                <div class="overview-number"><?= $total_active ?></div>
                <p class="overview-text">Total active time slots for doctors</p>
            </div>

            <div class="card card-search">
                <h3>Search &amp; sort</h3>

                <form method="GET" class="search-form">
                    <div class="form-group">
                        <label for="q">Search by doctor, day or room</label>
                        <input type="text"
                               id="q"
                               name="q"
                               placeholder="Ex: Popescu, Luni, Cabinet 1"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div class="form-group">
                        <label for="sort">Sort by</label>
                        <select name="sort" id="sort">
                            <option value="doctor_day" <?= $sort === 'doctor_day' ? 'selected' : '' ?>>Doctor, then day</option>
                            <option value="day"        <?= $sort === 'day' ? 'selected' : '' ?>>Day of week</option>
                            <option value="start_time" <?= $sort === 'start_time' ? 'selected' : '' ?>>Start time</option>
                            <option value="status"     <?= $sort === 'status' ? 'selected' : '' ?>>Status (active / inactive)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-apply">Apply</button>
                </form>
            </div>
        </div>

        <div class="card doctors-list-card">
            <div class="card-header-row">
                <h3>Schedules list</h3>
                <button type="button" class="btn btn-add" id="btnAddSchedule">+ Add Schedule</button>
            </div>

            <div class="table-wrapper">
                <table class="doctors-table">
                    <thead>
                        <tr>
                            <th>DOCTOR</th>
                            <th>DAY</th>
                            <th>TIME INTERVAL</th>
                            <th>ROOM</th>
                            <th>STATUS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="6" class="no-data">No schedules found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $s): ?>
                            <?php
                                $time_interval = substr($s['start_time'],0,5) . " - " . substr($s['end_time'],0,5);
                                $status_label = $s['is_active'] === 'active' ? 'Active' : 'Inactive';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($s['doctor_name']) ?></td>
                                <td><?= htmlspecialchars($s['day_of_week']) ?></td>
                                <td><?= htmlspecialchars($time_interval) ?></td>
                                <td><?= htmlspecialchars($s['room']) ?></td>
                                <td><?= htmlspecialchars($status_label) ?></td>
                                <td class="actions">
                                    <button
                                        type="button"
                                        class="link-btn edit-btn"
                                        data-id="<?= $s['id_schedule'] ?>"
                                        data-doctor_id="<?= (int)$s['doctor_id'] ?>"
                                        data-day="<?= htmlspecialchars($s['day_of_week']) ?>"
                                        data-start="<?= htmlspecialchars(substr($s['start_time'],0,5)) ?>"
                                        data-end="<?= htmlspecialchars(substr($s['end_time'],0,5)) ?>"
                                        data-room="<?= htmlspecialchars($s['room']) ?>"
                                        data-status="<?= htmlspecialchars($s['is_active']) ?>"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="link-btn delete-btn"
                                        data-id="<?= $s['id_schedule'] ?>"
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

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="pag-btn"
                           href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>&sort=<?= htmlspecialchars($sort) ?>">Prev</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a class="pag-btn <?= $i === $page ? 'active' : '' ?>"
                           href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&sort=<?= htmlspecialchars($sort) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a class="pag-btn"
                           href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>&sort=<?= htmlspecialchars($sort) ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

    </div></div><div class="modal-overlay" id="scheduleModal">
    <div class="modal-box large-modal">
        <div class="modal-header">
            <h3 id="modalTitle">Add Schedule</h3>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>

        <form method="POST" id="scheduleForm" class="modal-form">
            <input type="hidden" name="form_mode" id="formMode" value="add">
            <input type="hidden" name="id_schedule" id="scheduleId" value="0">

            <div class="modal-row">
                <div class="form-group">
                    <label>Doctor*</label>
                    <select name="doctor_id" id="doctor_id" required>
                        <option value="">Select doctor...</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id_doctor'] ?>">
                                <?= htmlspecialchars($doc['last_name'] . ' ' . $doc['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Room*</label>
                    <select name="room" id="room" required>
                        <option value="">Select room...</option>
                        <?php for ($i=1; $i<=10; $i++): ?>
                            <option value="Cabinet <?= $i ?>">Cabinet <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status*</label>
                    <select name="is_active" id="is_active" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Start time*</label>
                    <input type="time" name="start_time" id="start_time" required>
                </div>
                <div class="form-group">
                    <label>End time*</label>
                    <input type="time" name="end_time" id="end_time" required>
                </div>
                <div class="form-group"></div>
            </div>

            <div class="form-group full">
                <label>Days of week*</label>
                <div class="days-grid">
                    <?php foreach ($DAYS as $day): ?>
                        <?php $id = 'day_' . strtolower($day); ?>
                        <label class="day-check-label">
                            <input type="checkbox"
                                   class="day-checkbox"
                                   name="days[]"
                                   id="<?= $id ?>"
                                   value="<?= $day ?>">
                            <span><?= $day ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="help-text">
                    Bifează una sau mai multe zile. Același interval orar se aplică pentru toate zilele selectate.
                </p>
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
            <h3>Delete schedule</h3>
            <button type="button" class="modal-close" id="closeDeletePopup">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this schedule? This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, delete</button>
        </div>
    </div>
</div>

<script>
// ---------- MODAL ADD / EDIT ----------
const scheduleModal   = document.getElementById('scheduleModal');
const btnAddSchedule  = document.getElementById('btnAddSchedule');
const closeModalBtn   = document.getElementById('closeModal');
const cancelModal     = document.getElementById('cancelModal');
const modalTitle      = document.getElementById('modalTitle');
const formModeInput   = document.getElementById('formMode');
const scheduleIdInput = document.getElementById('scheduleId');
const saveBtn         = document.getElementById('saveBtn');

function openModal() {
    scheduleModal.classList.add('show');
}
function closeModal() {
    scheduleModal.classList.remove('show');
}

btnAddSchedule.addEventListener('click', () => {
    modalTitle.textContent  = "Add Schedule";
    formModeInput.value     = "add";
    scheduleIdInput.value   = "0";

    document.getElementById('doctor_id').value  = "";
    document.getElementById('room').value       = "";
    document.getElementById('is_active').value  = "active";
    document.getElementById('start_time').value = "";
    document.getElementById('end_time').value   = "";

    document.querySelectorAll('.day-checkbox').forEach(cb => {
        cb.checked = false;
        // La ADD, toate checkbox-urile sunt active
        cb.disabled = false;
    });

    saveBtn.textContent = "Save schedule";
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
        modalTitle.textContent  = "Edit Schedule";
        formModeInput.value     = "edit";
        scheduleIdInput.value   = btn.dataset.id;

        document.getElementById('doctor_id').value  = btn.dataset.doctor_id;
        document.getElementById('room').value       = btn.dataset.room;
        document.getElementById('is_active').value  = btn.dataset.status;
        document.getElementById('start_time').value = btn.dataset.start;
        document.getElementById('end_time').value   = btn.dataset.end;

        // Reset zile
        document.querySelectorAll('.day-checkbox').forEach(cb => {
            cb.checked = false;
            // La EDIT, DEZACTIVĂM toate celelalte zile pentru că edităm un singur rând (zi).
            cb.disabled = true; 
        });
        
        // Bifează și activează ziua curentă (la edit lucrăm pe un singur rând)
        const day = btn.dataset.day;
        document.querySelectorAll('.day-checkbox').forEach(cb => {
            if (cb.value === day) {
                cb.checked = true;
                cb.disabled = false; // Reactivăm doar ziua curentă pentru editare
            }
        });

        saveBtn.textContent = "Update schedule";
        openModal();
    });
});

// ---------- VALIDARE FRONT-END ----------
const scheduleForm = document.getElementById('scheduleForm');
scheduleForm.addEventListener('submit', (e) => {
    const doctor = document.getElementById('doctor_id').value;
    const room   = document.getElementById('room').value;
    const start  = document.getElementById('start_time').value;
    const end    = document.getElementById('end_time').value;

    const checkedDays = Array.from(document.querySelectorAll('.day-checkbox:not(:disabled)'))
                             .filter(cb => cb.checked);

    if (!doctor || !room || !start || !end) {
        alert("Doctor, room, start time și end time sunt obligatorii.");
        e.preventDefault();
        return;
    }

    if (checkedDays.length === 0) {
        alert("Bifează cel puțin o zi din săptămână.");
        e.preventDefault();
        return;
    }

    if (start >= end) {
        alert("Start time trebuie să fie mai mic decât end time.");
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
const closeDeletePopupBtn = document.getElementById('closeDeletePopup');
if (closeDeletePopupBtn) {
    closeDeletePopupBtn.addEventListener('click', closeDeletePopup);
}


confirmDeleteBtn.addEventListener('click', () => {
    if (deleteId) {
        window.location.href = "schedules.php?delete=" + deleteId;
    }
});
</script>

</body>
</html>