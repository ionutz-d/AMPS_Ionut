<?php
session_start();
require_once "db.php";

function require_login() {
    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }
}

function user_role() {
    return isset($_SESSION["role"]) ? $_SESSION["role"] : "";
}

function user_name() {
    return isset($_SESSION["username"]) ? $_SESSION["username"] : "";
}

function user_avatar() {
    if (isset($_SESSION["avatar"]) && $_SESSION["avatar"] != "") {
        return $_SESSION["avatar"];
    }
    return "assets/img/default_avatar.png";
}
?>
