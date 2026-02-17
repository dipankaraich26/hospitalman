<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /hospitalman/modules/dashboard/index.php');
} else {
    header('Location: /hospitalman/modules/auth/login.php');
}
exit;
