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
$password = "kaisec@2025";
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

// Get active clients count (FIXED)
function getActiveClients($conn, $agent_id)
{
  $sql = "SELECT COUNT(DISTINCT client_id) AS total
            FROM (
                SELECT inquirer_id AS client_id 
                FROM property_inquiries 
                WHERE agent_id = ?
                AND status IN ('new', 'contacted', 'scheduled')
                
                UNION
                
                SELECT sender_id AS client_id 
                FROM messages 
                WHERE property_id IN (
                    SELECT id FROM properties WHERE agent_id = ?
                )
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ) AS active_clients";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ii", $agent_id, $agent_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result->fetch_assoc();
  $stmt->close();
  return $row['total'] ?? 0;
}

// Get commission earned (FIXED)
function getCommissionEarned($conn, $agent_id)
{
  $stmt = $conn->prepare("SELECT COALESCE(SUM(commission_earned), 0) AS total 
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
  $stmt = $conn->prepare("SELECT COALESCE(AVG(rating), 0) AS average 
                            FROM agent_ratings 
                            WHERE agent_id = ?");
  $stmt->bind_param("i", $agent_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result->fetch_assoc();
  $stmt->close();
  return round($row['average'] ?? 0, 1);
}

// Get recent listings
function getRecentListings($conn, $agent_id)
{
  $sql = "SELECT p.id, p.title, p.price, p.status, p.bedrooms, 
                   p.bathrooms, p.square_feet, p.address,
                   (SELECT image_path FROM property_images 
                    WHERE property_id = p.id AND is_primary = 1 
                    LIMIT 1) AS thumbnail
            FROM properties p
            WHERE p.agent_id = ?
            ORDER BY p.created_at DESC 
            LIMIT 3";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    return [];
  }

  $stmt->bind_param("i", $agent_id);

  if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    $stmt->close();
    return [];
  }

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
      font-size: 1.1rem;
      margin-bottom: 5px;
    }

    .user-details span {
      font-size: 0.85rem;
      opacity: 0.8;
      display: inline-block;
      background: rgba(255, 255, 255, 0.15);
      padding: 3px 8px;
      border-radius: 20px;
    }

    html {
      scroll-behavior: smooth;
    }

    .nav-links {
      flex-grow: 1;
    }

    .nav-links a {
      display: flex;
      align-items: center;
      color: white;
      padding: 14px 15px;
      text-decoration: none;
      font-size: 1rem;
      margin-bottom: 8px;
      border-radius: 6px;
      transition: all 0.3s;
    }

    .nav-links a:hover,
    .nav-links a.active {
      background: rgba(255, 255, 255, 0.15);
    }

    .nav-links a i {
      font-size: 1.2rem;
      width: 30px;
      margin-right: 12px;
    }

    .logout-btn {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      border: none;
      padding: 12px;
      border-radius: 6px;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.3s;
    }

    .logout-btn:hover {
      background: rgba(255, 255, 255, 0.2);
    }

    .logout-btn i {
      margin-right: 8px;
    }

    /* Transactions Section Styles */
    #transactions-section {
      margin-top: 40px;
    }

    .transaction-filters {
      display: flex;
      gap: 15px;
      flex-wrap: wrap;
      align-items: center;
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .filter-group label {
      font-weight: 500;
      color: var(--text-light);
    }

    .filter-group select {
      padding: 8px 12px;
      border: 1px solid var(--border-gray);
      border-radius: 6px;
      background: white;
    }

    .transactions-summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
      margin-bottom: 25px;
    }

    .summary-card {
      background: var(--card-bg);
      border-radius: 10px;
      padding: 20px;
      display: flex;
      align-items: center;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .summary-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 15px;
      font-size: 1.5rem;
      background: rgba(13, 148, 136, 0.1);
      color: var(--primary-teal);
    }

    .summary-info h3 {
      font-size: 1.8rem;
      margin-bottom: 5px;
      color: var(--text-dark);
    }

    .summary-info p {
      color: var(--text-light);
      font-size: 0.95rem;
    }

    .transactions-table {
      background: var(--card-bg);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .transactions-table table {
      width: 100%;
      border-collapse: collapse;
    }

    .transactions-table th,
    .transactions-table td {
      padding: 15px 20px;
      text-align: left;
      border-bottom: 1px solid var(--border-gray);
    }

    .transactions-table th {
      background-color: #f9fafb;
      font-weight: 600;
      color: var(--text-dark);
    }

    .transactions-table tbody tr:last-child td {
      border-bottom: none;
    }

    .transactions-table tbody tr:hover {
      background-color: rgba(13, 148, 136, 0.03);
    }

    .property-info {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .property-thumb {
      width: 50px;
      height: 50px;
      border-radius: 6px;
      background-size: cover;
      background-position: center;
      background-color: #f1f5f9;
    }

    .property-info h4 {
      margin: 0 0 5px;
      font-size: 1rem;
    }

    .property-info p {
      margin: 0;
      color: var(--primary-teal);
      font-weight: 600;
    }

    .status-badge {
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 500;
    }

    .status-completed {
      background: rgba(52, 211, 153, 0.1);
      color: var(--success-green);
    }

    .status-pending {
      background: rgba(254, 240, 138, 0.25);
      color: #d97706;
    }

    .status-cancelled {
      background: rgba(248, 113, 113, 0.1);
      color: var(--secondary-coral);
    }

    .transactions-table .btn {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-right: 5px;
    }

    .view-details-btn {
      background: rgba(13, 148, 136, 0.1);
      color: var(--primary-teal);
    }

    .download-btn {
      background: rgba(167, 139, 250, 0.1);
      color: var(--purple);
    }

    /* Responsive styles for table */
    @media (max-width: 768px) {
      .transactions-table {
        overflow-x: auto;
      }

      .transactions-table table {
        min-width: 700px;
      }

      .transaction-filters {
        flex-direction: column;
        align-items: flex-start;
      }

      .filter-group {
        width: 100%;
      }

      .filter-group select {
        flex-grow: 1;
      }
    }

    /* Main Content Styles */
    .content {
      flex-grow: 1;
      margin-left: 260px;
      padding: 30px;
    }

    .dashboard-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
    }

    .dashboard-header h1 {
      font-size: 2.2rem;
      font-weight: 700;
      color: var(--primary-teal);
    }

    .date-display {
      font-size: 1rem;
      color: var(--text-light);
      background: var(--card-bg);
      padding: 8px 15px;
      border-radius: 20px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    }

    /* Stats Cards */
    .stats-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .stat-card {
      background: var(--card-bg);
      border-radius: 10px;
      padding: 25px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
      display: flex;
      align-items: center;
      transition: transform 0.3s;
    }

    .stat-card:hover {
      transform: translateY(-5px);
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 20px;
      font-size: 1.8rem;
    }

    .stat-icon.listings {
      background: rgba(13, 148, 136, 0.15);
      color: var(--primary-teal);
    }

    .stat-icon.clients {
      background: rgba(167, 139, 250, 0.15);
      color: var(--purple);
    }

    .stat-icon.commission {
      background: rgba(254, 240, 138, 0.25);
      color: #d97706;
    }

    .stat-icon.rating {
      background: rgba(52, 211, 153, 0.15);
      color: var(--success-green);
    }

    /* Clients Section Styles */
    .clients-container {
      display: flex;
      gap: 25px;
    }

    .client-list {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 15px;
    }

    .client-card {
      background: var(--card-bg);
      border-radius: 10px;
      padding: 20px;
      display: flex;
      align-items: center;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
      transition: transform 0.3s, box-shadow 0.3s;
    }

    .client-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .client-avatar {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: linear-gradient(45deg, var(--purple), var(--primary-teal));
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: bold;
      font-size: 1.2rem;
      margin-right: 20px;
    }

    .client-info {
      flex: 1;
    }

    .client-info h3 {
      margin-bottom: 8px;
      color: var(--text-dark);
    }

    .client-phone,
    .client-email {
      margin: 4px 0;
      color: var(--text-light);
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .client-properties {
      margin-top: 12px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .property-tag {
      background: rgba(13, 148, 136, 0.1);
      color: var(--primary-teal);
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 500;
    }

    .client-actions {
      display: flex;
      gap: 10px;
    }

    .client-actions .btn {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .message-btn {
      background: rgba(96, 165, 250, 0.1);
      color: var(--info-blue);
    }

    .schedule-btn {
      background: rgba(52, 211, 153, 0.1);
      color: var(--success-green);
    }

    .client-details {
      width: 300px;
      background: var(--card-bg);
      border-radius: 10px;
      padding: 25px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .detail-placeholder {
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      color: var(--text-light);
    }

    .detail-placeholder i {
      font-size: 3rem;
      margin-bottom: 15px;
      opacity: 0.3;
    }

    /* Responsive styles */
    @media (max-width: 992px) {
      .clients-container {
        flex-direction: column;
      }

      .client-details {
        width: 100%;
      }
    }

    .stat-info h3 {
      font-size: 1.8rem;
      margin-bottom: 5px;
    }

    .stat-info p {
      color: var(--text-light);
      font-size: 0.95rem;
    }

    /* Agent Tools */
    .tools-container {
      background: var(--card-bg);
      border-radius: 10px;
      padding: 25px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
      margin-bottom: 30px;
    }

    .tools-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .tools-header h2 {
      font-size: 1.5rem;
      color: var(--text-dark);
    }

    .tools-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 20px;
    }

    .tool-card {
      background: #f8fafc;
      border-radius: 8px;
      padding: 20px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s;
      border: 1px solid var(--border-gray);
    }

    .tool-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
      border-color: var(--primary-teal);
    }

    .tool-icon {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: rgba(13, 148, 136, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 15px;
      font-size: 1.8rem;
      color: var(--primary-teal);
    }

    .tool-card h3 {
      font-size: 1.1rem;
      margin-bottom: 5px;
    }

    .tool-card p {
      color: var(--text-light);
      font-size: 0.85rem;
    }

    /* Listings Section */
    .dashboard-section {
      margin-bottom: 30px;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .section-header h2 {
      font-size: 1.5rem;
      color: var(--text-dark);
    }

    .view-all {
      color: var(--primary-teal);
      font-weight: 600;
      text-decoration: none;
    }

    .property-list {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 25px;
    }

    .property-card {
      background: var(--card-bg);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      transition: transform 0.3s;
    }

    .property-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
    }

    .property-image {
      height: 180px;
      background: linear-gradient(45deg, #0d9488, #0891b2);
      position: relative;
    }

    .property-status {
      position: absolute;
      top: 15px;
      right: 15px;
      background: white;
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .status-active {
      color: var(--success-green);
    }

    .status-pending {
      color: var(--secondary-coral);
    }

    .status-sold {
      color: var(--purple);
    }

    .status-rented {
      color: var(--info-blue);
    }

    .property-details {
      padding: 20px;
    }

    .property-details h3 {
      font-size: 1.2rem;
      margin-bottom: 10px;
      color: var(--text-dark);
    }

    .property-price {
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--primary-teal);
      margin-bottom: 15px;
    }

    .property-meta {
      display: flex;
      margin-bottom: 15px;
      color: var(--text-light);
      font-size: 0.9rem;
    }

    .property-meta span {
      margin-right: 15px;
      display: flex;
      align-items: center;
    }

    .property-meta i {
      margin-right: 5px;
    }

    .property-actions {
      display: flex;
      justify-content: space-between;
      padding-top: 15px;
      border-top: 1px solid var(--border-gray);
    }

    .property-actions button {
      padding: 8px 15px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s;
      border: none;
    }

    .edit-btn {
      background: rgba(13, 148, 136, 0.1);
      color: var(--primary-teal);
    }

    .edit-btn:hover {
      background: rgba(13, 148, 136, 0.2);
    }

    .insights-btn {
      background: rgba(167, 139, 250, 0.1);
      color: var(--purple);
    }

    .insights-btn:hover {
      background: rgba(167, 139, 250, 0.2);
    }

    /* Calendar Section */
    .calendar-container {
      background: var(--card-bg);
      border-radius: 10px;
      padding: 25px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .calendar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .calendar-header h3 {
      font-size: 1.3rem;
    }

    .calendar-controls {
      display: flex;
      gap: 10px;
    }

    .calendar-controls button {
      background: rgba(13, 148, 136, 0.1);
      color: var(--primary-teal);
      border: none;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 10px;
    }

    .calendar-day {
      text-align: center;
      font-weight: 600;
      padding: 10px 0;
      color: var(--text-light);
    }

    .calendar-date {
      height: 80px;
      border: 1px solid var(--border-gray);
      border-radius: 8px;
      padding: 8px;
      position: relative;
      cursor: pointer;
      transition: all 0.3s;
    }

    .calendar-date:hover {
      background: rgba(13, 148, 136, 0.05);
    }

    .calendar-date.active {
      border-color: var(--primary-teal);
      background: rgba(13, 148, 136, 0.1);
    }

    .calendar-date .date-num {
      font-size: 1.1rem;
      font-weight: 600;
    }

    .calendar-event {
      background: var(--accent-gold);
      color: #854d0e;
      font-size: 0.75rem;
      padding: 2px 5px;
      border-radius: 4px;
      margin-top: 5px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .calendar-event.viewing {
      background: rgba(96, 165, 250, 0.2);
      color: var(--info-blue);
    }

    .calendar-event.closing {
      background: rgba(52, 211, 153, 0.2);
      color: var(--success-green);
    }

    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      z-index: 1000;
      justify-content: center;
      align-items: center;
      overflow-y: auto;
    }

    .modal-content {
      background: white;
      border-radius: 10px;
      width: 90%;
      max-width: 600px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
      animation: modalFade 0.3s;
    }

    @keyframes modalFade {
      from {
        opacity: 0;
        transform: translateY(-50px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .modal-header {
      background: var(--primary-teal);
      color: white;
      padding: 20px;
      border-top-left-radius: 10px;
      border-top-right-radius: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-header h2 {
      margin: 0;
      font-size: 1.5rem;
    }

    .close-modal {
      background: none;
      border: none;
      color: white;
      font-size: 1.5rem;
      cursor: pointer;
    }

    .modal-body {
      padding: 25px;
      max-height: 70vh;
      overflow-y: auto;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--text-dark);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .form-control {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid var(--border-gray);
      border-radius: 6px;
      font-size: 1rem;
      transition: border-color 0.3s;
    }

    .form-control:focus {
      border-color: var(--primary-teal);
      outline: none;
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
    }

    .form-row {
      display: flex;
      gap: 15px;
      margin-bottom: 20px;
    }

    .form-row .form-group {
      flex: 1;
      margin-bottom: 0;
    }

    .modal-footer {
      padding: 20px;
      background: var(--neutral-bg);
      border-bottom-left-radius: 10px;
      border-bottom-right-radius: 10px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .btn {
      padding: 12px 25px;
      border-radius: 6px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      border: none;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: var(--primary-teal);
      color: white;
    }

    .btn-primary:hover {
      background: #0a7c72;
      transform: translateY(-2px);
      box-shadow: 0 4px 10px rgba(13, 148, 136, 0.25);
    }

    .btn-outline {
      background: transparent;
      border: 1px solid var(--border-gray);
      color: var(--text-light);
    }

    .btn-outline:hover {
      background: var(--neutral-bg);
      border-color: var(--primary-teal);
      color: var(--primary-teal);
    }

    /* Validation styles */
    .error-field {
      border: 2px solid var(--secondary-coral) !important;
      background-color: #fff5f5;
    }

    .error-message {
      color: var(--secondary-coral);
      font-size: 0.85rem;
      margin-top: 5px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .error-summary {
      background: #f8d7da;
      color: #721c24;
      padding: 15px;
      border-radius: 8px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Image preview styles */
    .image-preview-container {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 10px;
    }

    .image-preview {
      width: 80px;
      height: 80px;
      border-radius: 4px;
      background-size: cover;
      background-position: center;
      position: relative;
    }

    .remove-image {
      position: absolute;
      top: -5px;
      right: -5px;
      background: var(--secondary-coral);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      cursor: pointer;
    }

    .help-text {
      font-size: 0.85rem;
      color: var(--text-light);
      margin-top: 5px;
    }

    /* Pricing Tool Styles */
    .pricing-result {
      background: rgba(13, 148, 136, 0.05);
      border-radius: 8px;
      padding: 20px;
      margin-top: 20px;
      display: none;
    }

    .price-estimate {
      font-size: 1.8rem;
      font-weight: 700;
      color: var(--primary-teal);
      text-align: center;
      margin: 15px 0;
    }

    .price-range {
      display: flex;
      justify-content: space-between;
      margin-bottom: 15px;
    }

    .price-range .min,
    .price-range .max {
      color: var(--text-light);
    }

    .comps-container {
      margin-top: 20px;
    }

    .comp-card {
      background: white;
      border: 1px solid var(--border-gray);
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
    }

    .comp-img {
      width: 60px;
      height: 60px;
      border-radius: 6px;
      background: linear-gradient(45deg, #0d9488, #0891b2);
      margin-right: 15px;
    }

    .comp-info h4 {
      margin: 0 0 5px;
      font-size: 1.1rem;
    }

    .comp-price {
      color: var(--primary-teal);
      font-weight: 600;
    }

    /* Virtual Tour Styles */
    .tour-preview {
      height: 250px;
      background: #f1f5f9;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
    }

    .tour-preview i {
      font-size: 4rem;
      color: var(--primary-teal);
      opacity: 0.3;
    }

    /* eSign Styles */
    .document-list {
      max-height: 300px;
      overflow-y: auto;
    }

    .document-item {
      background: white;
      border: 1px solid var(--border-gray);
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .doc-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .doc-info i {
      font-size: 1.5rem;
      color: var(--secondary-coral);
    }

    .sign-btn {
      background: var(--success-green);
      color: white;
      border: none;
      padding: 8px 15px;
      border-radius: 6px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    /* Marketing Styles */
    .marketing-channels {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin: 15px 0;
    }

    .channel-option {
      flex: 1;
      min-width: 120px;
      background: #f8fafc;
      border: 1px solid var(--border-gray);
      border-radius: 8px;
      padding: 15px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s;
    }

    .channel-option.selected {
      border-color: var(--primary-teal);
      background: rgba(13, 148, 136, 0.05);
    }

    .channel-option i {
      font-size: 2rem;
      color: var(--primary-teal);
      margin-bottom: 10px;
    }

    /* AI Assistant Styles */
    .ai-chat {
      height: 350px;
      border: 1px solid var(--border-gray);
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 15px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 15px;
    }

    .message {
      max-width: 80%;
      padding: 12px 15px;
      border-radius: 18px;
      position: relative;
    }

    .user-message {
      background: rgba(13, 148, 136, 0.1);
      align-self: flex-end;
      border-bottom-right-radius: 4px;
    }

    .ai-message {
      background: #f1f5f9;
      align-self: flex-start;
      border-bottom-left-radius: 4px;
    }

    .ai-message .thinking {
      display: flex;
      align-items: center;
      gap: 8px;
      color: var(--text-light);
    }

    .chat-input {
      display: flex;
      gap: 10px;
    }

    .chat-input input {
      flex-grow: 1;
      padding: 12px 15px;
      border: 1px solid var(--border-gray);
      border-radius: 30px;
      font-size: 1rem;
    }

    .chat-input button {
      background: var(--primary-teal);
      color: white;
      border: none;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* Responsive styles */
    @media (max-width: 992px) {
      .sidebar {
        width: 220px;
      }

      .content {
        margin-left: 220px;
      }
    }

    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
        padding: 20px 10px;
        overflow: hidden;
      }

      .brand h1,
      .user-details,
      .nav-links span {
        display: none;
      }

      .brand {
        justify-content: center;
        padding: 0;
        border: none;
        margin-bottom: 30px;
      }

      .user-avatar {
        margin: 0 auto;
      }

      .content {
        margin-left: 70px;
        padding: 20px;
      }

      .stats-container {
        grid-template-columns: 1fr;
      }

      .tools-grid {
        grid-template-columns: 1fr 1fr;
      }

      .property-list {
        grid-template-columns: 1fr;
      }

      .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
      }

      .date-display {
        margin-top: 10px;
      }

      .form-row {
        flex-direction: column;
        gap: 20px;
      }

      .channel-option {
        min-width: 100px;
      }
    }

    @media (max-width: 480px) {
      .tools-grid {
        grid-template-columns: 1fr;
      }

      .channel-option {
        min-width: 100%;
      }
    }
  </style>
</head>

<body>
  <!-- Sidebar -->
  <div class="sidebar">
    <div class="brand" id="dashboard-logo">
      <i class="fas fa-home"></i>
      <h1>RealEstate AI</h1>
    </div>

    <div class="user-info">
      <div class="user-avatar">MR</div>
      <div class="user-details">
        <h3>Michael Reynolds</h3>
        <span>Top Agent</span>
      </div>
    </div>

    <div class="nav-links">
      <a href="#dashboard-header" class="active">
        <i class="fas fa-chart-line"></i>
        <span>Dashboard</span>
      </a>
      <a href="#listings-section">
        <i class="fas fa-home"></i>
        <span>Listings</span>
      </a>
      <a href="#">
        <i class="fas fa-users"></i>
        <span>Clients</span>
      </a>
      <a href="#">
        <i class="fas fa-calendar"></i>
        <span>Schedule</span>
      </a>
      <a href="#">
        <i class="fas fa-file-contract"></i>
        <span>Transactions</span>
      </a>
      <a href="#">
        <i class="fas fa-chart-bar"></i>
        <span>Market Insights</span>
      </a>
    </div>

    <button class="logout-btn">
      <i class="fas fa-sign-out-alt"></i>
      <span>Logout</span>
    </button>
  </div>

  <!-- Main Content -->
  <div class="content">
    <div class="dashboard-header" id="dashboard-header">
      <h1>Agent Dashboard</h1>
      <div class="date-display">
        <i class="far fa-calendar"></i>
        <span id="current-date"></span>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-container" id="stats-container">
      <div class="loading">
        <i class="fas fa-spinner"></i>
        <p>Loading dashboard metrics...</p>
      </div>
    </div>

    <!-- Agent Tools -->
    <div class="tools-container">
      <div class="tools-header">
        <h2>Agent Tools</h2>
      </div>

      <div class="tools-grid">
        <div class="tool-card" id="add-listing-tool">
          <div class="tool-icon">
            <i class="fas fa-plus"></i>
          </div>
          <h3>Add Listing</h3>
          <p>Create new property listing</p>
        </div>

        <div class="tool-card" id="pricing-tool">
          <div class="tool-icon">
            <i class="fas fa-chart-line"></i>
          </div>
          <h3>Pricing Tool</h3>
          <p>AI-powered pricing analysis</p>
        </div>

        <div class="tool-card" id="virtual-tour-tool">
          <div class="tool-icon">
            <i class="fas fa-camera"></i>
          </div>
          <h3>Virtual Tour</h3>
          <p>Create 3D property tours</p>
        </div>

        <div class="tool-card" id="esign-tool">
          <div class="tool-icon">
            <i class="fas fa-file-signature"></i>
          </div>
          <h3>eSign Documents</h3>
          <p>Digital document signing</p>
        </div>

        <div class="tool-card" id="marketing-tool">
          <div class="tool-icon">
            <i class="fas fa-bullhorn"></i>
          </div>
          <h3>Marketing</h3>
          <p>Promote your listings</p>
        </div>

        <div class="tool-card" id="ai-assistant-tool">
          <div class="tool-icon">
            <i class="fas fa-robot"></i>
          </div>
          <h3>AI Assistant</h3>
          <p>Get property insights</p>
        </div>
      </div>
    </div>

    <!-- Listings Section -->
    <div class="dashboard-section" id="listings-section">
      <div class="section-header">
        <h2>Recent Listings</h2>
        <a href="#" class="view-all">View All</a>
      </div>

      <div class="property-list" id="recent-listings">
        <div class="loading">
          <i class="fas fa-spinner"></i>
          <p>Loading recent listings...</p>
        </div>
      </div>
    </div>

    <!-- Calendar Section -->
    <div class="dashboard-section" id="calendar-section">
      <div class="section-header">
        <h2>Upcoming Schedule</h2>
        <a href="#calender-section" class="view-all">View Calendar</a>
      </div>

      <div class="calendar-container">
        <div class="calendar-header">
          <h3 id="calendar-month">July 2025</h3>
          <div class="calendar-controls">
            <button id="prev-month">
              <i class="fas fa-chevron-left"></i>
            </button>
            <button id="next-month">
              <i class="fas fa-chevron-right"></i>
            </button>
          </div>
        </div>

        <div class="calendar-grid" id="calendar-grid">
          <!-- Calendar will be populated dynamically -->
        </div>
      </div>
    </div>

    <div class="dashboard-section" id="clients-section">
      <div class="section-header">
        <h2>Active Clients</h2>
        <a href="#" class="view-all">View All</a>
      </div>

      <div class="clients-container">
        <div class="client-list">
          <div class="client-card">
            <div class="client-avatar">
              <span>JS</span>
            </div>
            <div class="client-info">
              <h3>John Smith</h3>
              <p class="client-phone"><i class="fas fa-phone"></i> (555) 123-4567</p>
              <p class="client-email"><i class="fas fa-envelope"></i> john.smith@example.com</p>
              <div class="client-properties">
                <span class="property-tag">Looking: 3-4 BD Homes</span>
                <span class="property-tag">Budget: $400-600K</span>
              </div>
            </div>
            <div class="client-actions">
              <button class="btn message-btn"><i class="fas fa-comment"></i></button>
              <button class="btn schedule-btn"><i class="fas fa-calendar"></i></button>
            </div>
          </div>

          <div class="client-card">
            <div class="client-avatar">
              <span>JD</span>
            </div>
            <div class="client-info">
              <h3>Jane Doe</h3>
              <p class="client-phone"><i class="fas fa-phone"></i> (555) 987-6543</p>
              <p class="client-email"><i class="fas fa-envelope"></i> jane.doe@example.com</p>
              <div class="client-properties">
                <span class="property-tag">Looking: Luxury Condos</span>
                <span class="property-tag">Budget: $800K-1.2M</span>
              </div>
            </div>
            <div class="client-actions">
              <button class="btn message-btn"><i class="fas fa-comment"></i></button>
              <button class="btn schedule-btn"><i class="fas fa-calendar"></i></button>
            </div>
          </div>

          <div class="client-card">
            <div class="client-avatar">
              <span>MJ</span>
            </div>
            <div class="client-info">
              <h3>Michael Johnson</h3>
              <p class="client-phone"><i class="fas fa-phone"></i> (555) 456-7890</p>
              <p class="client-email"><i class="fas fa-envelope"></i> mj@business.com</p>
              <div class="client-properties">
                <span class="property-tag">Looking: Commercial Space</span>
                <span class="property-tag">Budget: $2-3M</span>
              </div>
            </div>
            <div class="client-actions">
              <button class="btn message-btn"><i class="fas fa-comment"></i></button>
              <button class="btn schedule-btn"><i class="fas fa-calendar"></i></button>
            </div>
          </div>

          <div class="client-card">
            <div class="client-avatar">
              <span>SD</span>
            </div>
            <div class="client-info">
              <h3>Sarah Davis</h3>
              <p class="client-phone"><i class="fas fa-phone"></i> (555) 234-5678</p>
              <p class="client-email"><i class="fas fa-envelope"></i> sarahd@example.org</p>
              <div class="client-properties">
                <span class="property-tag">Looking: Vacation Homes</span>
                <span class="property-tag">Budget: $300-500K</span>
              </div>
            </div>
            <div class="client-actions">
              <button class="btn message-btn"><i class="fas fa-comment"></i></button>
              <button class="btn schedule-btn"><i class="fas fa-calendar"></i></button>
            </div>
          </div>
        </div>

        <div class="client-details">
          <h3>Client Details</h3>
          <div class="detail-placeholder">
            <i class="fas fa-user-friends"></i>
            <p>Select a client to view details</p>
          </div>
        </div>
      </div>
    </div>

    <div class="dashboard-section" id="transactions-section">
      <div class="section-header">
        <h2>Transaction Records</h2>
        <div class="transaction-filters">
          <div class="filter-group">
            <label for="transaction-status"><i class="fas fa-filter"></i> Status:</label>
            <select id="transaction-status">
              <option value="all">All Transactions</option>
              <option value="pending">Pending</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="filter-group">
            <label for="transaction-date"><i class="fas fa-calendar"></i> Date:</label>
            <select id="transaction-date">
              <option value="all">All Dates</option>
              <option value="week">Past Week</option>
              <option value="month">Past Month</option>
              <option value="quarter">Past Quarter</option>
            </select>
          </div>
          <div class="filter-group">
            <button class="btn btn-primary"><i class="fas fa-plus"></i> New Transaction</button>
          </div>
        </div>
      </div>

      <div class="transactions-container">
        <div class="transactions-summary">
          <div class="summary-card">
            <div class="summary-icon">
              <i class="fas fa-handshake"></i>
            </div>
            <div class="summary-info">
              <h3>12</h3>
              <p>Total Transactions</p>
            </div>
          </div>
          <div class="summary-card">
            <div class="summary-icon">
              <i class="fas fa-check-circle"></i>
            </div>
            <div class="summary-info">
              <h3>8</h3>
              <p>Completed</p>
            </div>
          </div>
          <div class="summary-card">
            <div class="summary-icon">
              <i class="fas fa-clock"></i>
            </div>
            <div class="summary-info">
              <h3>3</h3>
              <p>Pending</p>
            </div>
          </div>
          <div class="summary-card">
            <div class="summary-icon">
              <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="summary-info">
              <h3>$127,500</h3>
              <p>Total Commission</p>
            </div>
          </div>
        </div>

        <div class="transactions-table">
          <table>
            <thead>
              <tr>
                <th>Property</th>
                <th>Client</th>
                <th>Date</th>
                <th>Status</th>
                <th>Commission</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <div class="property-info">
                    <div class="property-thumb" style="background-image: url('placeholder.jpg');"></div>
                    <div>
                      <h4>123 Main St</h4>
                      <p>$425,000</p>
                    </div>
                  </div>
                </td>
                <td>John Smith</td>
                <td>Jul 10, 2025</td>
                <td><span class="status-badge status-completed">Completed</span></td>
                <td>$12,750</td>
                <td>
                  <button class="btn view-details-btn"><i class="fas fa-eye"></i></button>
                  <button class="btn download-btn"><i class="fas fa-download"></i></button>
                </td>
              </tr>
              <tr>
                <td>
                  <div class="property-info">
                    <div class="property-thumb" style="background-image: url('placeholder.jpg');"></div>
                    <div>
                      <h4>456 Park Ave</h4>
                      <p>$650,000</p>
                    </div>
                  </div>
                </td>
                <td>Jane Doe</td>
                <td>Jul 5, 2025</td>
                <td><span class="status-badge status-completed">Completed</span></td>
                <td>$19,500</td>
                <td>
                  <button class="btn view-details-btn"><i class="fas fa-eye"></i></button>
                  <button class="btn download-btn"><i class="fas fa-download"></i></button>
                </td>
              </tr>
              <tr>
                <td>
                  <div class="property-info">
                    <div class="property-thumb" style="background-image: url('placeholder.jpg');"></div>
                    <div>
                      <h4>789 Oak St</h4>
                      <p>$325,000</p>
                    </div>
                  </div>
                </td>
                <td>Michael Johnson</td>
                <td>Jun 28, 2025</td>
                <td><span class="status-badge status-pending">Pending</span></td>
                <td>$9,750</td>
                <td>
                  <button class="btn view-details-btn"><i class="fas fa-eye"></i></button>
                  <button class="btn download-btn"><i class="fas fa-download"></i></button>
                </td>
              </tr>
              <tr>
                <td>
                  <div class="property-info">
                    <div class="property-thumb" style="background-image: url('placeholder.jpg');"></div>
                    <div>
                      <h4>101 Pine Rd</h4>
                      <p>$550,000</p>
                    </div>
                  </div>
                </td>
                <td>Sarah Davis</td>
                <td>Jun 20, 2025</td>
                <td><span class="status-badge status-completed">Completed</span></td>
                <td>$16,500</td>
                <td>
                  <button class="btn view-details-btn"><i class="fas fa-eye"></i></button>
                  <button class="btn download-btn"><i class="fas fa-download"></i></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Add Listing Modal -->
  <div id="addListingModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-plus-circle"></i> Add New Property Listing</h2>
        <button class="close-modal">&times;</button>
      </div>
      <form id="addListingForm" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="form-group">
            <label for="listingTitle"><i class="fas fa-heading"></i> Property Title*</label>
            <input type="text" id="listingTitle" name="title" class="form-control" required
              placeholder="Modern Downtown Apartment" />
          </div>

          <div class="form-group">
            <label for="listingPrice"><i class="fas fa-dollar-sign"></i> Price ($)*</label>
            <input type="number" id="listingPrice" name="price" class="form-control" required min="1000"
              placeholder="425000" />
          </div>

          <!-- Property Type -->
          <div class="form-group">
            <label for="listingType"><i class="fas fa-building"></i> Property Type*</label>
            <select id="listingType" name="property_type" class="form-control" required>
              <option value="">Select property type</option>
              <option value="house">House</option>
              <option value="apartment">Apartment</option>
              <option value="condo">Condo</option>
              <option value="townhouse">Townhouse</option>
              <option value="land">Land</option>
              <option value="commercial">Commercial</option>
            </select>
          </div>

          <!-- Property Status -->
          <div class="form-group">
            <label for="listingStatus"><i class="fas fa-tag"></i> Status*</label>
            <select id="listingStatus" name="status" class="form-control" required>
              <option value="available">Available</option>
              <option value="pending">Pending</option>
              <option value="sold">Sold</option>
              <option value="rented">Rented</option>
            </select>
          </div>

          <!-- Address Fields -->
          <div class="form-group">
            <label for="listingStreet"><i class="fas fa-map-marker-alt"></i> Street Address*</label>
            <input type="text" id="listingStreet" name="street_address" class="form-control" required
              placeholder="123 Main St" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>City*</label>
              <input type="text" id="listingCity" name="city" class="form-control" required placeholder="New York" />
            </div>
            <div class="form-group">
              <label>State*</label>
              <input type="text" id="listingState" name="state" class="form-control" required placeholder="NY" />
            </div>
            <div class="form-group">
              <label>ZIP Code*</label>
              <input type="text" id="listingZip" name="zip_code" class="form-control" required placeholder="10001" />
            </div>
          </div>

          <!-- Hidden country field with default value -->
          <div class="form-group" style="display: none">
            <label>Country</label>
            <input type="text" id="listingCountry" name="country" class="form-control" value="USA" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="listingBedrooms"><i class="fas fa-bed"></i> Bedrooms*</label>
              <input type="number" id="listingBedrooms" name="bedrooms" class="form-control" required min="0" max="20"
                placeholder="2" />
            </div>

            <div class="form-group">
              <label for="listingBathrooms"><i class="fas fa-bath"></i> Bathrooms*</label>
              <input type="number" id="listingBathrooms" name="bathrooms" class="form-control" required min="0" max="20"
                step="0.5" placeholder="2.5" />
            </div>

            <div class="form-group">
              <label for="listingSqft"><i class="fas fa-ruler-combined"></i> Square Feet*</label>
              <input type="number" id="listingSqft" name="square_feet" class="form-control" required min="100"
                max="100000" placeholder="1250" />
            </div>
          </div>

          <!-- Lot Size and Year Built -->
          <div class="form-row">
            <div class="form-group">
              <label for="listingLotSize"><i class="fas fa-vector-square"></i> Lot Size (sq ft)</label>
              <input type="number" id="listingLotSize" name="lot_size" class="form-control" min="0" step="0.01"
                placeholder="0.00" />
            </div>
            <div class="form-group">
              <label for="listingYearBuilt"><i class="fas fa-calendar-alt"></i> Year Built</label>
              <input type="number" id="listingYearBuilt" name="year_built" class="form-control" min="1800" max="2025"
                placeholder="Year" />
            </div>
          </div>

          <div class="form-group">
            <label for="listingDescription"><i class="fas fa-align-left"></i> Description</label>
            <textarea id="listingDescription" name="description" class="form-control" rows="4"
              placeholder="Describe your property..."></textarea>
          </div>

          <div class="form-group">
            <label for="listingImages"><i class="fas fa-images"></i> Property Images</label>
            <input type="file" id="listingImages" name="images[]" class="form-control" multiple accept="image/*" />
            <p class="help-text">First image will be used as thumbnail</p>
            <div id="image-preview-container" class="image-preview-container"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline close-modal">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-plus-circle"></i> Add Listing
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Listing Modal -->
  <div id="editListingModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-edit"></i> Edit Property Listing</h2>
        <button class="close-modal">&times;</button>
      </div>
      <form id="editListingForm" enctype="multipart/form-data">
        <input type="hidden" id="editListingId" name="id" />
        <div class="modal-body">
          <div class="form-group">
            <label for="editListingTitle"><i class="fas fa-heading"></i> Property Title*</label>
            <input type="text" id="editListingTitle" name="title" class="form-control" required
              placeholder="Modern Downtown Apartment" />
          </div>

          <div class="form-group">
            <label for="editListingPrice"><i class="fas fa-dollar-sign"></i> Price ($)*</label>
            <input type="number" id="editListingPrice" name="price" class="form-control" required min="1000"
              placeholder="425000" />
          </div>

          <!-- Property Type -->
          <div class="form-group">
            <label for="editListingType"><i class="fas fa-building"></i> Property Type*</label>
            <select id="editListingType" name="property_type" class="form-control" required>
              <option value="">Select property type</option>
              <option value="house">House</option>
              <option value="apartment">Apartment</option>
              <option value="condo">Condo</option>
              <option value="townhouse">Townhouse</option>
              <option value="land">Land</option>
              <option value="commercial">Commercial</option>
            </select>
          </div>

          <!-- Property Status -->
          <div class="form-group">
            <label for="editListingStatus"><i class="fas fa-tag"></i> Status*</label>
            <select id="editListingStatus" name="status" class="form-control" required>
              <option value="available">Available</option>
              <option value="pending">Pending</option>
              <option value="sold">Sold</option>
              <option value="rented">Rented</option>
            </select>
          </div>

          <!-- Address Fields -->
          <div class="form-group">
            <label for="editListingStreet"><i class="fas fa-map-marker-alt"></i> Street Address*</label>
            <input type="text" id="editListingStreet" name="street_address" class="form-control" required
              placeholder="123 Main St" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>City*</label>
              <input type="text" id="editListingCity" name="city" class="form-control" required
                placeholder="New York" />
            </div>
            <div class="form-group">
              <label>State*</label>
              <input type="text" id="editListingState" name="state" class="form-control" required placeholder="NY" />
            </div>
            <div class="form-group">
              <label>ZIP Code*</label>
              <input type="text" id="editListingZip" name="zip_code" class="form-control" required
                placeholder="10001" />
            </div>
          </div>

          <!-- Hidden country field with default value -->
          <div class="form-group" style="display: none">
            <label>Country</label>
            <input type="text" id="editListingCountry" name="country" class="form-control" value="USA" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="editListingBedrooms"><i class="fas fa-bed"></i> Bedrooms*</label>
              <input type="number" id="editListingBedrooms" name="bedrooms" class="form-control" required min="0"
                max="20" placeholder="2" />
            </div>

            <div class="form-group">
              <label for="editListingBathrooms"><i class="fas fa-bath"></i> Bathrooms*</label>
              <input type="number" id="editListingBathrooms" name="bathrooms" class="form-control" required min="0"
                max="20" step="0.5" placeholder="2.5" />
            </div>

            <div class="form-group">
              <label for="editListingSqft"><i class="fas fa-ruler-combined"></i> Square Feet*</label>
              <input type="number" id="editListingSqft" name="square_feet" class="form-control" required min="100"
                max="100000" placeholder="1250" />
            </div>
          </div>

          <!-- Lot Size and Year Built -->
          <div class="form-row">
            <div class="form-group">
              <label for="editListingLotSize"><i class="fas fa-vector-square"></i> Lot Size (sq ft)</label>
              <input type="number" id="editListingLotSize" name="lot_size" class="form-control" min="0" step="0.01"
                placeholder="0.00" />
            </div>
            <div class="form-group">
              <label for="editListingYearBuilt"><i class="fas fa-calendar-alt"></i> Year Built</label>
              <input type="number" id="editListingYearBuilt" name="year_built" class="form-control" min="1800"
                max="2025" placeholder="Year" />
            </div>
          </div>

          <div class="form-group">
            <label for="editListingDescription"><i class="fas fa-align-left"></i> Description</label>
            <textarea id="editListingDescription" name="description" class="form-control" rows="4"
              placeholder="Describe your property..."></textarea>
          </div>

          <div class="form-group">
            <label><i class="fas fa-images"></i> Existing Images</label>
            <div id="existing-images-container" class="image-preview-container"></div>
          </div>

          <div class="form-group">
            <label for="editListingImages"><i class="fas fa-images"></i> Add More Images</label>
            <input type="file" id="editListingImages" name="images[]" class="form-control" multiple accept="image/*" />
            <p class="help-text">Select additional images to upload</p>
            <div id="edit-image-preview-container" class="image-preview-container"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline close-modal">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Update Listing
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Pricing Tool Modal -->
  <div id="pricingModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-chart-line"></i> AI Pricing Analysis</h2>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label for="propertyAddress"><i class="fas fa-map-marker-alt"></i> Property Address*</label>
          <input type="text" id="propertyAddress" class="form-control" required placeholder="Enter property address" />
        </div>

        <div class="form-group">
          <label for="propertyType"><i class="fas fa-home"></i> Property Type*</label>
          <select id="propertyType" class="form-control" required>
            <option value="">Select property type</option>
            <option value="single-family">Single Family Home</option>
            <option value="condo">Condo</option>
            <option value="townhouse">Townhouse</option>
            <option value="multi-family">Multi-Family</option>
            <option value="commercial">Commercial</option>
          </select>
        </div>

        <button id="analyze-price" class="btn btn-primary" style="width: 100%">
          <i class="fas fa-bolt"></i> Get Price Estimate
        </button>

        <div id="pricing-result" class="pricing-result">
          <h3><i class="fas fa-lightbulb"></i> AI Price Recommendation</h3>
          <div class="price-estimate">$475,000 - $525,000</div>
          <div class="price-range">
            <span class="min">$450,000</span>
            <span class="max">$550,000</span>
          </div>
          <div class="progress" style="
                height: 10px;
                background: #e5e7eb;
                border-radius: 5px;
                overflow: hidden;
              ">
            <div style="
                  width: 70%;
                  height: 100%;
                  background: var(--primary-teal);
                "></div>
          </div>

          <div class="comps-container">
            <h4><i class="fas fa-home"></i> Comparable Properties</h4>
            <div class="comp-card">
              <div class="comp-img"></div>
              <div class="comp-info">
                <h4>123 Oak Street</h4>
                <div class="comp-price">$485,000</div>
              </div>
            </div>
            <div class="comp-card">
              <div class="comp-img"></div>
              <div class="comp-info">
                <h4>456 Pine Avenue</h4>
                <div class="comp-price">$510,000</div>
              </div>
            </div>
            <div class="comp-card">
              <div class="comp-img"></div>
              <div class="comp-info">
                <h4>789 Maple Road</h4>
                <div class="comp-price">$498,500</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline close-modal">
          <i class="fas fa-times"></i> Close
        </button>
      </div>
    </div>
  </div>

  <!-- Virtual Tour Modal -->
  <div id="virtualTourModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-camera"></i> Create Virtual Tour</h2>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label for="tourProperty"><i class="fas fa-home"></i> Select Property*</label>
          <select id="tourProperty" class="form-control" required>
            <option value="">Select a property</option>
            <option value="123-main">123 Main St</option>
            <option value="456-park">456 Park Ave</option>
            <option value="789-oak">789 Oak St</option>
          </select>
        </div>

        <div class="form-group">
          <label for="tourImages"><i class="fas fa-images"></i> Upload Photos/Videos*</label>
          <input type="file" id="tourImages" class="form-control" multiple accept="image/*,video/*" />
          <p class="help-text">Upload at least 5 photos for best results</p>
        </div>

        <div class="tour-preview">
          <i class="fas fa-home"></i>
        </div>

        <div class="form-group">
          <label for="tourTitle"><i class="fas fa-heading"></i> Tour Title</label>
          <input type="text" id="tourTitle" class="form-control" placeholder="Virtual Tour of Luxury Home" />
        </div>

        <button id="generate-tour" class="btn btn-primary" style="width: 100%">
          <i class="fas fa-magic"></i> Generate Virtual Tour
        </button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline close-modal">
          <i class="fas fa-times"></i> Cancel
        </button>
      </div>
    </div>
  </div>

  <!-- eSign Documents Modal -->
  <div id="esignModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-file-signature"></i> eSign Documents</h2>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <h3>
          <i class="fas fa-file-contract"></i> Documents Awaiting Signature
        </h3>
        <p class="help-text">
          Click "Sign" to electronically sign each document
        </p>

        <div class="document-list">
          <div class="document-item">
            <div class="doc-info">
              <i class="fas fa-file-pdf"></i>
              <div>
                <h4>Purchase Agreement</h4>
                <p>123 Main St - Buyer: John Smith</p>
              </div>
            </div>
            <button class="sign-btn">
              <i class="fas fa-signature"></i> Sign
            </button>
          </div>

          <div class="document-item">
            <div class="doc-info">
              <i class="fas fa-file-pdf"></i>
              <div>
                <h4>Disclosure Statement</h4>
                <p>456 Park Ave - Seller: Jane Doe</p>
              </div>
            </div>
            <button class="sign-btn">
              <i class="fas fa-signature"></i> Sign
            </button>
          </div>

          <div class="document-item">
            <div class="doc-info">
              <i class="fas fa-file-pdf"></i>
              <div>
                <h4>Commission Agreement</h4>
                <p>789 Oak St - Client: Johnson Family</p>
              </div>
            </div>
            <button class="sign-btn">
              <i class="fas fa-signature"></i> Sign
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline close-modal">
          <i class="fas fa-times"></i> Close
        </button>
      </div>
    </div>
  </div>

  <!-- Marketing Modal -->
  <div id="marketingModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-bullhorn"></i> Marketing Campaign</h2>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label for="marketingProperty"><i class="fas fa-home"></i> Select Property*</label>
          <select id="marketingProperty" class="form-control" required>
            <option value="">Select a property to market</option>
            <option value="123-main">123 Main St - $425,000</option>
            <option value="456-park">456 Park Ave - $650,000</option>
            <option value="789-oak">789 Oak St - $325,000</option>
          </select>
        </div>

        <div class="form-group">
          <label><i class="fas fa-share-alt"></i> Marketing Channels*</label>
          <p class="help-text">
            Select channels to distribute your marketing
          </p>
          <div class="marketing-channels">
            <div class="channel-option">
              <i class="fab fa-facebook"></i>
              <h4>Facebook</h4>
            </div>
            <div class="channel-option">
              <i class="fab fa-instagram"></i>
              <h4>Instagram</h4>
            </div>
            <div class="channel-option">
              <i class="fas fa-envelope"></i>
              <h4>Email</h4>
            </div>
            <div class="channel-option">
              <i class="fas fa-print"></i>
              <h4>Print</h4>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label for="campaignMessage"><i class="fas fa-comment-alt"></i> Campaign Message</label>
          <textarea id="campaignMessage" class="form-control" rows="4"
            placeholder="Customize your marketing message...">
Beautiful 3-bedroom home in prime location. Open house this weekend!</textarea>
        </div>

        <button id="launch-campaign" class="btn btn-primary" style="width: 100%">
          <i class="fas fa-paper-plane"></i> Launch Marketing Campaign
        </button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline close-modal">
          <i class="fas fa-times"></i> Cancel
        </button>
      </div>
    </div>
  </div>

  <!-- AI Assistant Modal -->
  <div id="aiAssistantModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-robot"></i> AI Assistant</h2>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="ai-chat" id="ai-chat">
          <div class="message ai-message">
            <p>
              Hello Michael! I'm your AI real estate assistant. How can I help
              you today?
            </p>
            <p>You can ask me about:</p>
            <ul>
              <li>Property valuations</li>
              <li>Market trends</li>
              <li>Listing improvements</li>
              <li>Client communication tips</li>
            </ul>
          </div>
        </div>
        <div class="chat-input">
          <input type="text" id="chat-input" placeholder="Ask me anything about real estate..." />
          <button id="send-message">
            <i class="fas fa-paper-plane"></i>
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline close-modal">
          <i class="fas fa-times"></i> Close
        </button>
      </div>
    </div>
  </div>

  <script>
    // Configuration
    //const AGENT_ID = <?= $agent_id ?>;
    const API_BASE = window.location.href;

    // DOM Elements
    const statsContainer = document.getElementById("stats-container");
    const listingsContainer = document.getElementById("recent-listings");
    const currentDateEl = document.getElementById("current-date");
    const calendarMonthEl = document.getElementById("calendar-month");
    const calendarGrid = document.getElementById("calendar-grid");
    const addListingModal = document.getElementById("addListingModal");
    const addListingForm = document.getElementById("addListingForm");
    const imagePreviewContainer = document.getElementById(
      "image-preview-container"
    );
    const editListingModal = document.getElementById("editListingModal");
    const editListingForm = document.getElementById("editListingForm");
    const editImagePreviewContainer = document.getElementById(
      "edit-image-preview-container"
    );
    const existingImagesContainer = document.getElementById(
      "existing-images-container"
    );

    // Tool modals
    const pricingModal = document.getElementById("pricingModal");
    const virtualTourModal = document.getElementById("virtualTourModal");
    const esignModal = document.getElementById("esignModal");
    const marketingModal = document.getElementById("marketingModal");
    const aiAssistantModal = document.getElementById("aiAssistantModal");

    // Pricing tool elements
    const analyzePriceBtn = document.getElementById("analyze-price");
    const pricingResult = document.getElementById("pricing-result");

    // AI Assistant elements
    const chatInput = document.getElementById("chat-input");
    const sendMessageBtn = document.getElementById("send-message");
    const aiChat = document.getElementById("ai-chat");

    // Marketing elements
    const channelOptions = document.querySelectorAll(".channel-option");
    const launchCampaignBtn = document.getElementById("launch-campaign");

    // Set current date
    function setCurrentDate() {
      const now = new Date();
      const options = {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
      };
      currentDateEl.textContent = now.toLocaleDateString("en-US", options);
    }

    // Fetch dashboard metrics
    async function fetchMetrics() {
      try {
        const response = await fetch(`${API_BASE}?endpoint=metrics`);
        if (!response.ok) throw new Error("Network response was not ok");
        return await response.json();
      } catch (error) {
        console.error("Error fetching metrics:", error);
        return null;
      }
    }

    // Fetch recent listings
    async function fetchListings() {
      try {
        const response = await fetch(`${API_BASE}?endpoint=listings`);
        if (!response.ok) throw new Error("Network response was not ok");
        return await response.json();
      } catch (error) {
        console.error("Error fetching listings:", error);
        return null;
      }
    }

    // Render metrics
    function renderMetrics(metrics) {
      if (!metrics) {
        return '<div class="error">Failed to load metrics. Please try again.</div>';
      }

      const commission = metrics.commissionEarned || 0;
      const rating = metrics.avgRating || 0;

      return `
        <div class="stat-card">
          <div class="stat-icon listings">
            <i class="fas fa-home"></i>
          </div>
          <div class="stat-info">
            <h3>${metrics.activeListings || 0}</h3>
            <p>Active Listings</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon clients">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-info">
            <h3>${metrics.activeClients || 0}</h3>
            <p>Active Clients</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon commission">
            <i class="fas fa-dollar-sign"></i>
          </div>
          <div class="stat-info">
            <h3>$${commission.toLocaleString()}</h3>
            <p>Commission Earned</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon rating">
            <i class="fas fa-star"></i>
          </div>
          <div class="stat-info">
            <h3>${rating.toFixed(1)}</h3>
            <p>Average Rating</p>
          </div>
        </div>
      `;
    }

    // Render recent listings
    function renderListings(listings) {
      if (!listings || listings.length === 0) {
        return '<div class="error">No recent listings found</div>';
      }

      const statusClassMap = {
        available: "status-active",
        pending: "status-pending",
        sold: "status-sold",
        rented: "status-rented",
      };

      const statusTextMap = {
        available: "Active",
        pending: "Pending",
        sold: "Sold",
        rented: "Rented",
      };

      return listings
        .map((listing) => {
          const status = listing.status || "available";
          const statusClass = statusClassMap[status] || "status-active";
          const statusText = statusTextMap[status] || "Active";

          // Handle thumbnail path
          let thumbnailStyle = "";
          if (listing.thumbnail) {
            thumbnailStyle = `background: url('${listing.thumbnail}') center/cover;`;
          } else {
            thumbnailStyle = "background: linear-gradient(45deg, #0d9488, #0891b2);";
          }

          return `
          <div class="property-card" data-id="${listing.id}">
            <div class="property-image" style="${thumbnailStyle}">
              <div class="property-status ${statusClass}">
                ${statusText}
              </div>
            </div>
            <div class="property-details">
              <h3>${escapeHTML(listing.title || "Property Listing")}</h3>
              <div class="property-price">$${(
              listing.price || 0
            ).toLocaleString()}</div>
              <div class="property-meta">
                <span><i class="fas fa-bed"></i> ${listing.bedrooms || 0} Beds</span>
                <span><i class="fas fa-bath"></i> ${listing.bathrooms || 0} Baths</span>
                <span><i class="fas fa-ruler-combined"></i> ${(
              listing.square_feet || 0
            ).toLocaleString()} sqft</span>
              </div>
              <p class="property-address">${escapeHTML(
              listing.address || "Address not available"
            )}</p>
              <div class="property-actions">
                <button class="edit-btn" data-id="${listing.id}">
                  <i class="fas fa-edit"></i> Edit
                </button>
                <button class="insights-btn" data-id="${listing.id}">
                  <i class="fas fa-chart-line"></i> Insights
                </button>
              </div>
            </div>
          </div>
        `;
        })
        .join("");
    }

    // Render calendar
    function renderCalendar() {
      const now = new Date();
      const year = now.getFullYear();
      const month = now.getMonth();
      const monthNames = [
        "January",
        "February",
        "March",
        "April",
        "May",
        "June",
        "July",
        "August",
        "September",
        "October",
        "November",
        "December",
      ];

      // Set calendar month header
      calendarMonthEl.textContent = `${monthNames[month]} ${year}`;

      // Get first day of month
      const firstDay = new Date(year, month, 1).getDay();
      // Get days in month
      const daysInMonth = new Date(year, month + 1, 0).getDate();

      let calendarHTML = `
        <div class="calendar-day">Sun</div>
        <div class="calendar-day">Mon</div>
        <div class="calendar-day">Tue</div>
        <div class="calendar-day">Wed</div>
        <div class="calendar-day">Thu</div>
        <div class="calendar-day">Fri</div>
        <div class="calendar-day">Sat</div>
      `;

      // Fill empty days
      for (let i = 0; i < firstDay; i++) {
        calendarHTML += `<div class="calendar-date"></div>`;
      }

      // Fill calendar days
      for (let day = 1; day <= daysInMonth; day++) {
        const isToday = day === now.getDate() && month === now.getMonth();
        const dateClass = isToday ? "active" : "";

        calendarHTML += `
          <div class="calendar-date ${dateClass}">
            <div class="date-num">${day}</div>
            ${day === 2
            ? `
              <div class="calendar-event">Open House</div>
              <div class="calendar-event viewing">Client Tour</div>
            `
            : ""
          }
            ${day === 10
            ? `
              <div class="calendar-event closing">Closing</div>
            `
            : ""
          }
            ${day === 25
            ? `
              <div class="calendar-event">Team Meeting</div>
            `
            : ""
          }
          </div>
        `;
      }

      calendarGrid.innerHTML = calendarHTML;
    }

    // Simple XSS protection
    function escapeHTML(str) {
      return str.replace(
        /[&<>"']/g,
        (tag) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        }[tag])
      );
    }

    // Function to open modal
    function openAddListingModal() {
      addListingModal.style.display = "flex";
      document.body.style.overflow = "hidden"; // Prevent background scrolling
    }

    // Function to open pricing modal
    function openPricingModal() {
      pricingModal.style.display = "flex";
      document.body.style.overflow = "hidden";
    }

    // Function to open virtual tour modal
    function openVirtualTourModal() {
      virtualTourModal.style.display = "flex";
      document.body.style.overflow = "hidden";
    }

    // Function to open eSign modal
    function openEsignModal() {
      esignModal.style.display = "flex";
      document.body.style.overflow = "hidden";
    }

    // Function to open marketing modal
    function openMarketingModal() {
      marketingModal.style.display = "flex";
      document.body.style.overflow = "hidden";
    }

    // Function to open AI assistant modal
    function openAIAssistantModal() {
      aiAssistantModal.style.display = "flex";
      document.body.style.overflow = "hidden";
    }

    // Function to open edit modal and load property data
    async function openEditListingModal(propertyId) {
      try {
        const response = await fetch(
          `${API_BASE}?endpoint=property&id=${propertyId}`
        );
        if (!response.ok) throw new Error("Failed to fetch property");
        const data = await response.json();

        const property = data.property;
        const images = data.images;

        // Populate form
        document.getElementById("editListingId").value = property.id;
        document.getElementById("editListingTitle").value = property.title;
        document.getElementById("editListingPrice").value = property.price;
        document.getElementById("editListingType").value =
          property.property_type;
        document.getElementById("editListingStatus").value = property.status;
        document.getElementById("editListingStreet").value =
          property.street || "";
        document.getElementById("editListingCity").value =
          property.city || "";
        document.getElementById("editListingState").value =
          property.state || "";
        document.getElementById("editListingZip").value =
          property.zip_code || "";
        document.getElementById("editListingBedrooms").value =
          property.bedrooms;
        document.getElementById("editListingBathrooms").value =
          property.bathrooms;
        document.getElementById("editListingSqft").value =
          property.square_feet;
        document.getElementById("editListingDescription").value =
          property.description;
        document.getElementById("editListingLotSize").value =
          property.lot_size || "";
        document.getElementById("editListingYearBuilt").value =
          property.year_built || "";

        // Display existing images
        existingImagesContainer.innerHTML = "";
        images.forEach((image) => {
          const imgElement = document.createElement("div");
          imgElement.className = "image-preview";
          imgElement.style.backgroundImage = `url('uploads/${image.image_path}')`;
          imgElement.dataset.imageId = image.id;

          const removeBtn = document.createElement("div");
          removeBtn.className = "remove-image";
          removeBtn.innerHTML = "&times;";
          removeBtn.addEventListener("click", async function (e) {
            e.stopPropagation();
            if (confirm("Are you sure you want to delete this image?")) {
              try {
                // Remove from UI
                imgElement.remove();
                alert(
                  "Image removed from UI. In a real app, this would delete from the server."
                );
              } catch (error) {
                console.error("Error deleting image:", error);
                alert("Failed to delete image");
              }
            }
          });

          imgElement.appendChild(removeBtn);
          existingImagesContainer.appendChild(imgElement);
        });

        // Clear image previews
        editImagePreviewContainer.innerHTML = "";

        // Show modal
        editListingModal.style.display = "flex";
        document.body.style.overflow = "hidden";
      } catch (error) {
        console.error("Error opening edit modal:", error);
        alert("Failed to load property details");
      }
    }

    // Function to close modal
    function closeAddListingModal() {
      addListingModal.style.display = "none";
      document.body.style.overflow = ""; // Re-enable scrolling

      // Clear any messages
      const messages = addListingForm.querySelector(".modal-body > div");
      if (messages) messages.remove();

      // Clear image previews
      imagePreviewContainer.innerHTML = "";
    }

    // Function to close any modal
    function closeAllModals() {
      const modals = document.querySelectorAll(".modal");
      modals.forEach((modal) => {
        modal.style.display = "none";
      });
      document.body.style.overflow = "";
    }

    // Function to refresh listings
    async function refreshListings() {
      const listings = await fetchListings();
      listingsContainer.innerHTML = renderListings(listings);
      addEventListeners();
    }

    // Validate add listing form
    function validateAddListingForm() {
      const requiredFields = [
        { name: "title", type: "string" },
        { name: "price", type: "number" },
        { name: "property_type", type: "string" },
        { name: "status", type: "string" },
        { name: "street", type: "string" },
        { name: "city", type: "string" },
        { name: "state", type: "string" },
        { name: "zip_code", type: "string" },
        { name: "bedrooms", type: "number" },
        { name: "bathrooms", type: "number" },
        { name: "square_feet", type: "number" },
      ];

      let isValid = true;
      const errors = [];

      // Reset UI errors
      document.querySelectorAll(".error-field").forEach((el) => {
        el.classList.remove("error-field");
      });
      document.querySelectorAll(".error-message").forEach((el) => {
        el.remove();
      });

      // Validate each field
      requiredFields.forEach((field) => {
        const input = document.querySelector(`[name="${field.name}"]`);
        if (!input) return;

        const value = input.value.trim();
        let fieldError = "";

        if (!value) {
          fieldError = "This field is required";
        } else if (field.type === "number" && isNaN(Number(value))) {
          fieldError = "Please enter a valid number";
        } else if (field.name === "price" && Number(value) <= 0) {
          fieldError = "Price must be greater than 0";
        } else if (
          field.name === "bedrooms" &&
          (Number(value) < 0 || Number(value) > 20)
        ) {
          fieldError = "Please enter a valid number of bedrooms (0-20)";
        } else if (
          field.name === "bathrooms" &&
          (Number(value) < 0 || Number(value) > 20)
        ) {
          fieldError = "Please enter a valid number of bathrooms (0-20)";
        } else if (
          field.name === "square_feet" &&
          (Number(value) < 100 || Number(value) > 100000)
        ) {
          fieldError = "Please enter a valid square footage (100-100,000)";
        } else if (
          field.name === "zip_code" &&
          !/^\d{5}(?:[-\s]\d{4})?$/.test(value)
        ) {
          fieldError = "Please enter a valid ZIP code";
        } else if (field.name === "state" && value.length !== 2) {
          fieldError = "State must be a 2-letter abbreviation";
        }

        if (fieldError) {
          isValid = false;
          input.classList.add("error-field");

          const errorElement = document.createElement("div");
          errorElement.className = "error-message";
          errorElement.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${fieldError}`;

          input.parentNode.insertBefore(errorElement, input.nextSibling);
          errors.push(`${field.name}: ${fieldError}`);
        }
      });

      if (!isValid) {
        // Show summary error
        const existingSummary = document.querySelector(".error-summary");
        if (existingSummary) existingSummary.remove();

        const errorSummary = document.createElement("div");
        errorSummary.className = "error-summary";
        errorSummary.innerHTML = `
                  <div style="display: flex; align-items: center; gap: 10px;">
                      <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i>
                      <div>
                          <h3 style="margin: 0 0 5px;">Form Validation Failed</h3>
                          <p style="margin: 0;">Please fix the errors in the form</p>
                      </div>
                  </div>
              `;

        const modalBody = document.querySelector(".modal-body");
        const firstChild = modalBody.firstChild;
        if (firstChild) {
          modalBody.insertBefore(errorSummary, firstChild);
        } else {
          modalBody.appendChild(errorSummary);
        }
      }

      return isValid;
    }

    // Initialize dashboard
    async function initDashboard() {
      setCurrentDate();
      renderCalendar();
      addSmoothScrolling();

      try {
        // Load metrics
        const metrics = await fetchMetrics();
        statsContainer.innerHTML = metrics ? renderMetrics(metrics) :
          '<div class="error">Failed to load metrics</div>';

        // Load listings
        const listings = await fetchListings();
        listingsContainer.innerHTML = listings ? renderListings(listings) :
          '<div class="error">Failed to load listings</div>';
      } catch (error) {
        console.error("Dashboard init error:", error);
        statsContainer.innerHTML = '<div class="error">Metrics loading failed</div>';
        listingsContainer.innerHTML = '<div class="error">Listings loading failed</div>';
      }

      addEventListeners();
    }

    function addSmoothScrolling() {
      document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', function (e) {
          // Prevent default anchor behavior
          e.preventDefault();

          // Get target element
          const targetId = this.getAttribute('href');
          const targetElement = document.querySelector(targetId);

          if (targetElement) {
            // Scroll to target
            targetElement.scrollIntoView({
              behavior: 'smooth',
              block: 'start'
            });

            // Update active class
            document.querySelectorAll('.nav-links a').forEach(a => {
              a.classList.remove('active');
            });
            this.classList.add('active');
          }
        });
      });
    }

    // AI Assistant responses
    function getAIResponse(message) {
      const responses = {
        hello:
          "Hello! How can I assist you with your real estate needs today?",
        hi: "Hi there! I'm your AI real estate assistant. What can I help you with?",
        price:
          "Based on current market data, similar properties in this area are selling for $450-500K. Would you like a detailed pricing report?",
        market:
          "The current market is competitive with low inventory. Properties in your area are selling in 15 days on average, 5% above asking price.",
        tips: "For better photos: 1) Use natural light, 2) Stage key rooms, 3) Highlight unique features, 4) Take photos at golden hour.",
        presentation:
          "To improve property presentation: declutter, depersonalize, enhance curb appeal, and consider minor renovations like fresh paint.",
        marketing:
          "Effective marketing strategies: social media ads, virtual tours, email campaigns, open houses, and professional photography.",
        lead: "For lead generation: network at local events, maintain an online presence, ask for referrals, and use targeted online advertising.",
      };

      const lowerMessage = message.toLowerCase();

      // Check for keywords
      if (lowerMessage.includes("price") || lowerMessage.includes("value")) {
        return responses.price;
      }
      if (lowerMessage.includes("market") || lowerMessage.includes("trend")) {
        return responses.market;
      }
      if (
        lowerMessage.includes("photo") ||
        lowerMessage.includes("picture")
      ) {
        return responses.tips;
      }
      if (lowerMessage.includes("present") || lowerMessage.includes("show")) {
        return responses.presentation;
      }
      if (
        lowerMessage.includes("market") ||
        lowerMessage.includes("promote")
      ) {
        return responses.marketing;
      }
      if (lowerMessage.includes("lead") || lowerMessage.includes("client")) {
        return responses.lead;
      }

      // Default response
      return "I'm here to help with your real estate needs. You can ask me about pricing, market trends, property presentation tips, or marketing strategies.";
    }

    // Add all event listeners
    function addEventListeners() {
      // Logout functionality
      document
        .querySelector(".logout-btn")
        .addEventListener("click", function () {
          if (confirm("Are you sure you want to log out?")) {
            sessionStorage.clear();
            window.location.href = "signin.html";
          }
        });

      // Tool card functionality
      document.querySelectorAll(".tool-card").forEach((card) => {
        card.addEventListener("click", function () {
          const toolId = this.id;
          switch (toolId) {
            case "add-listing-tool":
              openAddListingModal();
              break;
            case "pricing-tool":
              openPricingModal();
              break;
            case "virtual-tour-tool":
              openVirtualTourModal();
              break;
            case "esign-tool":
              openEsignModal();
              break;
            case "marketing-tool":
              openMarketingModal();
              break;
            case "ai-assistant-tool":
              openAIAssistantModal();
              break;
            default:
              alert(
                `Opening ${this.querySelector("h3").textContent} tool...`
              );
          }
        });
      });

      // Property action buttons
      document.querySelectorAll(".edit-btn").forEach((button) => {
        button.addEventListener("click", function (e) {
          e.stopPropagation();
          const id = this.dataset.id;
          openEditListingModal(id);
        });
      });

      document.querySelectorAll(".insights-btn").forEach((button) => {
        button.addEventListener("click", function (e) {
          e.stopPropagation();
          const id = this.dataset.id;
          alert(`Showing insights for property ID: ${id}`);
        });
      });

      // Property card click functionality
      document.querySelectorAll(".property-card").forEach((card) => {
        card.addEventListener("click", function () {
          const id = this.dataset.id;
          alert(`Viewing details for property ID: ${id}`);
        });
      });

      // Calendar navigation
      document
        .getElementById("prev-month")
        .addEventListener("click", function () {
          alert("Navigating to previous month...");
        });

      document
        .getElementById("next-month")
        .addEventListener("click", function () {
          alert("Navigating to next month...");
        });

      // Modal close buttons
      document.querySelectorAll(".close-modal").forEach((button) => {
        button.addEventListener("click", closeAllModals);
      });

      // Close modal when clicking outside content
      document.querySelectorAll(".modal").forEach((modal) => {
        modal.addEventListener("click", (e) => {
          if (e.target === modal) {
            closeAllModals();
          }
        });
      });

      // Handle form submission
      addListingForm.addEventListener("submit", async (e) => {
        e.preventDefault();

        // Validate form
        if (!validateAddListingForm()) {
          return;
        }

        // Show loading state
        const submitBtn = addListingForm.querySelector(".btn-primary");
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.innerHTML =
          '<i class="fas fa-spinner fa-spin"></i> Adding...';
        submitBtn.disabled = true;

        const formData = new FormData(addListingForm);
        formData.append("action", "add_listing");

        try {
          const response = await fetch(API_BASE, {
            method: "POST",
            body: formData,
          });

          const result = await response.json();

          // Remove any existing messages
          const existingMsg =
            addListingForm.querySelector(".modal-body > div");
          if (existingMsg) existingMsg.remove();

          if (result.status === "success") {
            // Show success message
            const successMsg = `
                          <div style="
                              background: #d4edda;
                              color: #155724;
                              padding: 15px;
                              border-radius: 8px;
                              margin-bottom: 20px;
                              display: flex;
                              align-items: center;
                              gap: 10px;
                          ">
                              <i class="fas fa-check-circle" style="font-size: 1.5rem;"></i>
                              <div>
                                  <h3 style="margin: 0 0 5px;">Listing Added Successfully!</h3>
                                  <p style="margin: 0;">Property ID: ${result.id}</p>
                              </div>
                          </div>
                      `;

            // Insert success message at top of form
            addListingForm
              .querySelector(".modal-body")
              .insertAdjacentHTML("afterbegin", successMsg);

            // Reset form and close modal after delay
            setTimeout(() => {
              closeAddListingModal();
              refreshListings();
            }, 2000);
          } else {
            // Show error message
            const errorMsg = `
                          <div style="
                              background: #f8d7da;
                              color: #721c24;
                              padding: 15px;
                              border-radius: 8px;
                              margin-bottom: 20px;
                              display: flex;
                              align-items: center;
                              gap: 10px;
                          ">
                              <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i>
                              <div>
                                  <h3 style="margin: 0 0 5px;">Error Adding Listing</h3>
                                  <p style="margin: 0;">${result.message || "Please try again."
              }</p>
                              </div>
                          </div>
                      `;

            // Insert error message at top of form
            addListingForm
              .querySelector(".modal-body")
              .insertAdjacentHTML("afterbegin", errorMsg);
          }
        } catch (error) {
          console.error("Error adding listing:", error);
          alert("Failed to add listing. Please try again.");
        } finally {
          // Restore button state
          submitBtn.innerHTML = originalBtnText;
          submitBtn.disabled = false;
        }
      });

      // Handle edit form submission
      editListingForm.addEventListener("submit", async (e) => {
        e.preventDefault();

        // Show loading state
        const submitBtn = editListingForm.querySelector(".btn-primary");
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.innerHTML =
          '<i class="fas fa-spinner fa-spin"></i> Updating...';
        submitBtn.disabled = true;

        const formData = new FormData(editListingForm);
        formData.append("action", "update_listing");

        try {
          const response = await fetch(API_BASE, {
            method: "POST",
            body: formData,
          });

          const result = await response.json();

          // Remove any existing messages
          const existingMsg =
            editListingForm.querySelector(".modal-body > div");
          if (existingMsg) existingMsg.remove();

          if (result.status === "success") {
            // Show success message
            const successMsg = `
                          <div style="
                              background: #d4edda;
                              color: #155724;
                              padding: 15px;
                              border-radius: 8px;
                              margin-bottom: 20px;
                              display: flex;
                              align-items: center;
                              gap: 10px;
                          ">
                              <i class="fas fa-check-circle" style="font-size: 1.5rem;"></i>
                              <div>
                                  <h3 style="margin: 0 0 5px;">Listing Updated Successfully!</h3>
                                  <p style="margin: 0;">Property ID: ${result.id}</p>
                              </div>
                          </div>
                      `;

            // Insert success message at top of form
            editListingForm
              .querySelector(".modal-body")
              .insertAdjacentHTML("afterbegin", successMsg);

            // Reset form and close modal after delay
            setTimeout(() => {
              closeAllModals();
              refreshListings();
            }, 2000);
          } else {
            // Show error message
            const errorMsg = `
                          <div style="
                              background: #f8d7da;
                              color: #721c24;
                              padding: 15px;
                              border-radius: 8px;
                              margin-bottom: 20px;
                              display: flex;
                              align-items: center;
                              gap: 10px;
                          ">
                              <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i>
                              <div>
                                  <h3 style="margin: 0 0 5px;">Error Updating Listing</h3>
                                  <p style="margin: 0;">${result.message || "Please try again."
              }</p>
                              </div>
                          </div>
                      `;

            // Insert error message at top of form
            editListingForm
              .querySelector(".modal-body")
              .insertAdjacentHTML("afterbegin", errorMsg);
          }
        } catch (error) {
          console.error("Error updating listing:", error);
          alert("Failed to update listing. Please try again.");
        } finally {
          // Restore button state
          submitBtn.innerHTML = originalBtnText;
          submitBtn.disabled = false;
        }
      });

      // Image preview functionality
      document
        .getElementById("listingImages")
        .addEventListener("change", function (e) {
          imagePreviewContainer.innerHTML = "";

          for (const file of e.target.files) {
            const reader = new FileReader();
            reader.onload = function (e) {
              const preview = document.createElement("div");
              preview.className = "image-preview";
              preview.style.backgroundImage = `url(${e.target.result})`;

              const removeBtn = document.createElement("div");
              removeBtn.className = "remove-image";
              removeBtn.innerHTML = "&times;";
              removeBtn.addEventListener("click", function () {
                preview.remove();
                // Note: This doesn't remove from file input, but it's just for preview
              });

              preview.appendChild(removeBtn);
              imagePreviewContainer.appendChild(preview);
            };
            reader.readAsDataURL(file);
          }
        });

      // Image preview for edit form
      document
        .getElementById("editListingImages")
        .addEventListener("change", function (e) {
          editImagePreviewContainer.innerHTML = "";

          for (const file of e.target.files) {
            const reader = new FileReader();
            reader.onload = function (e) {
              const preview = document.createElement("div");
              preview.className = "image-preview";
              preview.style.backgroundImage = `url(${e.target.result})`;

              const removeBtn = document.createElement("div");
              removeBtn.className = "remove-image";
              removeBtn.innerHTML = "&times;";
              removeBtn.addEventListener("click", function () {
                preview.remove();
              });

              preview.appendChild(removeBtn);
              editImagePreviewContainer.appendChild(preview);
            };
            reader.readAsDataURL(file);
          }
        });

      // Pricing tool functionality
      analyzePriceBtn.addEventListener("click", function () {
        const address = document.getElementById("propertyAddress").value;
        const propertyType = document.getElementById("propertyType").value;

        if (!address || !propertyType) {
          alert("Please fill in all required fields");
          return;
        }

        // Show loading state
        const originalText = analyzePriceBtn.innerHTML;
        analyzePriceBtn.innerHTML =
          '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
        analyzePriceBtn.disabled = true;

        // Simulate AI analysis
        setTimeout(() => {
          pricingResult.style.display = "block";
          analyzePriceBtn.innerHTML = originalText;
          analyzePriceBtn.disabled = false;
        }, 1500);
      });

      // AI Assistant functionality
      sendMessageBtn.addEventListener("click", function () {
        const message = chatInput.value.trim();
        if (!message) return;

        // Add user message to chat
        const userMessage = document.createElement("div");
        userMessage.className = "message user-message";
        userMessage.innerHTML = `<p>${escapeHTML(message)}</p>`;
        aiChat.appendChild(userMessage);

        // Clear input
        chatInput.value = "";

        // Add thinking indicator
        const thinking = document.createElement("div");
        thinking.className = "message ai-message";
        thinking.innerHTML = `<div class="thinking"><i class="fas fa-spinner fa-spin"></i> Thinking...</div>`;
        aiChat.appendChild(thinking);

        // Scroll to bottom
        aiChat.scrollTop = aiChat.scrollHeight;

        // Simulate AI response
        setTimeout(() => {
          // Remove thinking indicator
          thinking.remove();

          // Add AI response
          const aiResponse = getAIResponse(message);
          const aiMessage = document.createElement("div");
          aiMessage.className = "message ai-message";
          aiMessage.innerHTML = `<p>${aiResponse}</p>`;
          aiChat.appendChild(aiMessage);

          // Scroll to bottom
          aiChat.scrollTop = aiChat.scrollHeight;
        }, 1500);
      });

      // Allow pressing Enter to send message
      chatInput.addEventListener("keypress", function (e) {
        if (e.key === "Enter") {
          sendMessageBtn.click();
        }
      });

      // Marketing channel selection
      channelOptions.forEach((option) => {
        option.addEventListener("click", function () {
          this.classList.toggle("selected");
        });
      });

      // Launch marketing campaign
      launchCampaignBtn.addEventListener("click", function () {
        const property = document.getElementById("marketingProperty").value;
        if (!property) {
          alert("Please select a property");
          return;
        }

        const selectedChannels = document.querySelectorAll(
          ".channel-option.selected"
        );
        if (selectedChannels.length === 0) {
          alert("Please select at least one marketing channel");
          return;
        }

        // Show loading state
        const originalText = launchCampaignBtn.innerHTML;
        launchCampaignBtn.innerHTML =
          '<i class="fas fa-spinner fa-spin"></i> Launching...';
        launchCampaignBtn.disabled = true;

        // Simulate campaign launch
        setTimeout(() => {
          alert("Marketing campaign launched successfully!");
          launchCampaignBtn.innerHTML = originalText;
          launchCampaignBtn.disabled = false;
          closeAllModals();
        }, 1500);
      });

      // eSign functionality
      document.querySelectorAll(".sign-btn").forEach((button) => {
        button.addEventListener("click", function () {
          const docName =
            this.closest(".document-item").querySelector("h4").textContent;

          // Show loading state
          const originalText = this.innerHTML;
          this.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Signing...';
          this.disabled = true;

          // Simulate signing process
          setTimeout(() => {
            this.innerHTML = '<i class="fas fa-check"></i> Signed';
            this.style.background = "var(--success-green)";
            alert(`${docName} has been successfully signed!`);
          }, 1500);
        });
      });

      // Virtual tour generation
      document
        .getElementById("generate-tour")
        .addEventListener("click", function () {
          const property = document.getElementById("tourProperty").value;
          if (!property) {
            alert("Please select a property");
            return;
          }

          // Show loading state
          const originalText = this.innerHTML;
          this.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Generating...';
          this.disabled = true;

          // Simulate tour generation
          setTimeout(() => {
            this.innerHTML = originalText;
            this.disabled = false;
            alert("Virtual tour generated successfully!");
            closeAllModals();
          }, 2000);
        });
    }

    // Start dashboard
    document.addEventListener("DOMContentLoaded", initDashboard);
  </script>
</body>

</html>
