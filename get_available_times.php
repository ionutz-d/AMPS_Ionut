<?php

// get_available_times.php
require_once 'db.php';  // Asigură-te că ai conexiunea la DB inclusă

$doctor_id = (int)$_GET['doctor_id'];  // ID-ul doctorului selectat
$date = $_GET['date'];  // Data selectată

$day_of_week = date('l', strtotime($date));  // Transformăm data într-o zi a săptămânii

// Obținem programul doctorului pentru ziua respectivă
$query = "SELECT start_time, end_time FROM schedules WHERE doctor_id = ? AND day_of_week = ? AND is_active = 'active'";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "is", $doctor_id, $day_of_week);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$available_times = [];

while ($row = mysqli_fetch_assoc($result)) {
    $start_time = $row['start_time'];
    $end_time = $row['end_time'];

    // Verificăm dacă intervalul de timp nu se suprapune cu o programare existentă
    $appointment_check_query = "
        SELECT * 
        FROM appointments 
        WHERE doctor_id = ? 
        AND appointment_date = ? 
        AND appointment_time BETWEEN ? AND ?
    ";
    $stmt_check = mysqli_prepare($conn, $appointment_check_query);
    mysqli_stmt_bind_param($stmt_check, "isss", $doctor_id, $date, $start_time, $end_time);
    mysqli_stmt_execute($stmt_check);
    $check_result = mysqli_stmt_get_result($stmt_check);

    if (mysqli_num_rows($check_result) === 0) {  // Dacă nu există programare în acel interval
        $available_times[] = [
            'start_time' => $start_time,
            'end_time' => $end_time
        ];
    }
}

echo json_encode($available_times);  // Răspundem cu orele disponibile în format JSON



?>
