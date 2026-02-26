<?php require_once "auth.php"; ?>
<script src="assets/main.js" defer></script>

<header class="top-header">

    <div class="header-left">
        <h1 class="page-title"><?= $page_title ?></h1>
        <p class="welcome-text">
            Welcome back, <strong><?= ucfirst(user_role()); ?></strong>!
        </p>
    </div>

    <div class="header-right">

        <div class="date-box">
            <?= date("l, F j, Y") ?>
        </div>

        <div class="theme-toggle" onclick="toggleTheme()">
            <span id="themeIcon">🌙</span>
        </div>

        <div class="user-box" onclick="document.getElementById('avatarInput').click();">
            <img src="<?= user_avatar(); ?>?v=<?= time(); ?>" class="avatar">

            <div class="user-info">
                <span class="user-name"><?= user_name(); ?></span>
                <span class="user-role"><?= ucfirst(user_role()); ?></span>
            </div>
        </div>

        <form action="upload_avatar.php" method="POST" enctype="multipart/form-data" id="avatarForm">
            <input type="file" name="avatar" id="avatarInput" style="display:none;"
                   onchange="document.getElementById('avatarForm').submit();">
        </form>

    </div>

    <style>
        .top-header{
            padding-top: 20px;
        }

    </style>

</header>
