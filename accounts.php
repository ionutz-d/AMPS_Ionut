<?php
// accounts.php
$page_title = "Accounts";
$active     = "accounts";

require_once "auth.php";
require_login();
require_once "db.php";

$errors  = [];
$success = [];

// ---------- HELPERI ----------
function clean($v) {
    return trim($v ?? '');
}

function is_valid_email_custom($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    return (strpos($email, '@') !== false && strpos($email, '.') !== false);
}

function is_valid_role($role) {
    return in_array($role, ['admin', 'staff'], true);
}

function is_valid_status($status) {
    return in_array($status, ['active', 'inactive'], true);
}

// ---------- ADD / EDIT ACCOUNT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mode      = $_POST['form_mode'] ?? 'add';
    $id_user   = (int)($_POST['id_user'] ?? 0);

    $username  = clean($_POST['username'] ?? '');
    $email     = clean($_POST['email'] ?? '');
    $role      = clean($_POST['role'] ?? '');
    $status    = clean($_POST['status'] ?? 'active');

    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    // --- VALIDĂRI ---
    if ($username === '' || strlen($username) < 3) {
        $errors[] = "Username trebuie să aibă minim 3 caractere.";
    }

    if ($email === '' || !is_valid_email_custom($email)) {
        $errors[] = "Email invalid. Trebuie să conțină @ și .";
    }

    if (!is_valid_role($role)) {
        $errors[] = "Rol invalid.";
    }

    if (!is_valid_status($status)) {
        $errors[] = "Status invalid.";
    }

    if ($mode === 'add') {
        if ($password === '' || strlen($password) < 6) {
            $errors[] = "Parola trebuie să aibă minim 6 caractere.";
        }
        if ($password !== $confirm) {
            $errors[] = "Parola și Confirm password nu coincid.";
        }
    } else { // edit
        if ($password !== '' || $confirm !== '') {
            if (strlen($password) < 6) {
                $errors[] = "Parola nouă trebuie să aibă minim 6 caractere.";
            }
            if ($password !== $confirm) {
                $errors[] = "Parola nouă și Confirm password nu coincid.";
            }
        }
    }

    // verificăm unicitatea username/email
    if (empty($errors)) {
        if ($mode === 'add') {
            $sql_check = "SELECT id_user FROM users WHERE username = ? OR email = ? LIMIT 1";
            $stmt_chk  = mysqli_prepare($conn, $sql_check);
            if ($stmt_chk) {
                mysqli_stmt_bind_param($stmt_chk, "ss", $username, $email);
                mysqli_stmt_execute($stmt_chk);
                mysqli_stmt_store_result($stmt_chk);
                if (mysqli_stmt_num_rows($stmt_chk) > 0) {
                    $errors[] = "Există deja un cont cu acest username sau email.";
                }
                mysqli_stmt_close($stmt_chk);
            }
        } else {
            $sql_check = "SELECT id_user FROM users WHERE (username = ? OR email = ?) AND id_user <> ? LIMIT 1";
            $stmt_chk  = mysqli_prepare($conn, $sql_check);
            if ($stmt_chk) {
                mysqli_stmt_bind_param($stmt_chk, "ssi", $username, $email, $id_user);
                mysqli_stmt_execute($stmt_chk);
                mysqli_stmt_store_result($stmt_chk);
                if (mysqli_stmt_num_rows($stmt_chk) > 0) {
                    $errors[] = "Există deja un alt cont cu acest username sau email.";
                }
                mysqli_stmt_close($stmt_chk);
            }
        }
    }

    if (empty($errors)) {
        if ($mode === 'add') {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "INSERT INTO users (username, password_hash, email, role, status, created_at)
                    VALUES (?,?,?,?,?, NOW())";

            $stmt = mysqli_prepare($conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "sssss",
                    $username,
                    $password_hash,
                    $email,
                    $role,
                    $status
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $success[] = "Account created successfully.";
            }

        } else { // edit
            if ($id_user > 0) {

                // dacă avem parolă nouă
                if ($password !== '') {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET
                                username = ?,
                                password_hash = ?,
                                email = ?,
                                role = ?,
                                status = ? 
                            WHERE id_user = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param(
                            $stmt,
                            "sssssi",
                            $username,
                            $password_hash,
                            $email,
                            $role,
                            $status,
                            $id_user
                        );
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        $success[] = "Account updated successfully (password changed).";
                    }

                } else {
                    $sql = "UPDATE users SET
                                username = ?,
                                email    = ?,
                                role     = ?,
                                status   = ? 
                            WHERE id_user = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param(
                            $stmt,
                            "ssssi",
                            $username,
                            $email,
                            $role,
                            $status,
                            $id_user
                        );
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        $success[] = "Account updated successfully.";
                    }
                }
            }
        }

        if (!empty($success)) {
            header("Location: accounts.php?msg=" . urlencode(implode(" ", $success)));
            exit;
        }
    }
}

