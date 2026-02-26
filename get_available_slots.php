<?php
// get_available_slots.php
// Script AJAX pentru a returna sloturile orare disponibile pentru un doctor pe o anumită dată.

require_once "db.php"; // Asigură-te că include fișierul de conexiune la baza de date

header('Content-Type: application/json');

// Funcție pentru a genera o listă de ore la un anumit interval (e.g., din 30 în 30 de minute)
function generate_time_slots($start_time, $end_time, $interval_minutes = 30) {
    $slots = [];
    $current_time = strtotime("1970-01-01 $start_time");
    $end_timestamp = strtotime("1970-01-01 $end_time");

    while ($current_time < $end_timestamp) {
        $slots[] = date("H:i", $current_time);
        $current_time = strtotime("+$interval_minutes minutes", $current_time);
    }
    return $slots;
}

$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$selected_date = $_GET['date'] ?? '';
$duration_minutes = (int)($_GET['duration_minutes'] ?? 30);
$current_appointment_id = (int)($_GET['current_appointment_id'] ?? 0); 

if ($doctor_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) || $duration_minutes <= 0) {
    // Returnează un răspuns gol sau o eroare JSON
    echo json_encode(['available_times' => [], 'error' => 'Parametri invalizi.']);
    exit;
}

// 1. Găsește programul doctorului pentru ziua respectivă
$day_of_week = date('l', strtotime($selected_date));
// Maparea zilei din engleză în română (conform tabelei schedules)
$day_map = [
    'Monday' => 'Luni', 'Tuesday' => 'Marti', 'Wednesday' => 'Miercuri', 
    'Thursday' => 'Joi', 'Friday' => 'Vineri', 'Saturday' => 'Sambata', 'Sunday' => 'Duminica'
];
$db_day = $day_map[$day_of_week] ?? '';

$schedule_query = "
    SELECT start_time, end_time 
    FROM schedules 
    WHERE doctor_id = ? AND day_of_week = ? AND is_active = 'active'
    LIMIT 1
";
$stmt_schedule = mysqli_prepare($conn, $schedule_query);
mysqli_stmt_bind_param($stmt_schedule, "is", $doctor_id, $db_day);
mysqli_stmt_execute($stmt_schedule);
$schedule_result = mysqli_stmt_get_result($stmt_schedule);
$schedule = mysqli_fetch_assoc($schedule_result);
mysqli_stmt_close($stmt_schedule);

if (!$schedule) {
    // Doctorul nu lucrează în această zi
    echo json_encode(['available_times' => [], 'working_hours' => null]);
    exit;
}

$start_time = substr($schedule['start_time'], 0, 5); // HH:MM
$end_time = substr($schedule['end_time'], 0, 5);   // HH:MM
$working_hours = "$start_time - $end_time";

// 2. Generează toate sloturile posibile bazate pe program și durata programării
$possible_slots = generate_time_slots($start_time, $end_time, $duration_minutes);

// 3. Găsește programările deja existente pentru doctor în ziua respectivă
$booked_query = "
    SELECT appointment_time, duration_minutes 
    FROM appointments 
    WHERE doctor_id = ? AND appointment_date = ? 
    AND status NOT IN ('anulata', 'neprezentat')
";
// La editare, excludem programarea curentă din verificare
if ($current_appointment_id > 0) {
    $booked_query .= " AND id_appointment != $current_appointment_id";
}

$stmt_booked = mysqli_prepare($conn, $booked_query);
mysqli_stmt_bind_param($stmt_booked, "is", $doctor_id, $selected_date);
mysqli_stmt_execute($stmt_booked);
$booked_result = mysqli_stmt_get_result($stmt_booked);
$booked_slots_info = mysqli_fetch_all($booked_result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt_booked);

$booked_intervals = [];
foreach ($booked_slots_info as $booked) {
    $booked_start = strtotime("1970-01-01 " . $booked['appointment_time']);
    $booked_end = strtotime("+" . (int)$booked['duration_minutes'] . " minutes", $booked_start);
    $booked_intervals[] = ['start' => $booked_start, 'end' => $booked_end];
}

// 4. Filtrează sloturile posibile (elimină suprapunerile și cele care depășesc programul)
$available_slots = [];
foreach ($possible_slots as $slot_start_str) {
    $slot_start = strtotime("1970-01-01 $slot_start_str");
    $slot_end = strtotime("+$duration_minutes minutes", $slot_start);

    // Verifică dacă slotul depășește ora de terminare a doctorului
    $work_end = strtotime("1970-01-01 $end_time");
    if ($slot_end > $work_end) {
        continue;
    }

    $is_booked = false;
    foreach ($booked_intervals as $interval) {
        // Suprapunerea: (A_start < B_end) AND (A_end > B_start)
        if ($slot_start < $interval['end'] && $slot_end > $interval['start']) {
            $is_booked = true;
            break;
        }
    }

    if (!$is_booked) {
        $available_slots[] = $slot_start_str;
    }
}

echo json_encode([
    'available_times' => $available_slots,
    'working_hours' => $working_hours
]);
?>