<?php
require __DIR__ . '/_inc.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
