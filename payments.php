<?php
// payments.php
$page_title = "Payments";
$active     = "payments";

require_once "auth.php";
require_login();
require_once "db.php";

// Definirea constantei pentru numărul de înregistrări pe pagină
const RECORDS_PER_PAGE = 5;

$errors  = [];
$success = [];

// ---------- HELPERI ----------
function clean($v) {
    return trim($v ?? '');
}

function is_valid_method($m) {
    return in_array($m, ['cash','card','transfer'], true);
}
function is_valid_status_pay($s) {
    return in_array($s, ['platita','neplatita','returnata'], true);
}

// Functie pentru a formata suma
function format_amount($v) {
    return number_format((float)$v, 2, ',', ' ');
}

// Funcție utilitară pentru a genera URL-ul pentru paginare (păstrează filtrele)
function get_pagination_url($page, $params = []) {
    $base_url = "payments.php?";
    $query = array_merge($_GET, $params, ['page' => $page]);
    // Elimină 'msg' dacă există
    unset($query['msg']);
    return $base_url . http_build_query($query);
}

// ---------- EDIT PAYMENT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mode       = $_POST['form_mode'] ?? '';
    $id_payment = (int)($_POST['id_payment'] ?? 0);

    if ($mode === 'edit' && $id_payment > 0) {

        $amount         = clean($_POST['amount'] ?? '');
        $payment_date   = clean($_POST['payment_date'] ?? '');
        $payment_method = clean($_POST['payment_method'] ?? '');
        $status         = clean($_POST['status'] ?? '');
        $receipt_number = clean($_POST['receipt_number'] ?? '');
        $notes          = clean($_POST['notes'] ?? '');

        // VALIDĂRI
        if ($amount === '' || !is_numeric($amount) || $amount <= 0) {
            $errors[] = "Amount invalid. Trebuie să fie un număr pozitiv.";
        }

        if ($payment_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            $errors[] = "Payment date trebuie să fie în formatul YYYY-MM-DD.";
        }

        if (!is_valid_method($payment_method)) {
            $errors[] = "Payment method invalid.";
        }

        if (!is_valid_status_pay($status)) {
            $errors[] = "Payment status invalid.";
        }

        if (empty($errors)) {
            $sql = "UPDATE payments SET
                        amount         = ?,
                        payment_date   = ?,
                        payment_method = ?,
                        status         = ?,
                        receipt_number = ?,
                        notes          = ?
                    WHERE id_payment  = ?";
            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "dsssssi",
                    $amount,
                    $payment_date,
                    $payment_method,
                    $status,
                    $receipt_number,
                    $notes,
                    $id_payment
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $success[] = "Payment updated successfully.";
                header("Location: payments.php?msg=" . urlencode(implode(" ", $success)));
                exit;
            } else {
                // Eroare la prepared statement
                $errors[] = "Database error (UPDATE): " . mysqli_error($conn);
            }
        }
    }
}

// ---------- DELETE PAYMENT ----------
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM payments WHERE id_payment = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("Location: payments.php?msg=" . urlencode("Payment deleted successfully."));
        exit;
    }
}

// ---------- OVERVIEW CARDS ----------
function sum_payments_where($conn, $where) {
    // Escapare pentru a preveni SQL Injection pe partea de WHERE dinamic
    $safe_where = mysqli_real_escape_string($conn, $where);
    $sql = "SELECT IFNULL(SUM(amount),0) AS total_sum
            FROM payments
            WHERE status = 'platita' " . $safe_where;
    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : ['total_sum' => 0];
    return (float)$row['total_sum'];
}

// today
$total_today = sum_payments_where($conn, "AND payment_date = CURDATE()");
// this month
$total_month = sum_payments_where(
    $conn,
    "AND YEAR(payment_date)=YEAR(CURDATE())
     AND MONTH(payment_date)=MONTH(CURDATE())"
);
// this year
$total_year = sum_payments_where(
    $conn,
    "AND YEAR(payment_date)=YEAR(CURDATE())"
);

