<?php
header('Content-Type: application/json');

try {
    // Database connection
    $pdo = new PDO('mysql:host=localhost;dbname=real_estate_ai_db', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get search parameters
    $query = $_GET['q'] ?? '';
    $offset = intval($_GET['offset'] ?? 0);
    $limit = intval($_GET['limit'] ?? 10);

    // Main property search query - ADDED TITLE TO SELECT
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.title, p.price, p.bedrooms, p.bathrooms,
            (SELECT image_path FROM property_images 
             WHERE property_id = p.id 
             ORDER BY is_primary DESC, id ASC 
             LIMIT 1) AS image_url
        FROM properties p
        WHERE 
            CONCAT(p.title, p.description, p.address) LIKE :query
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(':query', '%' . $query . '%');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process results
    foreach ($properties as &$property) {
        // Set default image if none found
        if (empty($property['image_url'])) {
            $property['image_url'] = 'images/placeholder.jpg';
        } else {
            $property['image_url'] = 'uploads/' . $property['image_url'];
        }

        // Ensure numeric fields are properly typed
        $property['price'] = floatval($property['price']);
        $property['bedrooms'] = intval($property['bedrooms']);
        $property['bathrooms'] = intval($property['bathrooms']);

        // NEW: Clean up title display
        $property['title'] = htmlspecialchars($property['title'] ?? 'Untitled Property');
    }

    // Return successful response
    echo json_encode([
        'success' => true,
        'data' => $properties
    ]);

} catch (PDOException $e) {
    // Database error
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // General error
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
