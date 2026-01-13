<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/creator-auth.php';

logoutCreator();
header('Location: /creator-dashboard/login.php');
exit;
