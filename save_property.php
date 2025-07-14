<?php
session_start();
require_once 'db_connection.php'; // Include your database connection

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'You must be logged in to save properties']);
    exit;
}

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
$propertyId = $data['property_id'] ?? null;

if (!$propertyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid property ID']);
    exit;
}

// Check if already saved
$userId = $_SESSION['user_id'];
$checkStmt = $conn->prepare("SELECT * FROM saved_properties WHERE user_id = ? AND property_id = ?");
$checkStmt->bind_param("ii", $userId, $propertyId);
$checkStmt->execute();

if ($checkStmt->get_result()->num_rows > 0) {
    echo json_encode(['error' => 'Property already saved']);
    exit;
}

// Save property
$insertStmt = $conn->prepare("INSERT INTO saved_properties (user_id, property_id) VALUES (?, ?)");
$insertStmt->bind_param("ii", $userId, $propertyId);

if ($insertStmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $conn->error]);
}
