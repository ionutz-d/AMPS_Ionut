<?php
// reports.php
$page_title = "Reports";
$active     = "reports";

require_once "auth.php";
require_login();
require_once "db.php";

// ----------------- HELPERI GENERALE -----------------
function clean($v) {
    return trim($v ?? '');
}

function format_money($v) {
    return number_format((float)$v, 2, ',', '.');
}

/**
 * Returnează [start_date, end_date] în format Y-m-d
 * $type: today | this_week | this_month | all_time
 */
function get_date_range($type) {
    $today = new DateTime('today');

    switch ($type) {
        case 'today':
            $start = $today;
            $end   = clone $today;
            break;

        case 'this_week':
            $start = clone $today;
            $start->modify('monday this week');
            $end = clone $today;
            $end->modify('sunday this week');
            break;

        case 'this_month':
            $start = new DateTime($today->format('Y-m-01'));
            $end   = clone $start;
            $end->modify('last day of this month');
            break;

        case 'all_time':
        default:
            return [null, null];
    }

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

// ----------------- PRELUARE FILTRE DIN GET -----------------

// Appointments report
$allowed_a_periods = ['today', 'this_week', 'this_month', 'all_time'];
$a_period = $_GET['a_period'] ?? 'this_month';
if (!in_array($a_period, $allowed_a_periods, true)) {
    $a_period = 'this_month';
}
$a_doctor = isset($_GET['a_doctor']) ? (int)$_GET['a_doctor'] : 0;

// Financial report
$allowed_f_periods = ['today', 'this_month', 'all_time'];
$f_period = $_GET['f_period'] ?? 'this_month';
if (!in_array($f_period, $allowed_f_periods, true)) {
    $f_period = 'this_month';
}

$allowed_methods = ['all', 'cash', 'card', 'transfer'];
$f_method = $_GET['f_method'] ?? 'all';
if (!in_array($f_method, $allowed_methods, true)) {
    $f_method = 'all';
}

// ----------------- LISTA DOCTORI (pentru dropdown-uri) -----------------
$doctors = [];
$res_doc = mysqli_query(
    $conn,
    "SELECT id_doctor, first_name, last_name
     FROM doctors
     ORDER BY last_name, first_name"
);
if ($res_doc) {
    while ($row = mysqli_fetch_assoc($res_doc)) {
        $doctors[] = $row;
    }
}

// =======================================================
//                APPOINTMENTS REPORT
// =======================================================

list($a_start, $a_end) = get_date_range($a_period);

$ap_where_parts = [];
if ($a_start && $a_end) {
    $a_start_esc = mysqli_real_escape_string($conn, $a_start);
    $a_end_esc   = mysqli_real_escape_string($conn, $a_end);
    $ap_where_parts[] = "appointment_date BETWEEN '{$a_start_esc}' AND '{$a_end_esc}'";
}
if ($a_doctor > 0) {
    $ap_where_parts[] = "doctor_id = {$a_doctor}";
}
$ap_where = '';
if (!empty($ap_where_parts)) {
    $ap_where = 'WHERE ' . implode(' AND ', $ap_where_parts);
}

// -- total appointments + status breakdown (performed / canceled / no-show)
$appointments_total      = 0;
$appointments_canceled   = 0;
$appointments_performed  = 0;
$appointments_noshow     = 0;

$sql_ap_counts = "
    SELECT 
        COUNT(*) AS total,
        SUM(status = 'efectuata')   AS performed,
        SUM(status = 'anulata')     AS canceled,
        SUM(status = 'neprezentat') AS noshow
    FROM appointments
    {$ap_where}
";
$res = mysqli_query($conn, $sql_ap_counts);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $appointments_total     = (int)$row['total'];
    $appointments_performed = (int)$row['performed'];
    $appointments_canceled  = (int)$row['canceled'];
    $appointments_noshow    = (int)$row['noshow'];
}

// -- appointments by day
$appointments_by_day = [];
$sql_ap_by_day = "
    SELECT 
        appointment_date,
        COUNT(*) AS total,
        SUM(status = 'efectuata')   AS performed,
        SUM(status = 'anulata')     AS canceled,
        SUM(status = 'neprezentat') AS noshow
    FROM appointments
    {$ap_where}
    GROUP BY appointment_date
    ORDER BY appointment_date
