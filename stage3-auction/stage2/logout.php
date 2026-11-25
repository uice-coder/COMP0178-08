<?php

session_start();

unset($_SESSION['logged_in']);
unset($_SESSION['account_type']);
unset($_SESSION['userID']);
unset($_SESSION['username']);
unset($_SESSION['is_buyer']);
unset($_SESSION['is_seller']);

setcookie(session_name(), "", time() - 360);
session_destroy();


// Redirect to index
header("Location: index.php");
exit;

?>