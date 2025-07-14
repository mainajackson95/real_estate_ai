<?php
require_once 'db_connection.php';

if (isset($_GET['id'])) {
    $property_id = $_GET['id'];

    $sql = "SELECT p.*, 
           (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) AS image_path
           FROM properties p
           WHERE p.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $property_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $property = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode($property);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Property not found']);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Missing property ID']);
}