// ---------- SEARCH + FILTERS + PAGINATION LOGIC ----------
$search      = clean($_GET['q'] ?? '');
$period      = $_GET['period'] ?? 'month';
$method      = $_GET['method'] ?? 'all';
$status_filt = $_GET['status'] ?? 'all';
$sort        = $_GET['sort'] ?? 'date_new';
$current_page = max(1, (int)($_GET['page'] ?? 1)); // Pagina curentă

// baza SELECT-ului
$sql_list = "
    SELECT pay.*,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
           CONCAT(a.appointment_date, ' ', DATE_FORMAT(a.appointment_time,'%H:%i')) AS appointment_dt
    FROM payments pay
    LEFT JOIN patients p ON p.id_patient = pay.patient_id
    LEFT JOIN appointments a ON a.id_appointment = pay.appointment_id
    LEFT JOIN doctors d ON d.id_doctor = a.doctor_id
    WHERE 1
";

$sql_count = "
    SELECT COUNT(*) as total_records
    FROM payments pay
    LEFT JOIN patients p ON p.id_patient = pay.patient_id
    LEFT JOIN appointments a ON a.id_appointment = pay.appointment_id
    LEFT JOIN doctors d ON d.id_doctor = a.doctor_id
    WHERE 1
";


$params = [];
$types  = "";

// SEARCH by patient or doctor
if ($search !== "") {
    $search_condition = " AND (
        CONCAT(p.first_name,' ',p.last_name) LIKE ?
        OR CONCAT(d.first_name,' ',d.last_name) LIKE ?
    )";
    $sql_list .= $search_condition;
    $sql_count .= $search_condition;
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $types  .= "ss";
}

// PERIOD filter
$period_condition = "";
if ($period === 'today') {
    $period_condition = " AND pay.payment_date = CURDATE()";
} elseif ($period === 'month') {
    $period_condition = " AND YEAR(pay.payment_date)=YEAR(CURDATE())
                          AND MONTH(pay.payment_date)=MONTH(CURDATE())";
} elseif ($period === 'year') {
    $period_condition = " AND YEAR(pay.payment_date)=YEAR(CURDATE())";
}
$sql_list .= $period_condition;
$sql_count .= $period_condition;

// METHOD filter
if (in_array($method, ['cash','card','transfer'], true)) {
    $method_condition = " AND pay.payment_method = ?";
    $sql_list .= $method_condition;
    $sql_count .= $method_condition;
    $params[] = $method;
    $types  .= "s";
}

// STATUS filter
if (in_array($status_filt, ['platita','neplatita','returnata'], true)) {
    $status_condition = " AND pay.status = ?";
    $sql_list .= $status_condition;
    $sql_count .= $status_condition;
    $params[] = $status_filt;
    $types  .= "s";
}

// 1. CALCULUL TOTAL DE ÎNREGISTRĂRI
if (!empty($params)) {
    // Replicarea parametrilor pentru prepared statement (types și params)
    // Deoarece folosim același set de condiții pentru COUNT și SELECT
    $stmt_count = mysqli_prepare($conn, $sql_count);
    // Bind parameters for COUNT query
    // Trebuie să reconstruim array-ul de parametri dacă $params include tipuri multiple pentru a nu altera array-ul original
    // Totuși, deoarece $params este deja construit cu filtre, îl putem refolosi.
    // Dar dacă tipurile și numărul de parametri diferă între COUNT și SELECT, ar trebui gestionat separat.
    // În acest caz, deoarece structura WHERE este identică, este OK.
    mysqli_stmt_bind_param($stmt_count, $types, ...$params);
    mysqli_stmt_execute($stmt_count);
    $result_count = mysqli_stmt_get_result($stmt_count);
    $row_count = mysqli_fetch_assoc($result_count);
    $total_records = $row_count['total_records'];
    mysqli_stmt_close($stmt_count);
} else {
    $result_count = mysqli_query($conn, $sql_count);
    $row_count = $result_count ? mysqli_fetch_assoc($result_count) : ['total_records' => 0];
    $total_records = $row_count['total_records'];
}

$total_pages = ceil($total_records / RECORDS_PER_PAGE);
// Asigură-te că pagina curentă nu depășește numărul total de pagini
$current_page = min($current_page, $total_pages > 0 ? $total_pages : 1);
$offset = ($current_page - 1) * RECORDS_PER_PAGE;