// ---------- DELETE ----------
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        // prevenim ștergerea contului curent
        if ($id === (int)$_SESSION['user_id']) {
            $errors[] = "Nu poți șterge contul cu care ești logat.";
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id_user = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            header("Location: accounts.php?msg=" . urlencode("Account deleted successfully."));
            exit;
        }
    }
}

// ---------- STATS ----------
$res_admins = mysqli_query($conn, "SELECT COUNT(*) AS total_admins FROM users WHERE role='admin'");
$row_admins = mysqli_fetch_assoc($res_admins);
$total_admins = (int)$row_admins['total_admins'];

$res_staff = mysqli_query($conn, "SELECT COUNT(*) AS total_staff FROM users WHERE role='staff'");
$row_staff = mysqli_fetch_assoc($res_staff);
$total_staff = (int)$row_staff['total_staff'];

// ---------- SEARCH + FILTER ----------
$search      = clean($_GET['q'] ?? '');
$role_filter = $_GET['role_filter'] ?? 'all';

$sql_list = "SELECT * FROM users WHERE 1";
$params   = [];
$types    = "";

if ($search !== "") {
    $sql_list .= " AND (username LIKE ? OR email LIKE ?)";
    $like   = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $types   .= "ss";
}

if (in_array($role_filter, ['admin','staff'], true)) {
    $sql_list .= " AND role = ?";
    $params[] = $role_filter;
    $types   .= "s";
}

$sql_list .= " ORDER BY created_at DESC";

// --------- PAGINARE ---------
$per_page = 5; // 5 conturi pe pagină
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

$sql_list .= " LIMIT $per_page OFFSET $offset";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql_list);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql_list);
}

$accounts = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $accounts[] = $row;
    }
    if (isset($stmt)) mysqli_stmt_close($stmt);
}

// mesaj succes din redirect
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $success[] = $_GET['msg'];
}

