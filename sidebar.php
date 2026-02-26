<?php require_once "auth.php"; ?>

<aside class="sidebar">

    <!-- LOGO -->
    <div class="sidebar-logo">
        <img src="img/logo.png" alt="AMC">
        <h2>AMC Clinic</h2>
    </div>

    <ul class="sidebar-menu">

        <li>
            <a href="dashboard.php" class="<?= $active == 'dashboard' ? 'active' : '' ?>">
                Dashboard
            </a>
        </li>

        <li>
            <a href="doctors.php" class="<?= $active == 'doctors' ? 'active' : '' ?>">
                Doctors
            </a>
        </li>

        <li>
            <a href="nurses.php" class="<?= $active == 'nurses' ? 'active' : '' ?>">
                Nurses
            </a>
        </li>

        <li>
            <a href="patients.php" class="<?= $active == 'patients' ? 'active' : '' ?>">
                Patients
            </a>
        </li>

        <li>
            <a href="appointments.php" class="<?= $active == 'appointments' ? 'active' : '' ?>">
                Appointments
            </a>
        </li>

        <li>
            <a href="schedules.php" class="<?= $active == 'schedules' ? 'active' : '' ?>">
                Schedules
            </a>
        </li>

        <?php if (user_role() === "admin"): ?>

        <li>
            <a href="payments.php" class="<?= $active == 'payments' ? 'active' : '' ?>">
                Payments
            </a>
        </li>

        <li>
            <a href="reports.php" class="<?= $active == 'reports' ? 'active' : '' ?>">
                Reports
            </a>
        </li>

        <li>
            <a href="accounts.php" class="<?= $active == 'accounts' ? 'active' : '' ?>">
                Accounts
            </a>
        </li>

        <?php endif; ?>

        <li class="logout-wrap">
            <a href="logout.php" class="logout">Log Out</a>
        </li>

    </ul>

</aside>
