<?php
session_start();
require 'db.php';

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: login.php");
    exit;
}

$username = trim($_POST["username"] ?? '');
$password = $_POST["password"] ?? '';

if (empty($username) || empty($password)) {
    $_SESSION["login_error"] = "Username and password are required";
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["passwordHash"])) {
    $_SESSION["login_error"] = "Invalid credentials";
    header("Location: login.php");
    exit;
}

$_SESSION["userId"] = $user["userId"];
$_SESSION["fullName"] = $user["fullName"];
unset($_SESSION["login_error"]);

header("Location: dashboard.php");
exit;