// ---------- COUNT total records for pagination ----------
$sql_count = "SELECT COUNT(*) AS total_filtered FROM users WHERE 1";
$res_count = mysqli_query($conn, $sql_count);
$row_count = mysqli_fetch_assoc($res_count);
$total_filtered = (int)$row_count['total_filtered'];
$total_pages    = max(1, (int)ceil($total_filtered / $per_page));

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Accounts - AMC Clinic</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        /* ======================================================= */
        /*            ACCOUNTS TABLE – FIXED COLUMN WIDTHS         */
        /* ======================================================= */

        .accounts-page table.doctors-table {
            table-layout: fixed !important;
            width: 100% !important;
        }

        /* 2: USERNAME */
        .accounts-page table.doctors-table th:nth-child(1),
        .accounts-page table.doctors-table td:nth-child(1) {
            width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 3: EMAIL */
        .accounts-page table.doctors-table th:nth-child(2),
        .accounts-page table.doctors-table td:nth-child(2) {
            width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 4: ROLE */
        .accounts-page table.doctors-table th:nth-child(3),
        .accounts-page table.doctors-table td:nth-child(3) {
            width: 90px;
            text-align: center;
            white-space: nowrap;
        }

        /* 5: STATUS */
        .accounts-page table.doctors-table th:nth-child(4),
        .accounts-page table.doctors-table td:nth-child(4) {
            width: 110px;
            text-align: center;
            white-space: nowrap;
        }

        /* 6: CREATED AT */
        .accounts-page table.doctors-table th:nth-child(5),
        .accounts-page table.doctors-table td:nth-child(5) {
            width: 150px;
            text-align: center;
            white-space: nowrap;
        }

        /* 7: LAST LOGIN */
        .accounts-page table.doctors-table th:nth-child(6),
        .accounts-page table.doctors-table td:nth-child(6) {
            width: 150px;
            text-align: center;
            white-space: nowrap;
        }

        /* 8: ACTIONS */
        .accounts-page table.doctors-table th:nth-child(7),
        .accounts-page table.doctors-table td:nth-child(7) {
            width: 120px;
            text-align: center;
            white-space: nowrap;
        }

        .accounts-page .card-admin {
            background: linear-gradient(135deg, #1565c0, #42a5f5);
            color: #fff;
        }
        .accounts-page .card-staff {
            background: linear-gradient(135deg, #00897b, #26a69a);
            color: #fff;
        }
        .accounts-page .card-admin .overview-number,
        .accounts-page .card-staff .overview-number {
            color: #fff;
        }

        
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

    </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box accounts-page">

        <div class="doctors-header">
            <h2>Accounts</h2>
            <p class="subtitle">Manage user accounts for the AMC Clinic.</p>
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

        <!-- OVERVIEW + SEARCH/FILTER -->
        <div class="cards-row">

            <div class="card card-overview card-admin">
                <h3>Total admins</h3>
                <div class="overview-number"><?= $total_admins ?></div>
                <p class="overview-text">Administrator accounts</p>
            </div>

            <div class="card card-overview card-staff">
                <h3>Total staff</h3>
                <div class="overview-number"><?= $total_staff ?></div>
                <p class="overview-text">Staff accounts</p>
            </div>

            <div class="card card-search">
                <h3>Search &amp; filter</h3>

                <form method="GET" class="search-form">
                    <div class="form-group">
                        <label for="q">Search by username or email</label>
                        <input type="text"
                               id="q"
                               name="q"
                               placeholder="Ex: admin, maria.staff@clinica.test"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div class="form-group">
                        <label for="role_filter">Filter by role</label>
                        <select name="role_filter" id="role_filter">
                            <option value="all"   <?= $role_filter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admins only</option>
                            <option value="staff" <?= $role_filter === 'staff' ? 'selected' : '' ?>>Staff only</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-apply">Apply</button>
                </form>
            </div>

        </div>

        <!-- ACCOUNTS LIST -->
        <div class="card doctors-list-card">
            <div class="card-header-row">
                <h3>Users list</h3>
                <button type="button" class="btn btn-add" id="btnAddAccount">+ Add account</button>
            </div>

            <div class="table-wrapper">
                <table class="doctors-table">
                    <thead>
                        <tr>

                            <th>USERNAME</th>
                            <th>EMAIL</th>
                            <th>ROLE</th>
                            <th>STATUS</th>
                            <th>CREATED AT</th>
                            <th>LAST LOGIN</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="8" class="no-data">No accounts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($accounts as $u): ?>
                            <tr>

                                <td><?= htmlspecialchars($u['username']) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><?= htmlspecialchars($u['role']) ?></td>
                                <td><?= htmlspecialchars($u['status']) ?></td>
                                <td><?= htmlspecialchars($u['created_at']) ?></td>
                                <td><?= htmlspecialchars($u['last_login']) ?></td>
                                <td class="actions">
                                    <button
                                        type="button"
                                        class="link-btn edit-btn"
                                        data-id="<?= (int)$u['id_user'] ?>"
                                        data-username="<?= htmlspecialchars($u['username']) ?>"
                                        data-email="<?= htmlspecialchars($u['email']) ?>"
                                        data-role="<?= htmlspecialchars($u['role']) ?>"
                                        data-status="<?= htmlspecialchars($u['status']) ?>"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="link-btn delete-btn"
                                        data-id="<?= (int)$u['id_user'] ?>"
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

            <div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&role_filter=<?= urlencode($role_filter) ?>">« Prev</a>
    <?php endif; ?>

    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&role_filter=<?= urlencode($role_filter) ?>" 
           class="<?= $i == $page ? 'active' : '' ?>">
            <?= $i ?>
        </a>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
        <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&role_filter=<?= urlencode($role_filter) ?>">Next »</a>
    <?php endif; ?>
</div>

        </div>

    </div><!-- /content-box -->
</div><!-- /main-content -->

<!-- MODAL ADD / EDIT ACCOUNT -->
<div class="modal-overlay" id="accountModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Add account</h3>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>

        <form method="POST" id="accountForm" class="modal-form">
            <input type="hidden" name="form_mode" id="formMode" value="add">
            <input type="hidden" name="id_user" id="userId" value="0">

            <div class="modal-row">
                <div class="form-group">
                    <label>Username*</label>
                    <input type="text" name="username" id="username" required>
                </div>
                <div class="form-group">
                    <label>Email*</label>
                    <input type="email" name="email" id="email" required>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label>Role*</label>
                    <select name="role" id="role" required>
                        <option value="">Select role...</option>
                        <option value="admin">admin</option>
                        <option value="staff">staff</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status*</label>
                    <select name="status" id="status" required>
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
                    </select>
                </div>
            </div>

            <div class="modal-row">
                <div class="form-group">
                    <label id="passwordLabel">Password*</label>
                    <input type="password" name="password" id="password">
                </div>
                <div class="form-group">
                    <label id="confirmLabel">Confirm password*</label>
                    <input type="password" name="confirm_password" id="confirm_password">
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
        <h3>Delete account</h3>
        <p>Are you sure you want to delete this account? This action cannot be undone.</p>
        <div class="confirm-buttons">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, delete</button>
        </div>
    </div>
</div>

<script>
// ---------- MODAL HANDLING ----------
const accountModal = document.getElementById('accountModal');
const btnAdd = document.getElementById('btnAddAccount');
const closeModalBtn = document.getElementById('closeModal');
const cancelModal = document.getElementById('cancelModal');
const modalTitle = document.getElementById('modalTitle');
const formModeInput = document.getElementById('formMode');
const userIdInput = document.getElementById('userId');
const saveBtn = document.getElementById('saveBtn');

const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm_password');
const passwordLabel = document.getElementById('passwordLabel');
const confirmLabel = document.getElementById('confirmLabel');

// Deschide modalul
function openModal() {
    accountModal.classList.add('show');
}

// Închide modalul
function closeModal() {
    accountModal.classList.remove('show');
}

// Deschide formularul pentru adăugare
btnAdd.addEventListener('click', () => {
    modalTitle.textContent = "Add account"; // Titlu pentru Add
    formModeInput.value = "add"; // Setează formularul pe modul de adăugare
    userIdInput.value = "0"; // Setează ID-ul la 0 pentru adăugare nouă

    // Golește câmpurile formularului
    document.getElementById('username').value = "";
    document.getElementById('email').value = "";
    document.getElementById('role').value = "";
    document.getElementById('status').value = "active"; // Setează statusul la "active"

    passwordInput.value = "";
    confirmInput.value = "";

    // Activează câmpurile de parolă pentru adăugare
    passwordInput.required = true;
    confirmInput.required = true;
    passwordLabel.textContent = "Password*";
    confirmLabel.textContent = "Confirm password*";

    saveBtn.textContent = "Create account"; // Text pentru butonul de salvare
    openModal(); // Deschide modalul
});

// Închide modalul
closeModalBtn.addEventListener('click', closeModal);
cancelModal.addEventListener('click', closeModal);

window.addEventListener('keydown', (e) => {
    if (e.key === "Escape") {
        closeModal();
        closeDeletePopup();
    }
});

// ---------- EDIT BUTTONS ----------
// Evenimentul pentru a edita contul
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        modalTitle.textContent = "Edit account"; // Titlu pentru Edit
        formModeInput.value = "edit"; // Setează formularul pe modul de editare
        userIdInput.value = btn.dataset.id; // Setează ID-ul utilizatorului pentru editare

        // Populează câmpurile formularului cu datele existente
        document.getElementById('username').value = btn.dataset.username;
        document.getElementById('email').value = btn.dataset.email;
        document.getElementById('role').value = btn.dataset.role;
        document.getElementById('status').value = btn.dataset.status;

        passwordInput.value = "";
        confirmInput.value = "";

        // Dezactivează câmpurile de parolă pentru editare
        passwordInput.required = false;
        confirmInput.required = false;
        passwordLabel.textContent = "New password (optional)";
        confirmLabel.textContent = "Confirm new password";

        saveBtn.textContent = "Update account"; // Text pentru butonul de salvare
        openModal(); // Deschide modalul
    });
});

