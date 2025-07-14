<?php
// =============================================
// CONFIGURATION & DATABASE CONNECTION
// =============================================
session_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Get logged-in agent ID from session
$agent_id = $_SESSION['user_id'] ?? 0;
if (!$agent_id || $agent_id < 1) {
    // For testing without session, hardcode an agent ID
    $agent_id = 1;
}

// =============================================
// ADD LISTING FUNCTIONALITY (WITH IMAGE UPLOAD) - UPDATED FOR SCHEMA
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_listing') {
    header('Content-Type: application/json');

    // Validate required fields based on schema
    $required = ['title', 'price', 'property_type', 'street_address', 'city', 'state', 'zip_code', 'bedrooms', 'bathrooms', 'square_feet'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $missing[] = $field;
        }
    }

    if (count($missing) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields: ' . implode(', ', $missing)]);
        exit;
    }

    // Sanitize inputs
    $title = htmlspecialchars(trim($_POST['title']));
    $price = (float) $_POST['price'];
    $property_type = htmlspecialchars(trim($_POST['property_type'] ?? ''));
    $address = htmlspecialchars(trim($_POST['street_address']));
    $city = htmlspecialchars(trim($_POST['city']));
    $state = htmlspecialchars(trim($_POST['state']));
    $zip_code = htmlspecialchars(trim($_POST['zip_code']));
    $bedrooms = (int) $_POST['bedrooms'];
    $bathrooms = (float) $_POST['bathrooms'];
    $square_feet = (int) $_POST['square_feet'];
    $description = htmlspecialchars(trim($_POST['description'] ?? ''));

    $status = 'available'; // Default status
    $country = 'KE'; // Default country
    $user_id = $agent_id; // Using logged-in agent ID

    try {
        // Insert new listing
        $stmt = $conn->prepare("INSERT INTO properties 
    (title, price, address, city, state, zip_code, bedrooms, bathrooms, square_feet, description, 
    property_type, status, country, agent_id, owner_id, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        // Add this line to define owner_id
        $owner_id = $agent_id;

        // Fix the binding parameters
        $stmt->bind_param(
            "sdssssiidssssii", // Corrected type specifiers
            $title,
            $price,
            $address,
            $city,
            $state,
            $zip_code,
            $bedrooms,
            $bathrooms,
            $square_feet,
            $description,
            $property_type,
            $status,
            $country,
            $agent_id,   // agent_id
            $owner_id    // owner_id (now defined)
        );

        if ($stmt->execute()) {
            $new_listing_id = $stmt->insert_id;

            // Handle image uploads
            if (!empty($_FILES['images']['name'][0])) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $images = $_FILES['images'];
                $primarySet = false;

                for ($i = 0; $i < count($images['name']); $i++) {
                    if ($images['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $images['tmp_name'][$i];
                        $ext = pathinfo($images['name'][$i], PATHINFO_EXTENSION);
                        $filename = uniqid('img_') . '.' . $ext;
                        $destination = $uploadDir . $filename;

                        if (move_uploaded_file($tmp_name, $destination)) {
                            // Insert into property_images
                            $isPrimary = $primarySet ? 0 : 1;
                            $primarySet = true; // only the first image is primary

                            $upload_path = 'uploads/' . $filename; // Store relative path

                            $imageStmt = $conn->prepare("INSERT INTO property_images 
    (property_id, image_path, is_primary) 
    VALUES (?, ?, ?)");
                            $imageStmt->bind_param("isi", $new_listing_id, $upload_path, $isPrimary);
                            $imageStmt->execute();
                            $imageStmt->close();
                        }
                    }
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Listing added successfully!',
                'id' => $new_listing_id
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
    }

    exit;
}

// =============================================
// GET SINGLE PROPERTY FOR EDITING
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint']) && $_GET['endpoint'] === 'property' && isset($_GET['id'])) {
    header('Content-Type: application/json');

    $id = (int) $_GET['id'];
    $stmt = $conn->prepare("
        SELECT p.*, 
               u1.username AS agent_name,
               u2.username AS owner_name
        FROM properties p
        LEFT JOIN users u1 ON p.agent_id = u1.id
        LEFT JOIN users u2 ON p.owner_id = u2.id
        WHERE p.id = ? AND p.agent_id = ?
    ");
    $stmt->bind_param("ii", $id, $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $property = $result->fetch_assoc();
    $stmt->close();

    // Get images
    $imageStmt = $conn->prepare("SELECT * FROM property_images WHERE property_id = ?");
    $imageStmt->bind_param("i", $id);
    $imageStmt->execute();
    $images = $imageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $imageStmt->close();

    echo json_encode([
        'property' => $property,
        'images' => $images
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_listing') {
    header('Content-Type: application/json');

    // Validate required fields
    $required = ['title', 'price', 'property_type', 'street_address', 'city', 'state', 'zip_code', 'bedrooms', 'bathrooms', 'square_feet'];
    $missing = array_filter($required, fn($field) => empty($_POST[$field]));

    if (count($missing) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields: ' . implode(', ', $missing)]);
        exit;
    }

    // Sanitize inputs
    $id = (int) $_POST['id'];
    $title = htmlspecialchars(trim($_POST['title']));
    $price = (float) $_POST['price'];
    $property_type = htmlspecialchars(trim($_POST['property_type']));
    $status = htmlspecialchars(trim($_POST['status'] ?? 'available'));
    $bedrooms = (int) $_POST['bedrooms'];
    $bathrooms = (float) $_POST['bathrooms'];
    $square_feet = (int) $_POST['square_feet'];
    $lot_size = !empty($_POST['lot_size']) ? (float) $_POST['lot_size'] : null;
    $year_built = !empty($_POST['year_built']) ? (int) $_POST['year_built'] : null;
    $description = htmlspecialchars(trim($_POST['description'] ?? ''));
    $address = htmlspecialchars(trim($_POST['street_address']));
    $city = htmlspecialchars(trim($_POST['city']));
    $state = htmlspecialchars(trim($_POST['state']));
    $zip_code = htmlspecialchars(trim($_POST['zip_code']));
    $country = htmlspecialchars(trim($_POST['country'] ?? 'USA'));
    $is_featured = isset($_POST['is_featured']) ? (int) $_POST['is_featured'] : 0;

    try {
        $stmt = $conn->prepare("
            UPDATE properties SET 
                title = ?, 
                description = ?,
                price = ?,
                property_type = ?,
                status = ?,
                bedrooms = ?,
                bathrooms = ?,
                square_feet = ?,
                lot_size = ?,
                year_built = ?,
                address = ?,
                city = ?,
                state = ?,
                zip_code = ?,
                country = ?,
                is_featured = ?
            WHERE id = ? AND agent_id = ?
        ");

        // Corrected bind_param with proper types and variables
        $stmt->bind_param(
            "ssdssiididssssssii", // 18 characters for 18 params
            $title,
            $description,
            $price,
            $property_type,
            $status,
            $bedrooms,
            $bathrooms,
            $square_feet,
            $lot_size,
            $year_built,
            $address,
            $city,
            $state, // Added missing state variable
            $zip_code,
            $country,
            $is_featured,
            $id,
            $agent_id
        );

        if ($stmt->execute()) {
            // Handle image uploads if any
            if (!empty($_FILES['images']['name'][0])) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $images = $_FILES['images'];
                for ($i = 0; $i < count($images['name']); $i++) {
                    if ($images['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $images['tmp_name'][$i];
                        $ext = pathinfo($images['name'][$i], PATHINFO_EXTENSION);
                        $filename = uniqid('img_') . '.' . $ext;
                        $destination = $uploadDir . $filename;

                        if (move_uploaded_file($tmp_name, $destination)) {
                            // Insert into property_images
                            $imageStmt = $conn->prepare("
                                INSERT INTO property_images 
                                (property_id, image_path) 
                                VALUES (?, ?)
                            ");
                            $upload_path = 'uploads/' . $filename;
                            $imageStmt->bind_param("is", $id, $upload_path);
                            $imageStmt->execute();
                            $imageStmt->close();
                        }
                    }
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Listing updated successfully!',
                'id' => $id
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
    }

    exit;
}

// =============================================
// REST API ENDPOINTS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    if ($_GET['endpoint'] === 'metrics') {
        echo json_encode(getDashboardMetrics($conn, $agent_id));
        exit;
    }

    if ($_GET['endpoint'] === 'listings') {
        echo json_encode(getRecentListings($conn, $agent_id));
        exit;
    }

    // Add this new endpoint
    if ($_GET['endpoint'] === 'active_listings') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;
        echo json_encode(getActiveListings($conn, $agent_id, $limit));
        exit;
    }
}

// =============================================
// DASHBOARD FUNCTIONS
// =============================================

// Get all dashboard metrics (UPDATED VERSION)

function getActiveListingsCount($conn, $agent_id)
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total 
                           FROM properties 
                           WHERE agent_id = ? 
                           AND status = 'available'");
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['total'] ?? 0;
}
function getDashboardMetrics($conn, $agent_id)
{
    return [
        'activeListings' => getActiveListingsCount($conn, $agent_id),
        'activeClients' => getActiveClients($conn, $agent_id),
        'commissionEarned' => getCommissionEarned($conn, $agent_id),
        'avgRating' => getAverageRating($conn, $agent_id),
    ];
}
function getActiveListings($conn, $agent_id, $limit = 5)
{
    $stmt = $conn->prepare("SELECT p.*, 
                           (SELECT image_path FROM property_images 
                            WHERE property_id = p.id 
                            ORDER BY is_primary DESC LIMIT 1) AS thumbnail
                           FROM properties p
                           WHERE p.agent_id = ? 
                           AND p.status = 'available'
                           ORDER BY p.created_at DESC
                           LIMIT ?");
    $stmt->bind_param("ii", $agent_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $listings = [];

    while ($row = $result->fetch_assoc()) {
        $listings[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'price' => $row['price'],
            'address' => $row['address'],
            'bedrooms' => $row['bedrooms'],
            'bathrooms' => $row['bathrooms'],
            'square_feet' => $row['square_feet'],
            'thumbnail' => $row['thumbnail'] ? $row['thumbnail'] : 'placeholder.jpg',
            'status' => $row['status'],
            'created_at' => $row['created_at']
        ];
    }

    $stmt->close();
    return $listings;
}

// Get active clients count
function getActiveClients($conn, $agent_id)
{
    $sql = "SELECT COUNT(DISTINCT u.id) AS total
            FROM (
                SELECT inquirer_id AS client_id 
                FROM property_inquiries 
                WHERE agent_id = ?
                AND status IN ('new', 'contacted', 'scheduled')
                
                UNION
                
                SELECT receiver_id AS client_id 
                FROM messages 
                WHERE sender_id = ? 
                AND is_read = 0
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ) AS active_clients
            JOIN users u ON active_clients.client_id = u.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $agent_id, $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['total'] ?? 0;
}

// Get commission earned
function getCommissionEarned($conn, $agent_id)
{
    $stmt = $conn->prepare("SELECT SUM(commission_earned) AS total 
                           FROM transactions 
                           WHERE agent_id = ? 
                           AND transaction_date > DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['total'] ?? 0;
}

// Get average rating
function getAverageRating($conn, $agent_id)
{
    $stmt = $conn->prepare("SELECT AVG(rating) AS average 
                           FROM agent_ratings 
                           WHERE agent_id = ?");
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $avg = $row['average'] ?? 0;
    return round($avg, 1);
}

// Get recent listings
function getRecentListings($conn, $agent_id)
{
    $sql = "SELECT p.id, p.title, p.price, p.status, p.bedrooms, 
                   p.bathrooms, p.square_feet, p.address,
                   pi.image_path AS thumbnail
            FROM properties p
            LEFT JOIN property_images pi ON p.id = pi.property_id AND pi.is_primary = 1
            WHERE p.agent_id = ?
            GROUP BY p.id
            ORDER BY p.created_at DESC 
            LIMIT 3";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $listings = [];

    while ($row = $result->fetch_assoc()) {
        $listings[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'price' => $row['price'],
            'status' => $row['status'],
            'bedrooms' => $row['bedrooms'],
            'bathrooms' => $row['bathrooms'],
            'square_feet' => $row['square_feet'],
            'address' => $row['address'],
            'thumbnail' => $row['thumbnail'] ?: null
        ];
    }

    $stmt->close();
    return $listings;
}

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Agent Dashboard - Real Estate AI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root {
            --primary-teal: #0d9488;
            --secondary-coral: #f87171;
            --accent-gold: #fef08a;
            --neutral-bg: #fafafa;
            --card-bg: #ffffff;
            --text-dark: #1f2937;
            --text-light: #6b7280;
            --border-gray: #e5e7eb;
            --success-green: #34d399;
            --info-blue: #60a5fa;
            --purple: #a78bfa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--neutral-bg);
            color: var(--text-dark);
            display: flex;
            min-height: 100vh;
        }


        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: linear-gradient(to bottom, var(--primary-teal), #0a7c72);
            color: white;
            padding: 25px 15px;
            height: 100vh;
            position: fixed;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }

        .brand {
            display: flex;
            align-items: center;
            margin-bottom: 35px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .brand i {
            font-size: 28px;
            margin-right: 12px;
            color: var(--accent-gold);
        }

        .brand h1 {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .user-info {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--accent-gold), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            margin-right: 15px;
        }

        .user-details h3 {
            font-size: 1.