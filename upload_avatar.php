<?php
require_once "auth.php";
require_login();

$user_id = $_SESSION["user_id"];

// CREAZĂ FOLDERUL dacă nu există
if (!file_exists("uploads")) {
    mkdir("uploads", 0777, true);
}

if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] === 0) {

    $ext = strtolower(pathinfo($_FILES["avatar"]["name"], PATHINFO_EXTENSION));
    $allow = ["jpg", "jpeg", "png"];

    if (in_array($ext, $allow)) {

        $filename = "avatar_" . $user_id . "." . $ext;
        $path = "uploads/" . $filename;  // ← corect

        if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $path)) {

            // UPDATE DB
            $sql = "UPDATE users SET avatar=? WHERE id_user=?";
            $stmt = mysqli_prepare($conn, $sql);
            
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $path, $user_id);
                mysqli_stmt_execute($stmt);
            }

            // ACTUALIZEZ SESIUNEA CU NOUL PATH
            $_SESSION["avatar"] = $path;

        } else {
            echo "Nu pot salva fișierul în folderul uploads.";
            exit;
        }
    }
}

// Înapoi la pagina anterioară
header("Location: ".$_SERVER["HTTP_REFERER"]);
exit;
?>
