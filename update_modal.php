<?php
require 'db.php';

$websiteId = $_POST["websiteId"];
$version = $_POST["version"];
$status = $_POST["status"];
$note = $_POST["note"];
$userId = $_SESSION["userId"];

$pdo->beginTransaction();

try {

$update = $pdo->prepare("
UPDATE websites 
SET currentVersion=?, status=?, lastUpdatedAt=NOW(), updatedBy=? 
WHERE websiteId=?
");

$update->execute([$version,$status,$userId,$websiteId]);

$log = $pdo->prepare("
INSERT INTO updateLogs (websiteId, version, note, updatedBy)
VALUES (?,?,?,?)
");

$log->execute([$websiteId,$version,$note,$userId]);

$pdo->commit();

header("Location: dashboard.php");
exit;

} catch(Exception $e){
    $pdo->rollBack();
    echo "Error updating";
}