";
$res = mysqli_query($conn, $sql_ap_by_day);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $appointments_by_day[] = $row;
    }
}

// -- top 3 doctors by number of appointments
$top_doctors_appointments = [];
$sql_top_docs = "
    SELECT 
        d.id_doctor,
        CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
        COUNT(*) AS total
    FROM appointments a
    JOIN doctors d ON d.id_doctor = a.doctor_id
    {$ap_where}
    GROUP BY a.doctor_id
    ORDER BY total DESC
    LIMIT 3
";
$res = mysqli_query($conn, $sql_top_docs);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $top_doctors_appointments[] = $row;
    }
}

// =======================================================
//                  FINANCIAL REPORT
// =======================================================

list($f_start, $f_end) = get_date_range($f_period);

// bază: doar plăți cu status "platita"
$pay_where_base_parts = ["status = 'platita'"];
if ($f_start && $f_end) {
    $f_start_esc = mysqli_real_escape_string($conn, $f_start);
    $f_end_esc   = mysqli_real_escape_string($conn, $f_end);
    $pay_where_base_parts[] = "payment_date BETWEEN '{$f_start_esc}' AND '{$f_end_esc}'";
}
$pay_where_methods_parts = $pay_where_base_parts;
if ($f_method !== 'all') {
    $method_esc = mysqli_real_escape_string($conn, $f_method);
    $pay_where_methods_parts[] = "payment_method = '{$method_esc}'";
}

$pay_where_base_sql    = 'WHERE ' . implode(' AND ', $pay_where_base_parts);
$pay_where_methods_sql = 'WHERE ' . implode(' AND ', $pay_where_methods_parts);

// -- total revenue (în funcție de method filter)
$total_revenue = 0.0;
$sql_total_rev = "
    SELECT COALESCE(SUM(amount), 0) AS total_paid
    FROM payments
    {$pay_where_methods_sql}
";
$res = mysqli_query($conn, $sql_total_rev);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $total_revenue = (float)$row['total_paid'];
}

// -- breakdown by payment method (cash / card / transfer) pentru perioada selectată (indiferent de filtrul actual)
$cash_total = $card_total = $transfer_total = 0.0;

$sql_breakdown = "
    SELECT
        SUM(CASE WHEN payment_method = 'cash'     THEN amount ELSE 0 END) AS cash_total,
        SUM(CASE WHEN payment_method = 'card'     THEN amount ELSE 0 END) AS card_total,
        SUM(CASE WHEN payment_method = 'transfer' THEN amount ELSE 0 END) AS transfer_total
    FROM payments
    {$pay_where_base_sql}
";
$res = mysqli_query($conn, $sql_breakdown);
if ($res && $row = mysqli_fetch_assoc($res)) {
    $cash_total     = (float)$row['cash_total'];
    $card_total     = (float)$row['card_total'];
    $transfer_total = (float)$row['transfer_total'];
}

// -- revenue by day (respectă și filtrul de metodă)
$revenue_by_day = [];
$sql_rev_by_day = "
    SELECT 
        payment_date,
        SUM(amount) AS total_paid,
        SUM(CASE WHEN payment_method = 'cash'     THEN amount ELSE 0 END) AS cash_total,
        SUM(CASE WHEN payment_method = 'card'     THEN amount ELSE 0 END) AS card_total,
        SUM(CASE WHEN payment_method = 'transfer' THEN amount ELSE 0 END) AS transfer_total
    FROM payments
    {$pay_where_methods_sql}
    GROUP BY payment_date
    ORDER BY payment_date
";
$res = mysqli_query($conn, $sql_rev_by_day);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $revenue_by_day[] = $row;
    }
}

// -- top 3 doctors by revenue
$top_doctors_revenue = [];
$sql_top_rev = "
    SELECT 
        d.id_doctor,
        CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
        COUNT(p.id_payment) AS appointments_count,
        SUM(p.amount) AS revenue
    FROM payments p
    LEFT JOIN appointments a ON a.id_appointment = p.appointment_id
    LEFT JOIN doctors d ON d.id_doctor = a.doctor_id
    {$pay_where_methods_sql}
    GROUP BY d.id_doctor
    HAVING revenue IS NOT NULL
    ORDER BY revenue DESC
    LIMIT 3