// 2. QUERY CU LIMIT ȘI OFFSET

// SORTING
$order_by = "";
if      ($sort === 'date_old')      $order_by = " ORDER BY pay.payment_date ASC, pay.id_payment ASC";
elseif  ($sort === 'amount_high')   $order_by = " ORDER BY pay.amount DESC";
elseif  ($sort === 'amount_low')    $order_by = " ORDER BY pay.amount ASC";
else                                $order_by = " ORDER BY pay.payment_date DESC, pay.id_payment DESC";

$sql_list .= $order_by;
$sql_list .= " LIMIT " . RECORDS_PER_PAGE . " OFFSET " . $offset;

// executăm query-ul final
if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql_list);
    // Re-bind parameters for the final SELECT query
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql_list);
}

$payments = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $payments[] = $row;
    }
    if (isset($stmt)) mysqli_stmt_close($stmt);
}

// mesaj succes din redirect
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $success[] = $_GET['msg'];
}

// Paginile necesare (sidebar.php și header.php sunt incluse)
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Payments - AMC Clinic</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
</head>
<style>
    /* ======================================================= */
/* PAYMENTS TABLE – FIXED COLUMNS LIKE POINTMAN      */
/* ======================================================= */

.payments-page table.doctors-table {
    table-layout: fixed !important;
    width: 100% !important;
}

