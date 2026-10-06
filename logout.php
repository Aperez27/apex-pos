<?php
require 'config/config.php';
app_session_start();
$_SESSION = [];
session_destroy();
header("Location: index.php");
exit;
