<?php
session_start();
require_once 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_pic'])) {
    $user_id = $_SESSION['user_id'];

    // File upload handling
    $target_dir = "uploads/profiles/";
    $target_file = $target_dir . basename($_FILES["profile_pic"]["name"]);
    $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Check if image file is valid
    $check = getimagesize($_FILES["profile_pic"]["tmp_name"]);
    if ($check === false) {
        echo json_encode(['success' => false, 'error' => 'File is not an image.']);
        exit;
    }

    // Generate unique filename
    $new_filename = "profile_" . $user_id . "_" . time() . "." . $imageFileType;
    $target_path = $target_dir . $new_filename;

    // Move uploaded file
    if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_path)) {
        // Update database
        $stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
        $stmt->bind_param("si", $target_path, $user_id);
        $stmt->execute();

        // Update session
        $_SESSION['profile_pic'] = $target_path;

        echo json_encode(['success' => true, 'filePath' => $target_path]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error uploading file.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
}
?>