";
$res = mysqli_query($conn, $sql_top_rev);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $top_doctors_revenue[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Reports - AMC Clinic</title>
    <link rel="stylesheet" href="assets/style.css">

    <!-- Culori speciale pentru KPI-uri din Reports -->
    <style>
        .reports-page .kpi-blue {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: #fff;
        }
        .reports-page .kpi-orange {
            background: linear-gradient(135deg, #f7971e, #ff512f);
            color: #fff;
        }
        .reports-page .kpi-purple {
            background: linear-gradient(135deg, #8e2de2, #4a00e0);
            color: #fff;
        }
        .reports-page .kpi-green {
            background: linear-gradient(135deg, #00b09b, #96c93d);
            color: #fff;
        }

        .reports-page .report-section {
            margin-bottom: 40px;
        }

        .reports-page .report-header {
            margin-bottom: 15px;
        }

        .reports-page .report-header h2 {
            font-size: 24px;
            margin-bottom: 4px;
        }

        .reports-page .report-header .subtitle {
            color: #7a7a7a;
            font-size: 14px;
        }

        .reports-page .report-filters {
            display: flex;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .reports-page .report-filters .form-group {
            min-width: 220px;
        }

        .reports-page .report-bottom-row {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .reports-page .report-bottom-row .card {
            flex: 1;
        }

        .reports-page .report-bottom-row .card.wide {
            flex: 2;
        }

        .reports-page table td,
        .reports-page table th {
            white-space: nowrap;
        }

        .reports-page .no-data {
            text-align: center;
            padding: 20px 0;
            color: #888;
        }

        .card .card-overview .kpi-blue{
                background: linear-gradient(135deg,#7647ff,#9d6bff);
        }
    </style>
</head>
<body>

<?php include "sidebar.php"; ?>

<div class="main-content">
    <?php include "header.php"; ?>

    <div class="content-box reports-page">

        <!-- ========================================= -->
        <!--          APPOINTMENTS REPORT             -->
        <!-- ========================================= -->
        <section class="report-section">
            <div class="report-header">
                <h2>Appointments report</h2>
                <p class="subtitle">Statistică a programărilor pe perioadă și pe doctor.</p>
            </div>

            <form method="get" class="report-filters">
                <div class="form-group">
                    <label for="a_period">Period</label>
                    <select name="a_period" id="a_period">
                        <option value="today"      <?= $a_period === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="this_week"  <?= $a_period === 'this_week' ? 'selected' : '' ?>>This week</option>
                        <option value="this_month" <?= $a_period === 'this_month' ? 'selected' : '' ?>>This month</option>
                        <option value="all_time"   <?= $a_period === 'all_time' ? 'selected' : '' ?>>All time</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="a_doctor">Doctor</label>
                    <select name="a_doctor" id="a_doctor">
                        <option value="0" <?= $a_doctor === 0 ? 'selected' : '' ?>>All doctors</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?= (int)$doc['id_doctor'] ?>"
                                <?= $a_doctor === (int)$doc['id_doctor'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($doc['last_name'] . ' ' . $doc['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-apply">Apply</button>
            </form>

            <!-- KPI CARDS -->
            <div class="cards-row report-cards-row">
                <div class="card card-overview kpi-blue">
                    <h3>Total appointments</h3>
                    <div class="overview-number"><?= $appointments_total ?></div>
                    <p class="overview-text">
                        În perioada și cu doctorul selectat.
                    </p>
                </div>

                <div class="card card-overview kpi-orange">
                    <h3>Canceled</h3>
                    <div class="overview-number"><?= $appointments_canceled ?></div>
                    <p class="overview-text">
                        Programări cu status „anulata”.
                    </p>
                </div>
            </div>

            <!-- TABLES: APPOINTMENTS BY DAY + TOP 3 DOCTORS -->
            <div class="report-bottom-row">

                <div class="card wide">
                    <h3>Appointments by day</h3>
                    <div class="table-wrapper">
                        <table class="doctors-table">
                            <thead>
                                <tr>
                                    <th>DATE</th>
                                    <th>TOTAL</th>
                                    <th>PERFORMED</th>
                                    <th>CANCELED</th>
                                    <th>NO-SHOW</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($appointments_by_day)): ?>
                                <tr>
                                    <td colspan="5" class="no-data">No appointments for selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($appointments_by_day as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['appointment_date']) ?></td>
                                        <td><?= (int)$row['total'] ?></td>
                                        <td><?= (int)$row['performed'] ?></td>
                                        <td><?= (int)$row['canceled'] ?></td>
                                        <td><?= (int)$row['noshow'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Top 3 doctors by appointments</h3>
                    <div class="table-wrapper">
                        <table class="doctors-table">
                            <thead>
                                <tr>
                                    <th>DOCTOR</th>
                                    <th>APPOINTMENTS</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($top_doctors_appointments)): ?>
                                <tr>
                                    <td colspan="2" class="no-data">No data for selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($top_doctors_appointments as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                                        <td><?= (int)$row['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </section>

        <!-- ========================================= -->
        <!--             FINANCIAL REPORT             -->
        <!-- ========================================= -->
        <section class="report-section">
            <div class="report-header">
                <h2>Financial report</h2>
                <p class="subtitle">Venituri în funcție de perioadă și metodă de plată.</p>
            </div>

            <form method="get" class="report-filters">
                <div class="form-group">
                    <label for="f_period">Period</label>
                    <select name="f_period" id="f_period">
                        <option value="today"      <?= $f_period === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="this_month" <?= $f_period === 'this_month' ? 'selected' : '' ?>>This month</option>
                        <option value="all_time"   <?= $f_period === 'all_time' ? 'selected' : '' ?>>All time</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="f_method">Payment method</label>
                    <select name="f_method" id="f_method">
                        <option value="all"      <?= $f_method === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="cash"     <?= $f_method === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="card"     <?= $f_method === 'card' ? 'selected' : '' ?>>Card</option>
                        <option value="transfer" <?= $f_method === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-apply">Apply</button>
            </form>

            <!-- KPI CARDS -->
            <div class="cards-row report-cards-row">
                <div class="card card-overview kpi-purple">
                    <h3>Total revenue (paid)</h3>
                    <div class="overview-number">
                        <?= format_money($total_revenue) ?>
                    </div>
                    <p class="overview-text">
                        Suma plăților cu status „platita” în perioada selectată.
                    </p>
                </div>

                <div class="card card-overview kpi-green">
                    <h3>By payment method</h3>
                    <div class="overview-number" style="font-size:18px; line-height:1.4;">
                        <?php if ($cash_total == 0 && $card_total == 0 && $transfer_total == 0): ?>
                            No paid payments in period.
                        <?php else: ?>
                            <?php if ($f_method === 'all'): ?>
                                Cash: <strong><?= format_money($cash_total) ?></strong><br>
                                Card: <strong><?= format_money($card_total) ?></strong><br>
                                Transfer: <strong><?= format_money($transfer_total) ?></strong>
                            <?php else: ?>
                                Total via <?= htmlspecialchars($f_method) ?>:
                                <strong><?= format_money($total_revenue) ?></strong>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <p class="overview-text">
                        Analiza veniturilor după metoda de plată.
                    </p>
                </div>
            </div>

            <!-- TABLES: REVENUE BY DAY + TOP 3 DOCTORS BY REVENUE -->
            <div class="report-bottom-row">

                <div class="card wide">
                    <h3>Revenue by day</h3>
                    <div class="table-wrapper">
                        <table class="doctors-table">
                            <thead>
                                <tr>
                                    <th>DATE</th>
                                    <th>TOTAL PAID</th>
                                    <th>CASH</th>
                                    <th>CARD</th>
                                    <th>TRANSFER</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($revenue_by_day)): ?>
                                <tr>
                                    <td colspan="5" class="no-data">No payments for selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($revenue_by_day as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['payment_date']) ?></td>
                                        <td><?= format_money($row['total_paid']) ?></td>
                                        <td><?= format_money($row['cash_total']) ?></td>
                                        <td><?= format_money($row['card_total']) ?></td>
                                        <td><?= format_money($row['transfer_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

      

            </div>
        </section>

    </div><!-- /content-box -->
</div><!-- /main-content -->

</body>
</html>
