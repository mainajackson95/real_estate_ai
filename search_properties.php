<?php
// search_properties.php
header('Content-Type: application/json');

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "real_estate_ai_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

// Get and sanitize search parameters
$location = isset($_GET['location']) ? $conn->real_escape_string($_GET['location']) : null;
$propertyType = isset($_GET['property_type']) ? $conn->real_escape_string($_GET['property_type']) : null;
$priceRange = isset($_GET['price_range']) ? $_GET['price_range'] : null;
$bedrooms = isset($_GET['bedrooms']) ? (int) $_GET['bedrooms'] : null;

// Build base query
$query = "SELECT 
    p.id, 
    p.title, 
    p.price, 
    p.property_type, 
    p.bedrooms, 
    p.bathrooms, 
    p.square_feet,
    CONCAT(p.address, ', ', p.city, ', ', p.state, ' ', p.zip_code) AS full_address,
    (SELECT image_path FROM property_images WHERE property_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image
FROM properties p
WHERE p.status = 'available'";

// Add location filter
if ($location) {
    $query .= " AND (
        p.city LIKE '%$location%' 
        OR p.state LIKE '%$location%' 
        OR p.zip_code LIKE '%$location%' 
        OR p.address LIKE '%$location%'
    )";
}

// Add property type filter
if ($propertyType && $propertyType !== 'Any Type') {
    $query .= " AND p.property_type = '$propertyType'";
}

// Add price range filter
if ($priceRange && $priceRange !== 'Any Price') {
    switch ($priceRange) {
        case 'Under $200,000':
            $query .= " AND p.price < 200000";
            break;
        case '$200,000 - $400,000':
            $query .= " AND p.price BETWEEN 200000 AND 400000";
            break;
        case '$400,000 - $600,000':
            $query .= " AND p.price BETWEEN 400000 AND 600000";
            break;
        case '$600,000 - $800,000':
            $query .= " AND p.price BETWEEN 600000 AND 800000";
            break;
        case 'Over $800,000':
            $query .= " AND p.price > 800000";
            break;
    }
}

// Add bedrooms filter
if ($bedrooms && $bedrooms > 0) {
    $query .= " AND p.bedrooms >= $bedrooms";
}

// Add sorting and limiting
$query .= " ORDER BY p.created_at DESC LIMIT 50";

// Execute query
$result = $conn->query($query);

if (!$result) {
    echo json_encode(['error' => 'Query failed: ' . $conn->error]);
    exit;
}

// Format results
$properties = [];
while ($row = $result->fetch_assoc()) {
    $properties[] = [
        'id' => $row['id'],
        'title' => $row['title'],
        'price' => number_format($row['price'], 0),
        'type' => $row['property_type'],
        'bedrooms' => $row['bedrooms'],
        'bathrooms' => $row['bathrooms'],
        'square_feet' => number_format($row['square_feet']),
        'address' => $row['full_address'],
        'image' => $row['primary_image'] ?: 'default_property.jpg'
    ];
}

// Return results
echo json_encode([
    'success' => true,
    'count' => count($properties),
    'properties' => $properties
]);

$conn->close();
?>