/* 1: PATIENT */
.payments-page table.doctors-table th:nth-child(1),
.payments-page table.doctors-table td:nth-child(1) {
    width: 160px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 2: DOCTOR */
.payments-page table.doctors-table th:nth-child(2),
.payments-page table.doctors-table td:nth-child(2) {
    width: 160px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 3: APPOINTMENT */
.payments-page table.doctors-table th:nth-child(3),
.payments-page table.doctors-table td:nth-child(3) {
    width: 150px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 4: AMOUNT */
.payments-page table.doctors-table th:nth-child(4),
.payments-page table.doctors-table td:nth-child(4) {
    width: 90px;
    text-align: center;
    white-space: nowrap;
}

/* 5: METHOD */
.payments-page table.doctors-table th:nth-child(5),
.payments-page table.doctors-table td:nth-child(5) {
    width: 90px;
    white-space: nowrap;
    text-align: center;
}

/* 6: STATUS */
.payments-page table.doctors-table th:nth-child(6),
.payments-page table.doctors-table td:nth-child(6) {
    width: 100px;
    white-space: nowrap;
    text-align: center;
}

/* 7: PAYMENT DATE */
.payments-page table.doctors-table th:nth-child(7),
.payments-page table.doctors-table td:nth-child(7) {
    width: 130px;
    white-space: nowrap;
    text-align: center;
}

/* 8: ACTIONS */
.payments-page table.doctors-table th:nth-child(8),
.payments-page table.doctors-table td:nth-child(8) {
    width: 110px;
    white-space: nowrap;
    text-align: center;
}

/* ======================================================= */
/* PAGINATION                        */
/* ======================================================= */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
    padding: 10px 0;
    border-top: 1px solid #eee;
}

.pagination-info {
    font-size: 0.9em;
    color: #666;
}

.pagination-links a {
    text-decoration: none;
    padding: 5px 10px;
    margin: 0 2px;
    border-radius: 4px;
    transition: background-color 0.2s;
    font-size: 0.9em;
}

.pagination-links .btn-secondary {
    background-color: #f0f0f0;
    color: #333;
    border: 1px solid #ccc;
}

.pagination-links .btn-primary {
    background-color: #007bff; /* Culoarea primară a temei tale */
    color: white;
    border: 1px solid #007bff;
    font-weight: bold;
}

.pagination-links .btn-secondary:hover {
    background-color: #ddd;
}
</style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box payments-page">

        <div class="doctors-header">
            <h2>Payments</h2>
            <p class="subtitle">View and manage all payments registered in the clinic.</p>
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

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars(implode(" ", $success)) ?>
            </div>
        <?php endif; ?>

        <div class="cards-row">
            <div class="card card-overview payments-today">
                <h3>Total payments today</h3>
                <div class="overview-number"><?= format_amount($total_today) ?></div>
                <p class="overview-text">Suma plăților încasate azi (status „plătita”).</p>
            </div>

            <div class="card card-overview payments-month">
                <h3>Total this month</h3>
                <div class="overview-number"><?= format_amount($total_month) ?></div>
                <p class="overview-text">Suma plăților încasate în luna curentă.</p>
            </div>

            <div class="card card-overview payments-year">
                <h3>Total this year</h3>
                <div class="overview-number"><?= format_amount($total_year) ?></div>
                <p class="overview-text">Suma plăților încasate în anul curent.</p>
            </div>
        </div>

        <div class="card card-search">
            <h3>Search, filters &amp; sort</h3>

            <form method="GET" class="search-form search-form-row">
                <div class="form-group">
                    <label for="q">Search by patient or doctor</label>
                    <input type="text"
                           id="q"
                           name="q"
                           placeholder="Ex: Ion, Popescu, Dr. Ionescu"
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="form-group">
                    <label for="period">Period</label>
                    <select name="period" id="period">
                        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This month</option>
                        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="year"  <?= $period === 'year'  ? 'selected' : '' ?>>This year</option>
                        <option value="all"   <?= $period === 'all'   ? 'selected' : '' ?>>All time</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="method">Method</label>
                    <select name="method" id="method">
                        <option value="all"      <?= $method === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="cash"     <?= $method === 'cash' ? 'selected' : '' ?>>cash</option>
                        <option value="card"     <?= $method === 'card' ? 'selected' : '' ?>>card</option>
                        <option value="transfer" <?= $method === 'transfer' ? 'selected' : '' ?>>transfer</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="all"      <?= $status_filt === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="platita"  <?= $status_filt === 'platita' ? 'selected' : '' ?>>platita</option>
                        <option value="neplatita" <?= $status_filt === 'neplatita' ? 'selected' : '' ?>>neplatita</option>
                        <option value="returnata" <?= $status_filt === 'returnata' ? 'selected' : '' ?>>returnata</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sort">Sort by</label>
                    <select name="sort" id="sort">
                        <option value="date_new"    <?= $sort === 'date_new' ? 'selected' : '' ?>>Date (new → old)</option>
                        <option value="date_old"    <?= $sort === 'date_old' ? 'selected' : '' ?>>Date (old → new)</option>
                        <option value="amount_high" <?= $sort === 'amount_high' ? 'selected' : '' ?>>Amount (high → low)</option>
                        <option value="amount_low"  <?= $sort === 'amount_low' ? 'selected' : '' ?>>Amount (low → high)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-apply">Apply</button>
            </form>
        </div>

        <div class="card doctors-list-card">
            <div class="card-header-row">
                <h3>Payments list</h3>
                </div>

            <div class="table-wrapper">
                <table class="doctors-table payments-table">
                    <thead>
                        <tr>
                            <th>PATIENT</th>
                            <th>DOCTOR</th>
                            <th>APPOINTMENT</th>
                            <th>AMOUNT</th>
                            <th>METHOD</th>
                            <th>STATUS</th>
                            <th>PAYMENT DATE</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="8" class="no-data">No payments found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?= htmlspecialchars($pay['patient_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($pay['doctor_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($pay['appointment_dt'] ?? '-') ?></td>
                                <td><?= format_amount($pay['amount']) ?></td>
                                <td><?= htmlspecialchars($pay['payment_method']) ?></td>
                                <td><?= htmlspecialchars($pay['status']) ?></td>
                                <td><?= htmlspecialchars($pay['payment_date']) ?></td>
                                
                                <td class="actions">
                                    <button
                                        type="button"
                                        class="link-btn edit-btn"
                                        data-id="<?= (int)$pay['id_payment'] ?>"
                                        data-amount="<?= htmlspecialchars($pay['amount']) ?>"
                                        data-date="<?= htmlspecialchars($pay['payment_date']) ?>"
                                        data-method="<?= htmlspecialchars($pay['payment_method']) ?>"
                                        data-status="<?= htmlspecialchars($pay['status']) ?>"
                                        data-receipt="<?= htmlspecialchars($pay['receipt_number']) ?>"
                                        data-notes="<?= htmlspecialchars($pay['notes']) ?>"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="link-btn delete-btn"
                                        data-id="<?= (int)$pay['id_payment'] ?>"
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
                <div class="pagination-container">
                    <p class="pagination-info">
                        Showing **<?= $offset + 1 ?>** to **<?= min($offset + RECORDS_PER_PAGE, $total_records) ?>** of **<?= $total_records ?>** payments
                    </p>
                    <div class="pagination-links">
                        <?php if ($current_page > 1): ?>
                            <a href="<?= htmlspecialchars(get_pagination_url($current_page - 1)) ?>" class="btn btn-secondary">Previous</a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="<?= htmlspecialchars(get_pagination_url($i)) ?>" class="btn <?= $i === $current_page ? 'btn-primary' : 'btn-secondary' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?= htmlspecialchars(get_pagination_url($current_page + 1)) ?>" class="btn btn-secondary">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div></div><div class="modal-overlay" id="paymentModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Edit payment</h3>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>

        <form method="POST" id="paymentForm" class="modal-form">
            <input type="hidden" name="form_mode" id="formMode" value="edit">
            <input type="hidden" name="id_payment" id="paymentId" value="0">

            <div class="modal-row">
                <div class="form-group">
                    <label>Amount*</label>
                    <input type="number" step="0.01" min="0" name="amount" id="amount" required>
                </div>
                <div class="form-group">
                    <label>Payment date*</label>
                    <input type="date" name="payment_date" id="payment_date" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Method*</label>
                    <select name="payment_method" id="payment_method" required>
                        <option value="cash">cash</option>
                        <option value="card">card</option>
                        <option value="transfer">transfer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status*</label>
                    <select name="status" id="pay_status" required>
                        <option value="platita">platita</option>
                        <option value="neplatita">neplatita</option>
                        <option value="returnata">returnata</option>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Receipt number</label>
                    <input type="text" name="receipt_number" id="receipt_number">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="notes" rows="2"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="confirm-overlay" id="deleteConfirm">
    <div class="confirm-box">
        <h3>Delete payment</h3>
        <p>Are you sure you want to delete this payment? This action cannot be undone.</p>
        <div class="confirm-buttons">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, delete</button>
        </div>
    </div>
</div>

<script>
// ---------- MODAL ----------
const paymentModal  = document.getElementById('paymentModal');
const closeModalBtn = document.getElementById('closeModal');
const cancelModal   = document.getElementById('cancelModal');
const saveBtn       = document.getElementById('saveBtn');
const formModeInput = document.getElementById('formMode');
const paymentIdInput= document.getElementById('paymentId');

function openModal() {
    paymentModal.classList.add('show');
}
function closeModal() {
    paymentModal.classList.remove('show');
}

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
        formModeInput.value     = "edit";
        paymentIdInput.value    = btn.dataset.id;

        document.getElementById('amount').value     = btn.dataset.amount;
        document.getElementById('payment_date').value   = btn.dataset.date;
        document.getElementById('payment_method').value = btn.dataset.method;
        document.getElementById('pay_status').value     = btn.dataset.status;
        document.getElementById('receipt_number').value = btn.dataset.receipt || "";
        document.getElementById('notes').value          = btn.dataset.notes || "";

        saveBtn.textContent = "Update payment";
        openModal();
    });
});

// ---------- VALIDARE SIMPLĂ ----------
const paymentForm = document.getElementById('paymentForm');
paymentForm.addEventListener('submit', (e) => {
    const amount = document.getElementById('amount').value.trim();
    const date   = document.getElementById('payment_date').value.trim();

    if (amount === "" || isNaN(amount) || Number(amount) <= 0) {
        alert("Amount invalid. Trebuie să fie un număr pozitiv.");
        e.preventDefault();
        return;
    }

    if (!date) {
        alert("Te rog alege payment date.");
        e.preventDefault();
        return;
    }
});

// ---------- DELETE CONFIRM ----------
const deleteOverlay     = document.getElementById('deleteConfirm');
const cancelDeleteBtn   = document.getElementById('cancelDelete');
const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
let deleteId            = null;

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
        window.location.href = "payments.php?delete=" + deleteId;
    }
});
</script>

</body>
</html>