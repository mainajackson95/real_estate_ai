<?php
session_start();
require_once 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $new_username = $_POST['username'] ?? '';

    // Validate username
    if (empty($new_username)) {
        echo json_encode(['success' => false, 'error' => 'Username is required.']);
        exit;
    }

    // Handle file upload
    $profile_pic_path = $_SESSION['profile_pic'] ?? '';

    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == UPLOAD_ERR_OK) {
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
            $profile_pic_path = $target_path;
        } else {
            echo json_encode(['success' => false, 'error' => 'Error uploading file.']);
            exit;
        }
    }

    // Update database
    $stmt = $conn->prepare("UPDATE users SET username = ?, profile_pic = ? WHERE id = ?");
    $stmt->bind_param("ssi", $new_username, $profile_pic_path, $user_id);

    if ($stmt->execute()) {
        // Update session
        $_SESSION['username'] = $new_username;
        $_SESSION['profile_pic'] = $profile_pic_path;

        echo json_encode([
            'success' => true,
            'username' => $new_username,
            'profile_pic' => $profile_pic_path
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
}
?>
