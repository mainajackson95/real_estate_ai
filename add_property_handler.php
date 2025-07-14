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

    // For simplicity, we'll use a default agent (in real app, seller would select one)
    $default_agent_id = 1; // You should implement agent selection

    // Insert property
    $stmt = $conn->prepare("
        INSERT INTO properties (
            title, description, price, property_type, bedrooms, bathrooms, 
            square_feet, address, city, state, zip_code, agent_id, owner_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssdsidiisssii",
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
        $default_agent_id,
        $user_id
    );

    if ($stmt->execute()) {
        $property_id = $stmt->insert_id;

        // Handle file uploads
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
                    $is_primary = ($key === 0) ? 1 : 0; // First image is primary
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

        $_SESSION['success_message'] = "Property added successfully!";
        header("Location: seller_dashboard.php");
        exit();
    } else {
        $_SESSION['error_message'] = "Error adding property: " . $conn->error;
        header("Location: seller_dashboard.php");
        exit();
    }
} else {
    header("Location: seller_dashboard.php");
    exit();
}