<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and is a seller
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'seller') {
    header("Location: login.php");
    exit();
}

if (isset($_GET['id'])) {
    $property_id = intval($_GET['id']);
    $user_id = $_SESSION['user_id'];

    // Verify the property belongs to the logged-in user
    $stmt = $conn->prepare("SELECT owner_id FROM properties WHERE id = ?");
    $stmt->bind_param("i", $property_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $property = $result->fetch_assoc();
        // Verify the property belongs to logged-in user
        if ($property['owner_id'] == $user_id) {
            // Delete property
            $delete_stmt = $conn->prepare("DELETE FROM properties WHERE id = ?");
            $delete_stmt->bind_param("i", $property_id);

            if ($delete_stmt->execute()) {
                $_SESSION['success_message'] = "Property deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Error deleting property: " . $conn->error;
            }
        } else {
            $_SESSION['error_message'] = "You don't have permission to delete this property";
        }
    } else {
        $_SESSION['error_message'] = "Property not found";
    }
}

header("Location: seller_dashboard.php");
exit();
?>
