<?php
session_start();
include('db_connection.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'User not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user's properties only
$query = "SELECT * FROM properties WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$properties = [];
while ($row = $result->fetch_assoc()) {
    $properties[] = $row;
}

// Also get user information
$user_query = "SELECT username, email FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_info = $user_result->fetch_assoc();

// Get property statistics
$stats_query = "SELECT 
    COUNT(*) as total_properties,
    COUNT(CASE WHEN type = 'house' THEN 1 END) as houses,
    COUNT(CASE WHEN type = 'apartment' THEN 1 END) as apartments,
    COUNT(CASE WHEN type = 'condo' THEN 1 END) as condos,
    AVG(price) as avg_price,
    SUM(price) as total_value
    FROM properties WHERE user_id = ?";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

echo json_encode([
    'properties' => $properties,
    'user' => $user_info,
    'stats' => $stats
]);

$stmt->close();
$user_stmt->close();
$stats_stmt->close();
$conn->close();
?>
