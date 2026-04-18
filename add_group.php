<?php
require 'db.php';

$groupName = trim($_POST["groupName"]);
$description = trim($_POST["description"]);

if ($groupName == "") {
    header("Location: dashboard.php");
    exit;
}

$stmt = $pdo->prepare("
INSERT INTO groups (groupName, description)
VALUES (?, ?)
");

$stmt->execute([$groupName, $description]);

header("Location: dashboard.php");
exit;