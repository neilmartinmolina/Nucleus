<?php
require "includes/core.php";

$isAjaxRequest = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

function deleteSubjectResponse(bool $success, string $message, string $redirect = "dashboard.php?page=folders"): void
{
    global $isAjaxRequest;

    if ($isAjaxRequest) {
        header("Content-Type: application/json");
        echo json_encode([
            "success" => $success,
            "message" => $message,
            "redirect" => $redirect,
            "page" => "folders",
        ]);
        exit;
    }

    echo $success
        ? SweetAlert::success("Success", $message, $redirect)
        : SweetAlert::error("Error", $message, $redirect);
    exit;
}

// Check if user is authenticated
if (!isAuthenticated()) {
    deleteSubjectResponse(false, "Please login first", "index.php");
}

// Check if user has manage_groups permission
if (!hasPermission("manage_groups")) {
    deleteSubjectResponse(false, "You do not have permission to manage subjects");
}

// Sanitize and validate folder ID
$folderId = $_POST["id"] ?? $_GET["id"] ?? null;

if (!$folderId || !is_numeric($folderId)) {
    deleteSubjectResponse(false, "Invalid subject ID");
}

// Check if user can delete this folder (admin or creator)
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE subject_id = ?");
$stmt->execute([$folderId]);
$folder = $stmt->fetch();

if (!$folder) {
    deleteSubjectResponse(false, "Subject not found");
}

if ($_SESSION["role"] !== "admin" && $folder["created_by"] != $_SESSION["userId"]) {
    deleteSubjectResponse(false, "You can only delete subjects you created");
}

// Use transactions for safe deletion
try {
    $pdo->beginTransaction();
    $subjectCode = $folder["subject_code"];
    
    // Set subject_id to NULL for projects in this subject
    $stmt = $pdo->prepare("UPDATE projects SET subject_id = NULL, saved_at = NOW(), updated_at = NOW() WHERE subject_id = ?");
    $stmt->execute([$folderId]);
    $unlinkedProjects = $stmt->rowCount();
    
    // Delete subject
    $stmt = $pdo->prepare("DELETE FROM subjects WHERE subject_id = ?");
    $stmt->execute([$folderId]);
    logActivity("subject_unlisted", "Unlisted subject {$subjectCode}; {$unlinkedProjects} project(s) were unassigned");
    
    $pdo->commit();
    
    deleteSubjectResponse(true, "Subject deleted successfully");
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Subject deletion error: " . $e->getMessage());
    deleteSubjectResponse(false, "Failed to delete subject");
}

