<?php
session_start();
include('../db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Add to favorites
        $input = json_decode(file_get_contents('php://input'), true);
        $property_id = $input['property_id'] ?? null;

        if (!$property_id) {
            echo json_encode(['success' => false, 'message' => 'Property ID is required']);
            exit;
        }

        // Check if already favorited
        $check_query = "SELECT id FROM favorites WHERE user_id = ? AND property_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ii", $user_id, $property_id);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Property already in favorites']);
            exit;
        }

        // Add to favorites
        $insert_query = "INSERT INTO favorites (user_id, property_id, created_at) VALUES (?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ii", $user_id, $property_id);

        if ($insert_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Property added to favorites']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add to favorites']);
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Remove from favorites
        $input = json_decode(file_get_contents('php://input'), true);
        $property_id = $input['property_id'] ?? null;

        if (!$property_id) {
            echo json_encode(['success' => false, 'message' => 'Property ID is required']);
            exit;
        }

        $delete_query = "DELETE FROM favorites WHERE user_id = ? AND property_id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("ii", $user_id, $property_id);

        if ($delete_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Property removed from favorites']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to remove from favorites']);
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get saved properties
        $query = "SELECT p.*, f.created_at as saved_at
                  FROM properties p 
                  INNER JOIN favorites f ON p.id = f.property_id 
                  WHERE f.user_id = ? 
                  ORDER BY f.created_at DESC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $properties = [];
        while ($row = $result->fetch_assoc()) {
            $properties[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'price' => (float) $row['price'],
                'bedrooms' => (int) $row['bedrooms'],
                'bathrooms' => (float) $row['bathrooms'],
                'square_feet' => (int) $row['square_feet'],
                'property_type' => $row['property_type'],
                'address' => $row['address'],
                'city' => $row['city'],
                'state' => $row['state'],
                'zip_code' => $row['zip_code'],
                'image_url' => $row['image_url'],
                'is_featured' => (bool) $row['is_featured'],
                'created_at' => $row['created_at'],
                'saved_at' => $row['saved_at']
            ];
        }

        echo json_encode([
            'success' => true,
            'properties' => $properties,
            'total_count' => count($properties)
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Favorites API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while managing favorites'
    ]);
}
?>