// ---------- VALIDARE FRONT-END ----------
const accountForm = document.getElementById('accountForm');
accountForm.addEventListener('submit', (e) => {
    const username = document.getElementById('username').value.trim();
    const email = document.getElementById('email').value.trim();
    const role = document.getElementById('role').value;
    const status = document.getElementById('status').value;
    const pass = document.getElementById('password').value;
    const conf = document.getElementById('confirm_password').value;
    const mode = formModeInput.value;

    // Validare username (să înceapă cu literă mare)
    if (username === "" || !/^[A-Z]/.test(username)) {
        alert("Username trebuie să înceapă cu literă mare.");
        e.preventDefault();
        return;
    }

    // Validare email (să conțină un "@" și un ".")
    if (email === "" || !email.includes("@") || !email.includes(".")) {
        alert("Email invalid. Trebuie să conțină @ și .");
        e.preventDefault();
        return;
    }

    // Validare rol (trebuie să fie selectat)
    if (!role) {
        alert("Te rog selectează rolul (admin/staff).");
        e.preventDefault();
        return;
    }

    // Validare status (trebuie să fie selectat)
    if (!status) {
        alert("Te rog selectează statusul.");
        e.preventDefault();
        return;
    }

    // Validare parolă (minim 8 caractere, o literă mare, o cifră și un caracter special)
    const passwordRegex = /^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>])[A-Za-z\d!@#$%^&*(),.?":{}|<>]{8,}$/;
    if (pass === "" || pass.length < 8 || !passwordRegex.test(pass)) {
        alert("Parola trebuie să aibă minim 8 caractere, să conțină o literă mare, o cifră și un caracter special.");
        e.preventDefault();
        return;
    }

    // Validare confirmare parolă (trebuie să coincidă cu parola)
    if (pass !== conf) {
        alert("Parola și Confirm password nu coincid.");
        e.preventDefault();
        return;
    }
    
    // Dacă este în modul 'edit' și nu există o parolă nouă, oprim validarea aici
    if (mode === "edit" && pass === "" && conf === "") {
        return; // Nu validăm parola dacă nu a fost schimbată
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
        window.location.href = "accounts.php?delete=" + deleteId;
    }
});
</script>

</body>
</html>
