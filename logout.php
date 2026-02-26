<?php
session_start();

// curăț toate variabilele din sesiune
$_SESSION = array();

// distrug sesiunea
session_destroy();

// redirecționez către login
header("Location: login.php");
exit;
?>
