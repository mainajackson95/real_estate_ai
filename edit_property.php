<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and is a seller
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'seller') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $property_id = intval($_POST['id']);

    // Verify the property belongs to this seller
    $check_stmt = $conn->prepare("SELECT id FROM properties WHERE id = ? AND owner_id = ?");
    $check_stmt->bind_param("ii", $property_id, $user_id);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows === 0) {
        $_SESSION['error_message'] = "Property not found or you don't have permission to edit it";
        header("Location: seller_dashboard.php");
        exit();
    }

    // Validate and sanitize input
    $title = $conn->real_escape_string($_POST['title']);
    $description = $conn->real_escape_string($_POST['description']);
    $price = floatval($_POST['price']);
    $property_type = $conn->real_escape_string($_POST['property_type']);
    $bedrooms = intval($_POST['bedrooms']);
    $bathrooms = floatval($_POST['bathrooms']);
    $square_feet = intval($_POST['square_feet']);
    $address = $conn->real_escape_string($_POST['address']);
    $city = $conn->real_escape_string($_POST['city']);
    $state = $conn->real_escape_string($_POST['state']);
    $zip_code = $conn->real_escape_string($_POST['zip_code']);
    $status = $conn->real_escape_string($_POST['status']);

    // Update property
    $stmt = $conn->prepare("
        UPDATE properties SET
            title = ?, description = ?, price = ?, property_type = ?,
            bedrooms = ?, bathrooms = ?, square_feet = ?,
            address = ?, city = ?, state = ?, zip_code = ?, status = ?
        WHERE id = ? AND owner_id = ?
    ");

    $stmt->bind_param(
        "ssdsidiissssii",
        $title,
        $description,
        $price,
        $property_type,
        $bedrooms,
        $bathrooms,
        $square_feet,
        $address,
        $city,
        $state,
        $zip_code,
        $status,
        $property_id,
        $user_id
    );

    if ($stmt->execute()) {
        // Handle file uploads if any
        if (!empty($_FILES['images']['name'][0])) {
            $upload_dir = 'uploads/properties/' . $property_id . '/';

            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Process each uploaded file
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                $file_name = basename($_FILES['images']['name'][$key]);
                $file_path = $upload_dir . uniqid() . '_' . $file_name;

                if (move_uploaded_file($tmp_name, $file_path)) {
                    // Insert image record
                    $is_primary = 0; // Don't make these primary by default
                    $img_stmt = $conn->prepare("
                        INSERT INTO property_images (property_id, image_path, is_primary)
                        VALUES (?, ?, ?)
                    ");
                    $img_stmt->bind_param("isi", $property_id, $file_path, $is_primary);
                    $img_stmt->execute();
                    $img_stmt->close();
                }
            }
        }

        $_SESSION['success_message'] = "Property updated successfully!";
        header("Location: seller_dashboard.php");
        exit();
    } else {
        $_SESSION['error_message'] = "Error updating property: " . $conn->error;
        header("Location: seller_dashboard.php");
        exit();
    }
} else {
    header("Location: seller_dashboard.php");
    exit();
}
