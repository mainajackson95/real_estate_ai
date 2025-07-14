<?php
require_once 'config.php';

class PropertyController
{
    private $conn;

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
    }

    public function get_user_properties()
    {
        if (!is_authenticated()) {
            send_error_response('User not authenticated', 401);
        }

        $user_id = get_current_user_id();

        try {
            // Get user's properties
            $stmt = $this->conn->prepare("
                SELECT p.*, u.username as owner_name 
                FROM properties p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.user_id = ? 
                ORDER BY p.created_at DESC
            ");
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $properties = [];
            while ($row = $result->fetch_assoc()) {
                $properties[] = $row;
            }

            // Get user information
            $user_stmt = $this->conn->prepare("SELECT username, email, role FROM users WHERE id = ?");
            $user_stmt->bind_param("s", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_info = $user_result->fetch_assoc();

            // Get property statistics
            $stats_stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_properties,
                    COUNT(CASE WHEN type = 'house' THEN 1 END) as houses,
                    COUNT(CASE WHEN type = 'apartment' THEN 1 END) as apartments,
                    COUNT(CASE WHEN type = 'condo' THEN 1 END) as condos,
                    AVG(price) as avg_price,
                    SUM(price) as total_value
                FROM properties 
                WHERE user_id = ?
            ");
            $stats_stmt->bind_param("s", $user_id);
            $stats_stmt->execute();
            $stats_result = $stats_stmt->get_result();
            $stats = $stats_result->fetch_assoc();

            send_success_response([
                'properties' => $properties,
                'user' => $user_info,
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            send_error_response('Error fetching properties: ' . $e->getMessage(), 500);
        }
    }

    public function add_property()
    {
        if (!is_authenticated()) {
            send_error_response('User not authenticated', 401);
        }

        $user_id = get_current_user_id();

        try {
            // Get JSON input
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data) {
                // Try to get from POST data
                $data = $_POST;
            }

            // Validate required fields
            if (empty($data['title'])) {
                send_error_response('Title is required');
            }

            // Sanitize inputs
            $title = sanitize_input($data['title']);
            $price = !empty($data['price']) ? floatval($data['price']) : null;
            $address = sanitize_input($data['address'] ?? '');
            $type = sanitize_input($data['type'] ?? 'house');
            $bedrooms = !empty($data['bedrooms']) ? intval($data['bedrooms']) : 0;
            $bathrooms = !empty($data['bathrooms']) ? intval($data['bathrooms']) : 0;
            $description = sanitize_input($data['description'] ?? '');

            // Validate property type
            $allowed_types = ['house', 'apartment', 'condo'];
            if (!in_array($type, $allowed_types)) {
                send_error_response('Invalid property type');
            }

            // Generate property ID
            $property_id = generate_uuid();

            // Insert property
            $stmt = $this->conn->prepare("
                INSERT INTO properties (id, user_id, title, price, address, type, bedrooms, bathrooms, description) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssdssiis", $property_id, $user_id, $title, $price, $address, $type, $bedrooms, $bathrooms, $description);

            if ($stmt->execute()) {
                // Get the created property
                $get_stmt = $this->conn->prepare("SELECT * FROM properties WHERE id = ?");
                $get_stmt->bind_param("s", $property_id);
                $get_stmt->execute();
                $result = $get_stmt->get_result();
                $property = $result->fetch_assoc();

                send_success_response($property, 'Property added successfully');
            } else {
                send_error_response('Failed to add property: ' . $this->conn->error, 500);
            }

        } catch (Exception $e) {
            send_error_response('Error adding property: ' . $e->getMessage(), 500);
        }
    }

    public function update_property($property_id)
    {
        if (!is_authenticated()) {
            send_error_response('User not authenticated', 401);
        }

        if (!validate_uuid($property_id)) {
            send_error_response('Invalid property ID');
        }

        $user_id = get_current_user_id();

        try {
            // Check if property belongs to user
            $check_stmt = $this->conn->prepare("SELECT id FROM properties WHERE id = ? AND user_id = ?");
            $check_stmt->bind_param("ss", $property_id, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows === 0) {
                send_error_response('Property not found or access denied', 404);
            }

            // Get JSON input
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data) {
                $data = $_POST;
            }

            // Build update query dynamically
            $update_fields = [];
            $params = [];
            $types = "";

            if (isset($data['title']) && !empty($data['title'])) {
                $update_fields[] = "title = ?";
                $params[] = sanitize_input($data['title']);
                $types .= "s";
            }

            if (isset($data['price'])) {
                $update_fields[] = "price = ?";
                $params[] = floatval($data['price']);
                $types .= "d";
            }

            if (isset($data['address'])) {
                $update_fields[] = "address = ?";
                $params[] = sanitize_input($data['address']);
                $types .= "s";
            }

            if (isset($data['type'])) {
                $allowed_types = ['house', 'apartment', 'condo'];
                if (in_array($data['type'], $allowed_types)) {
                    $update_fields[] = "type = ?";
                    $params[] = $data['type'];
                    $types .= "s";
                }
            }

            if (isset($data['bedrooms'])) {
                $update_fields[] = "bedrooms = ?";
                $params[] = intval($data['bedrooms']);
                $types .= "i";
            }

            if (isset($data['bathrooms'])) {
                $update_fields[] = "bathrooms = ?";
                $params[] = intval($data['bathrooms']);
                $types .= "i";
            }

            if (isset($data['description'])) {
                $update_fields[] = "description = ?";
                $params[] = sanitize_input($data['description']);
                $types .= "s";
            }

            if (empty($update_fields)) {
                send_error_response('No valid fields to update');
            }

            // Add property_id to params
            $params[] = $property_id;
            $types .= "s";

            $query = "UPDATE properties SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                // Get the updated property
                $get_stmt = $this->conn->prepare("SELECT * FROM properties WHERE id = ?");
                $get_stmt->bind_param("s", $property_id);
                $get_stmt->execute();
                $result = $get_stmt->get_result();
                $property = $result->fetch_assoc();

                send_success_response($property, 'Property updated successfully');
            } else {
                send_error_response('Failed to update property: ' . $this->conn->error, 500);
            }

        } catch (Exception $e) {
            send_error_response('Error updating property: ' . $e->getMessage(), 500);
        }
    }

    public function delete_property($property_id)
    {
        if (!is_authenticated()) {
            send_error_response('User not authenticated', 401);
        }

        if (!validate_uuid($property_id)) {
            send_error_response('Invalid property ID');
        }

        $user_id = get_current_user_id();

        try {
            // Check if property belongs to user
            $check_stmt = $this->conn->prepare("SELECT id FROM properties WHERE id = ? AND user_id = ?");
            $check_stmt->bind_param("ss", $property_id, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows === 0) {
                send_error_response('Property not found or access denied', 404);
            }

            // Delete property
            $stmt = $this->conn->prepare("DELETE FROM properties WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ss", $property_id, $user_id);

            if ($stmt->execute()) {
                send_success_response([], 'Property deleted successfully');
            } else {
                send_error_response('Failed to delete property: ' . $this->conn->error, 500);
            }

        } catch (Exception $e) {
            send_error_response('Error deleting property: ' . $e->getMessage(), 500);
        }
    }

    public function get_all_properties()
    {
        // This endpoint can be accessed by anyone (for browsing properties)
        try {
            $stmt = $this->conn->prepare("
                SELECT p.*, u.username as owner_name 
                FROM properties p 
                JOIN users u ON p.user_id = u.id 
                ORDER BY p.created_at DESC
            ");
            $stmt->execute();
            $result = $stmt->get_result();

            $properties = [];
            while ($row = $result->fetch_assoc()) {
                // Don't expose sensitive user info in public listings
                unset($row['user_id']);
                $properties[] = $row;
            }

            send_success_response(['properties' => $properties]);

        } catch (Exception $e) {
            send_error_response('Error fetching properties: ' . $e->getMessage(), 500);
        }
    }
}

// Handle requests
$property = new PropertyController();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$property_id = $_GET['id'] ?? '';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'user_properties':
                $property->get_user_properties();
                break;
            case 'all':
                $property->get_all_properties();
                break;
            default:
                send_error_response('Invalid action');
        }
        break;
    case 'POST':
        if ($action === 'add') {
            $property->add_property();
        } else {
            send_error_response('Invalid action');
        }
        break;
    case 'PUT':
        if (!empty($property_id)) {
            $property->update_property($property_id);
        } else {
            send_error_response('Property ID required');
        }
        break;
    case 'DELETE':
        if (!empty($property_id)) {
            $property->delete_property($property_id);
        } else {
            send_error_response('Property ID required');
        }
        break;
    default:
        send_error_response('Method not allowed', 405);
}
?>
