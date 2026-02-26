<?php
require_once "db.php";
session_start();

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $role     = trim($_POST["role"]);
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    if ($role == "" || $username == "" || $password == "") {
        $error = "Completați toate câmpurile.";
    } else {

        $sql = "SELECT * FROM users WHERE username = ? AND role = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $username, $role);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);

            $ok = false;

            if (password_verify($password, $user["password_hash"])) {
                $ok = true;
            }

            if ($password === $user["password_hash"]) {
                $ok = true;
            }

            if ($ok) {
                $_SESSION["user_id"]  = $user["id_user"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["role"]     = $user["role"];
                $_SESSION["avatar"]   = $user["avatar"];

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Parola incorectă.";
            }

        } else {
            $error = "Utilizatorul nu există.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>AMC Login</title>
<link rel="stylesheet" href="assets/style.css">
</head>

<body class="login-body">

<div class="login-container">
    
 <div class="login-left">

    <h2>Welcome to</h2>
    <h1>Alpha Medical Center</h1>

    <div class="title-line"></div>

    <p class="subtitle">
        Premium medical care for every patient.  
        Experience, professionalism and comfort.
    </p>

</div>


    <div class="login-right">
        <img src="img/logo.png" class="login-logo">

        <h2 class="login-title">AMC</h2>
        <p class="login-subtitle">Alpha Medical System</p>

        <?php if ($error != ""): ?>
            <div class="error-box"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">

            <label>Rol utilizator</label>
            <select name="role" class="input">
                <option value="admin">Administrator</option>
                <option value="staff">Staff</option>
            </select>

            <label>Username</label>
            <input type="text" name="username" class="input">

            <label>Parola</label>
            <input type="password" name="password" class="input">

            <button class="btn-login">LOG IN</button>

        </form>

    </div>
</div>

</body>
</html>
