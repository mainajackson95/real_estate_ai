<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI']);
    header("Location: signin.html?redirect=$redirect");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch saved properties
$saved_sql = "SELECT p.*, 
              (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) AS image_path
              FROM saved_properties sp
              JOIN properties p ON sp.property_id = p.id
              WHERE sp.buyer_id = $user_id
              ORDER BY sp.saved_at DESC
              LIMIT 3";
$saved_result = $conn->query($saved_sql);

// Handle favorite toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['property_id'])) {
    $property_id = $_POST['property_id'];
    $is_favorite = $_POST['is_favorite'];

    if ($is_favorite == 'true') {
        $stmt = $conn->prepare("DELETE FROM saved_properties WHERE buyer_id = ? AND property_id = ?");
    } else {
        $stmt = $conn->prepare("INSERT INTO saved_properties (buyer_id, property_id) VALUES (?, ?)");
    }
    $stmt->bind_param("ii", $user_id, $property_id);
    $stmt->execute();
    $stmt->close();

    exit; // AJAX response only

    // After fetching user details
    if ($user['role'] !== 'buyer') {
        // Redirect to appropriate dashboard
        header("Location: {$user['role']}_dashboard.php");
        exit;
    }
}

// Fetch recommended properties
$recommended_sql = "SELECT p.*, 
                   (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) AS image_path,
                   (SELECT COUNT(*) FROM saved_properties WHERE property_id = p.id AND buyer_id = $user_id) AS is_favorite
                   FROM properties p
                   WHERE p.status = 'available'
                   ORDER BY p.created_at DESC
                   LIMIT 6";
$recommended_result = $conn->query($recommended_sql);

// Fetch saved properties (FIXED: corrected column references and image query)
$saved_sql = "SELECT p.*, 
              (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) AS image_path,
              (SELECT COUNT(*) FROM saved_properties WHERE property_id = p.id AND buyer_id = $user_id) AS is_favorite
              FROM saved_properties sp
              JOIN properties p ON sp.property_id = p.id
              WHERE sp.buyer_id = $user_id
              ORDER BY sp.saved_at DESC
              LIMIT 3";
$saved_result = $conn->query($saved_sql);

// Fetch locations for dropdown
$locations_sql = "SELECT DISTINCT CONCAT(city, ', ', state) AS location 
                 FROM properties 
                 ORDER BY city";
$locations_result = $conn->query($locations_sql);
$locations = [];
while ($row = $locations_result->fetch_assoc()) {
    $locations[] = $row['location'];
}

// Process search filters
$search_results = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $location = $_GET['location'] ?? '';
    $type = $_GET['type'] ?? 'any';
    $price = $_GET['price'] ?? 'any';
    $beds = $_GET['beds'] ?? 0;

    // Build SQL query with filters
    $search_sql = "SELECT p.*, 
                  (SELECT image_path FROM property_images WHERE property_id = p.id LIMIT 1) AS image_path,
                  (SELECT COUNT(*) FROM saved_properties WHERE property_id = p.id AND buyer_id = $user_id) AS is_favorite
                  FROM properties p
                  WHERE p.status = 'available'";

    if (!empty($location)) {
        list($city, $state) = explode(', ', $location);
        $search_sql .= " AND p.city = '$city' AND p.state = '$state'";
    }

    if ($type !== 'any') {
        $search_sql .= " AND p.property_type = '$type'";
    }

    if ($price !== 'any') {
        list($minPrice, $maxPrice) = explode('-', $price);
        $search_sql .= " AND p.price BETWEEN $minPrice AND $maxPrice";
    }

    if ($beds > 0) {
        $search_sql .= " AND p.bedrooms >= $beds";
    }

    $search_result = $conn->query($search_sql);
    while ($row = $search_result->fetch_assoc()) {
        $search_results[] = $row;
    }
}

// Fetch property types for the dropdown
$property_types_sql = "SELECT DISTINCT property_type FROM properties ORDER BY property_type";
$property_types_result = $conn->query($property_types_sql);
$property_types = [];
while ($row = $property_types_result->fetch_assoc()) {
    $property_types[] = $row['property_type'];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Buyer Dashboard - Real Estate AI</title>
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
            --modal-overlay: rgba(0, 0, 0, 0.6);
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
            position: relative;
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.25) !important;
            border-left: 4px solid var(--accent-gold);
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
            z-index: 100;
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

        /* Add dropdown styles */
        .dropdown {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .placeholder-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #666;
            text-align: center;
            z-index: 2;
        }

        .placeholder-text i {
            display: block;
            font-size: 2rem;
            margin-bottom: 5px;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            width: 100%;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            border-radius: 0 0 6px 6px;
            max-height: 200px;
            overflow-y: auto;
        }

        .dropdown-content div {
            padding: 12px;
            cursor: pointer;
            border-bottom: 1px solid #ddd;
        }

        .dropdown-content div:hover {
            background-color: #f1f1f1;
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary-teal);
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.error {
            background: var(--secondary-coral);
        }

        .show {
            display: block;
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
            background: linear-gradient(45deg,
                    var(--accent-gold),
                    var(--info-blue));
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
            display: flex;
            align-items: center;
        }

        .date-display i {
            margin-right: 8px;
        }

        .search-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .search-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .search-header h2 {
            font-size: 1.5rem;
            color: var(--text-dark);
            position: relative;
            padding-bottom: 10px;
        }

        .search-header h2::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--primary-teal);
            border-radius: 3px;
        }

        .search-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
        }

        .filter-group select,
        .filter-group input {
            padding: 12px;
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary-teal);
        }

        .search-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .search-btn {
            background: var(--primary-teal);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
        }

        .search-btn:hover {
            background: #0f766e;
        }

        .reset-btn {
            background: var(--border-gray);
            color: var(--text-dark);
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
        }

        .reset-btn:hover {
            background: #e5e7eb;
        }

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
            position: relative;
            padding-bottom: 10px;
        }

        .section-header h2::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--primary-teal);
            border-radius: 3px;
        }

        .view-all {
            color: var(--primary-teal);
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .view-all i {
            margin-left: 5px;
            transition: transform 0.3s;
        }

        .view-all:hover i {
            transform: translateX(3px);
        }

        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
        }

        .property-card {
            background: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
        }

        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .property-image {
            height: 200px;
            position: relative;
            background-size: cover;
            background-position: center;
        }

        .property-image::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to top,
                    rgba(0, 0, 0, 0.5),
                    transparent 40%);
        }

        .messages-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .messages-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .messages-header h3 {
            font-size: 1.3rem;
        }

        .new-message-btn {
            background: var(--primary-teal);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
        }

        .new-message-btn i {
            margin-right: 8px;
        }

        .viewings-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .viewings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .viewings-header h3 {
            font-size: 1.3rem;
        }

        .viewings-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .viewing-card {
            display: flex;
            background: #f8fafc;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }

        .viewing-image {
            width: 200px;
            background-size: cover;
            background-position: center;
        }

        .viewing-details {
            flex: 1;
            padding: 20px;
        }

        .viewing-details h4 {
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: var(--text-dark);
        }

        .viewing-meta {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
            color: var(--text-light);
        }

        .viewing-meta span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge {
            align-self: flex-start;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 5px;
        }

        .status-badge.confirmed {
            background: var(--success-green);
            color: white;
        }

        .status-badge.pending {
            background: var(--accent-gold);
            color: #854d0e;
        }

        .viewing-actions {
            display: flex;
            gap: 10px;
        }

        .viewing-actions .btn {
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            border: none;
        }

        .reschedule-btn,
        .confirm-btn {
            background: rgba(96, 165, 250, 0.1);
            color: var(--info-blue);
        }

        .cancel-btn {
            background: rgba(248, 113, 113, 0.1);
            color: var(--secondary-coral);
        }

        @media (max-width: 768px) {
            .viewing-card {
                flex-direction: column;
            }

            .viewing-image {
                width: 100%;
                height: 150px;
            }

            .viewing-actions {
                flex-direction: column;
            }
        }

        .conversation-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .conversation {
            display: flex;
            padding: 15px;
            border-radius: 8px;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .agents-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .agents-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-gray);
            flex-wrap: wrap;
            gap: 15px;
        }

        .agents-header h3 {
            font-size: 1.3rem;
        }

        .agent-search {
            position: relative;
            width: 300px;
        }

        .agent-search input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }

        .agent-search input:focus {
            outline: none;
            border-color: var(--primary-teal);
        }

        .agent-search i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }

        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .agent-card {
            background: #f8fafc;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            display: flex;
            transition: all 0.3s;
        }

        .agent-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.12);
        }

        .agent-avatar {
            width: 120px;
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }

        .agent-details {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }

        .agent-details h4 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: var(--text-dark);
        }

        .agent-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 15px;
        }

        .agent-rating i {
            color: var(--accent-gold);
        }

        .agent-rating span {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-left: 8px;
        }

        .agent-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .agent-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .agent-contact {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .agent-contact span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .agent-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
        }

        .agent-actions .btn {
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            border: none;
            flex: 1;
            justify-content: center;
        }

        .contact-btn {
            background: rgba(96, 165, 250, 0.1);
            color: var(--info-blue);
        }

        .schedule-btn {
            background: rgba(13, 148, 136, 0.1);
            color: var(--primary-teal);
        }

        /* Schedule Modal Styles */
        .schedule-agent-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .agent-avatar-sm {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-teal);
        }

        @media (max-width: 768px) {
            .agents-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .agent-search {
                width: 100%;
            }

            .agents-grid {
                grid-template-columns: 1fr;
            }

            .agent-card {
                flex-direction: column;
            }

            .agent-avatar {
                width: 100%;
                height: 200px;
            }

            .agent-actions {
                flex-direction: column;
            }
        }

        .conversation:hover,
        .conversation.active {
            background: rgba(13, 148, 136, 0.05);
            border-left: 3px solid var(--primary-teal);
        }

        .agent-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--primary-teal), var(--info-blue));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .conversation-details {
            flex-grow: 1;
            overflow: hidden;
        }

        .conversation-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .conversation-header h4 {
            font-size: 1.1rem;
            color: var(--text-dark);
        }

        .message-time {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .message-preview {
            color: var(--text-light);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.9rem;
        }

        .unread-indicator {
            background: var(--secondary-coral);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
            flex-shrink: 0;
            align-self: center;
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .messages-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .new-message-btn {
                width: 100%;
                justify-content: center;
            }
        }

        .property-image img {
            max-width: 100%;
            height: auto;
            object-fit: cover;
        }

        .placeholder-image {
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 200px;
            color: #666;
            font-style: italic;
        }

        .property-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            z-index: 2;
        }

        .badge-new {
            background: var(--secondary-coral);
            color: white;
        }

        .badge-featured {
            background: var(--accent-gold);
            color: #854d0e;
        }

        .favorite-btn {
            position: absolute;
            top: 15px;
            left: 15px;
            background: rgba(255, 255, 255, 0.8);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            color: var(--secondary-coral);
            font-size: 1.1rem;
            transition: all 0.3s;
            z-index: 2;
        }

        .favorite-btn.active {
            color: var(--secondary-coral);
        }

        .favorite-btn:hover {
            background: white;
            transform: scale(1.1);
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
            flex-wrap: wrap;
        }

        .property-meta span {
            margin-right: 15px;
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }

        .property-meta i {
            margin-right: 5px;
        }

        .property-address {
            color: var(--text-light);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .property-address i {
            margin-right: 8px;
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
            display: flex;
            align-items: center;
        }

        .property-actions button i {
            margin-right: 5px;
        }

        .details-btn {
            background: rgba(13, 148, 136, 0.1);
            color: var(--primary-teal);
        }

        .details-btn:hover {
            background: rgba(13, 148, 136, 0.2);
        }

        .contact-btn {
            background: rgba(96, 165, 250, 0.1);
            color: var(--info-blue);
        }

        .contact-btn:hover {
            background: rgba(96, 165, 250, 0.2);
        }

        .saved-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: var(--border-gray);
        }

        .empty-state p {
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .saved-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        html {
            scroll-behavior: smooth;
        }

        .saved-header h3 {
            font-size: 1.3rem;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--modal-overlay);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .modal-header::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(to right,
                    var(--primary-teal),
                    var(--info-blue));
        }

        .modal-header h3 {
            color: var(--text-dark);
            font-size: 1.4rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s;
        }

        .close-btn:hover {
            color: var(--secondary-coral);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-image {
            height: 300px;
            background-size: cover;
            background-position: center;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .modal-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .detail-label {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 1.1rem;
        }

        .detail-value.price {
            color: var(--primary-teal);
            font-size: 1.3rem;
        }

        .modal-description {
            margin-top: 20px;
            line-height: 1.6;
            color: var(--text-dark);
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-gray);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .user-avatar {
            position: relative;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .edit-icon {
            margin-left: 8px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
            transition: color 0.3s;
        }

        .edit-icon:hover {
            color: var(--accent-gold);
        }

        #username-edit-form {
            display: none;
            margin-top: 10px;
        }

        #username-edit-form input {
            width: 100%;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        #username-edit-form button {
            margin-top: 5px;
            padding: 5px 10px;
            background: var(--primary-teal);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: flex;
            align-items: center;
        }

        .btn-primary {
            background: var(--primary-teal);
            color: white;
        }

        .btn-primary:hover {
            background: #0f766e;
        }

        .btn-secondary {
            background: var(--border-gray);
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        /* Search Results Message */
        .search-results-message {
            background-color: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .search-results-message.show {
            display: block;
        }

        .search-results-message i {
            margin-right: 10px;
            color: var(--primary-teal);
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

            .property-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .date-display {
                margin-top: 10px;
            }

            .search-filters {
                grid-template-columns: 1fr;
            }

            .modal-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">
            <i class="fas fa-home"></i>
            <h1>RealEstate AI</h1>
        </div>

        <div class="user-info" id="user-profile-section">
            <div class="user-avatar">
                <?php if (isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                    <img src="<?= $user['profile_pic'] ?>" alt="Profile Picture">
                <?php else: ?>
                    <?= substr($user['username'], 0, 2) ?>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <h3>
                    <span id="username-display"><?= $user['username'] ?></span>
                    <a href="#" id="edit-username-btn" class="edit-icon">
                        <i class="fas fa-pencil-alt"></i>
                    </a>
                </h3>
            </div>
        </div>

        <div class="nav-links">
            <a href="#" class="active" id="find-properties-link">
                <i class="fas fa-search"></i>
                <span>Find Properties</span>
            </a>
            <a href="#saved-properties-section" id="saved-properties-link">
                <i class="fas fa-heart"></i>
                <span>Saved Properties</span>
            </a>
            <a href="#">
                <i class="fas fa-bell"></i>
                <span>Price Alerts</span>
            </a>
            <a href="#messages-section" id="messages-link">
                <i class="fas fa-comments"></i>
                <span>Messages</span>
            </a>
            <a href="#viewings-section" id="viewings-link">
                <i class="fas fa-calendar-check"></i>
                <span>Viewings</span>
            </a>
            <a href="#agents-section" id="agents-link">
                <i class="fas fa-user-tie"></i>
                <span>My Agents</span>
            </a>
        </div>

        <button class="logout-btn" id="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </button>
    </div>

    <!-- Main Content -->
    <div class="content">
        <div class="dashboard-header">
            <h1>Find Your Dream Home</h1>
            <div class="date-display">
                <i class="far fa-calendar"></i>
                <span id="current-date"></span>
            </div>
        </div>

        <!-- Search Section -->
        <div class="search-container">
            <div class="search-header">
                <h2>Advanced Property Search</h2>
            </div>

            <form method="GET" id="search-form">
                <div class="search-filters">
                    <div class="filter-group">
                        <label>Location</label>
                        <div class="dropdown">
                            <input type="text" id="location-input" name="location"
                                placeholder="City, Neighborhood, or ZIP" autocomplete="off"
                                value="<?= isset($_GET['location']) ? htmlspecialchars($_GET['location']) : '' ?>">
                            <div class="dropdown-content" id="location-dropdown"> <?php foreach ($locations as $loc): ?>
                                    <div onclick="selectLocation('<?= $loc ?>')">
                                        <?= $loc ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Property Type Filter -->
                    <div class="filter-group">
                        <label>Property Type</label>
                        <select id="type-select" name="type">
                            <option value="any" <?= ($_GET['type'] ?? 'any') === 'any' ? 'selected' : '' ?>>Any Type
                            </option>
                            <?php foreach ($property_types as $type): ?>
                                <option value="<?= $type ?>" <?= ($_GET['type'] ?? '') === $type ? 'selected' : '' ?>>
                                    <?= ucfirst($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Price Range Filter -->
                    <div class="filter-group">
                        <label>Price Range</label>
                        <select id="price-select" name="price">
                            <option value="any" <?= ($_GET['price'] ?? 'any') === 'any' ? 'selected' : '' ?>>Any Price
                            </option>
                            <option value="0-200000" <?= ($_GET['price'] ?? '') === '0-200000' ? 'selected' : '' ?>>Under
                                $200,000</option>
                            <option value="200000-400000" <?= ($_GET['price'] ?? '') === '200000-400000' ? 'selected' : '' ?>>$200,000 - $400,000</option>
                            <option value="400000-600000" <?= ($_GET['price'] ?? '') === '400000-600000' ? 'selected' : '' ?>>$400,000 - $600,000</option>
                            <option value="600000-800000" <?= ($_GET['price'] ?? '') === '600000-800000' ? 'selected' : '' ?>>$600,000 - $800,000</option>
                            <option value="800000-999999999" <?= ($_GET['price'] ?? '') === '800000-999999999' ? 'selected' : '' ?>>Over $800,000</option>
                        </select>
                    </div>

                    <!-- Bedrooms Filter -->
                    <div class=" filter-group">
                        <label>Bedrooms</label>
                        <select id="beds-select" name="beds">
                            <option value="0" <?= ($_GET['beds'] ?? 0) == 0 ? 'selected' : '' ?>>Any
                            </option>
                            <option value="1" <?= ($_GET['beds'] ?? 0) == 1 ? 'selected' : '' ?>>1+
                            </option>
                            <option value="2" <?= ($_GET['beds'] ?? 0) == 2 ? 'selected' : '' ?>>2+
                            </option>
                            <option value="3" <?= ($_GET['beds'] ?? 0) == 3 ? 'selected' : '' ?>>3+
                            </option>
                            <option value="4" <?= ($_GET['beds'] ?? 0) == 4 ? 'selected' : '' ?>>4+
                            </option>
                        </select>
                    </div>
                </div>

                <div class="search-actions">
                    <button type="button" class="reset-btn" id="reset-btn">Reset Filters</button>
                    <button type="submit" class="search-btn" name="search">
                        <i class="fas fa-search"></i> Search Properties
                    </button>
                </div>
            </form>
        </div>

        <!-- Search Results -->
        <?php if (!empty($search_results)): ?>
            <div class="search-results-message show">
                <i class="fas fa-info-circle"></i>
                <span>Found
                    <?= count($search_results) ?> properties matching your search
                </span>
            </div>

            <div class="dashboard-section">
                <div class="section-header">
                    <h2>Search Results</h2>
                </div>

                <div class="property-grid">
                    <?php foreach ($search_results as $property): ?>
                        <?php include 'property_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recommended Properties Section -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>Recommended For You</h2>
                <a href="properties.php" class="view-all">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="property-grid" id="recommended-grid">
                <?php while ($property = $recommended_result->fetch_assoc()): ?>
                    <?php include 'property_card.php'; ?>
                <?php endwhile; ?>
            </div>
        </div>

        <!-- Saved Properties Section -->
        <div class="dashboard-section" id="saved-properties-section">
            <div class="section-header">
                <h2>Your Saved Properties</h2>
                <a href="#" class="view-all">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="saved-container">
                <div class="saved-header">
                    <h3>Recently Saved (<?= $saved_result->num_rows ?>)</h3>
                </div>

                <div class="property-grid">
                    <?php if ($saved_result->num_rows > 0): ?>
                        <?php while ($property = $saved_result->fetch_assoc()): ?>
                            <?php include 'property_card.php'; ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="far fa-heart"></i>
                            <h3>No Saved Properties</h3>
                            <p>Save properties you're interested in by clicking the heart icon</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dashboard-section" id="messages-section">
            <div class="section-header">
                <h2>Your Messages</h2>
                <a href="messages.php" class="view-all">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="messages-container">
                <div class="messages-header">
                    <h3>Recent Conversations</h3>
                    <button class="new-message-btn">
                        <i class="fas fa-plus"></i> New Message
                    </button>
                </div>

                <div class="conversation-list">
                    <!-- Sample conversation 1 -->
                    <div class="conversation active">
                        <div class="agent-avatar">JS</div>
                        <div class="conversation-details">
                            <div class="conversation-header">
                                <h4>John Smith</h4>
                                <span class="message-time">2 hours ago</span>
                            </div>
                            <p class="message-preview">Yes, we can schedule a viewing for tomorrow at 3 PM...
                            </p>
                        </div>
                        <div class="unread-indicator">2</div>
                    </div>

                    <!-- Sample conversation 2 -->
                    <div class="conversation">
                        <div class="agent-avatar">MJ</div>
                        <div class="conversation-details">
                            <div class="conversation-header">
                                <h4>Maria Johnson</h4>
                                <span class="message-time">Yesterday</span>
                            </div>
                            <p class="message-preview">I've found a property that matches your criteria...</p>
                        </div>
                    </div>

                    <!-- Sample conversation 3 -->
                    <div class="conversation">
                        <div class="agent-avatar">RP</div>
                        <div class="conversation-details">
                            <div class="conversation-header">
                                <h4>Robert Parker</h4>
                                <span class="message-time">2 days ago</span>
                            </div>
                            <p class="message-preview">The seller has accepted your offer! Next steps...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add this new Viewings Section -->
        <div class="dashboard-section" id="viewings-section">
            <div class="section-header">
                <h2>Your Scheduled Viewings</h2>
                <a href="#" class="view-all">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="viewings-container">
                <div class="viewings-header">
                    <h3>Upcoming Appointments (3)</h3>
                </div>

                <div class="viewings-list">
                    <!-- Viewing Card 1 -->
                    <div class="viewing-card">
                        <div class="viewing-image" style="background-image: url('property1.jpg');"></div>
                        <div class="viewing-details">
                            <h4>Modern Downtown Loft</h4>
                            <div class="viewing-meta">
                                <span><i class="far fa-calendar"></i> Tomorrow, 10:00 AM</span>
                                <span><i class="fas fa-user-tie"></i> Agent: Sarah Johnson</span>
                                <span class="status-badge confirmed">Confirmed</span>
                            </div>
                            <div class="viewing-actions">
                                <button class="btn reschedule-btn"><i class="fas fa-calendar-edit"></i>
                                    Reschedule</button>
                                <button class="btn cancel-btn"><i class="fas fa-times-circle"></i>
                                    Cancel</button>
                            </div>
                        </div>
                    </div>

                    <!-- Viewing Card 2 -->
                    <div class="viewing-card">
                        <div class="viewing-image" style="background-image: url('property2.jpg');"></div>
                        <div class="viewing-details">
                            <h4>Suburban Family Home</h4>
                            <div class="viewing-meta">
                                <span><i class="far fa-calendar"></i> June 25, 2:30 PM</span>
                                <span><i class="fas fa-user-tie"></i> Agent: Michael Chen</span>
                                <span class="status-badge pending">Pending Confirmation</span>
                            </div>
                            <div class="viewing-actions">
                                <button class="btn confirm-btn"><i class="fas fa-check-circle"></i>
                                    Confirm</button>
                                <button class="btn cancel-btn"><i class="fas fa-times-circle"></i>
                                    Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add this new Agents Section -->
        <div class="dashboard-section" id="agents-section">
            <div class="section-header">
                <h2>Your Real Estate Agents</h2>
                <a href="#" class="view-all">
                    Find New Agents <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="agents-container">
                <div class="agents-header">
                    <h3>Your Trusted Partners (3)</h3>
                    <div class="agent-search">
                        <input type="text" placeholder="Search agents..." id="agent-search-input">
                        <i class="fas fa-search"></i>
                    </div>
                </div>

                <div class="agents-grid">
                    <!-- Agent Card 1 -->
                    <div class="agent-card">
                        <div class="agent-avatar" style="background-image: url('agent1.jpg');"></div>
                        <div class="agent-details">
                            <h4>Sarah Johnson</h4>
                            <div class="agent-rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                                <span>4.7 (128 reviews)</span>
                            </div>
                            <div class="agent-meta">
                                <span><i class="fas fa-home"></i> 12 properties</span>
                                <span><i class="fas fa-map-marker-alt"></i> Downtown</span>
                            </div>
                            <div class="agent-contact">
                                <span><i class="fas fa-phone"></i> (555) 123-4567</span>
                                <span><i class="fas fa-envelope"></i> sarah@realestate.com</span>
                            </div>
                            <div class="agent-actions">
                                <button class="btn contact-btn"><i class="fas fa-comment"></i> Message</button>
                                <button class="btn schedule-btn"><i class="fas fa-calendar"></i> Schedule
                                    Call</button>
                            </div>
                        </div>
                    </div>

                    <!-- Agent Card 2 -->
                    <div class="agent-card">
                        <div class="agent-avatar" style="background-image: url('agent2.jpg');"></div>
                        <div class="agent-details">
                            <h4>Michael Chen</h4>
                            <div class="agent-rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="far fa-star"></i>
                                <span>4.3 (97 reviews)</span>
                            </div>
                            <div class="agent-meta">
                                <span><i class="fas fa-home"></i> 8 properties</span>
                                <span><i class="fas fa-map-marker-alt"></i> Suburbs</span>
                            </div>
                            <div class="agent-contact">
                                <span><i class="fas fa-phone"></i> (555) 987-6543</span>
                                <span><i class="fas fa-envelope"></i> michael@realestate.com</span>
                            </div>
                            <div class="agent-actions">
                                <button class="btn contact-btn"><i class="fas fa-comment"></i> Message</button>
                                <button class="btn schedule-btn"><i class="fas fa-calendar"></i> Schedule
                                    Call</button>
                            </div>
                        </div>
                    </div>

                    <!-- Agent Card 3 -->
                    <div class="agent-card">
                        <div class="agent-avatar" style="background-image: url('agent3.jpg');"></div>
                        <div class="agent-details">
                            <h4>Robert Parker</h4>
                            <div class="agent-rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <span>5.0 (56 reviews)</span>
                            </div>
                            <div class="agent-meta">
                                <span><i class="fas fa-home"></i> 5 properties</span>
                                <span><i class="fas fa-map-marker-alt"></i> Luxury Homes</span>
                            </div>
                            <div class="agent-contact">
                                <span><i class="fas fa-phone"></i> (555) 456-7890</span>
                                <span><i class="fas fa-envelope"></i> robert@realestate.com</span>
                            </div>
                            <div class="agent-actions">
                                <button class="btn contact-btn"><i class="fas fa-comment"></i> Message</button>
                                <button class="btn schedule-btn"><i class="fas fa-calendar"></i> Schedule
                                    Call</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Property Detail Modal -->
    <div class="modal" id="property-modal">
        <!-- Modal content will be populated by JavaScript -->
    </div>

    <!-- Schedule Call Modal -->
    <div class="modal" id="schedule-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Schedule a Call</h3>
                <button class="close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="schedule-agent-info">
                    <div class="agent-avatar-sm" id="schedule-agent-avatar"></div>
                    <div>
                        <h4 id="schedule-agent-name">Agent Name</h4>
                        <p id="schedule-agent-specialty">Specialty</p>
                    </div>
                </div>

                <div class="form-group">
                    <label>Select Date & Time</label>
                    <input type="datetime-local" id="schedule-time">
                </div>

                <div class="form-group">
                    <label>Meeting Type</label>
                    <select id="meeting-type">
                        <option value="phone">Phone Call</option>
                        <option value="video">Video Conference</option>
                        <option value="in-person">In-Person Meeting</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea id="meeting-notes" placeholder="Any specific topics you want to discuss..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary close-btn">Cancel</button>
                <button class="btn btn-primary" id="confirm-schedule">Schedule Call</button>
            </div>
        </div>
    </div>

    <!-- Profile Edit Modal -->
    <div class="modal" id="profile-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Profile</h3>
                <button class="close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <form id="profile-form">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="profile-username" name="username" value="<?= $user['username'] ?>">
                    </div>

                    <div class="form-group">
                        <label>Profile Picture</label>
                        <div class="profile-pic-preview" id="profile-pic-preview" style="width: 150px; height: 150px; border-radius: 50%; 
               background-color: #f0f0f0; margin-bottom: 15px;
               background-size: cover; background-position: center;
               <?php if (isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                   background-image: url('<?= $user['profile_pic'] ?>')
               <?php endif; ?>">
                        </div>
                        <input type="file" id="profile-pic" name="profile_pic" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="profile-email" name="email" value="<?= $user['email'] ?>" readonly>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary close-btn">Cancel</button>
                <button class="btn btn-primary" id="save-profile-btn">Save Changes</button>
            </div>
        </div>
    </div>

    <script>
        // Set current date
        const now = new Date();
        const options = {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        document.getElementById('current-date').textContent =
            now.toLocaleDateString('en-US', options);

        // Fixed active sidebar link management
        const navLinks = document.querySelectorAll('.sidebar .nav-link');
        const sections = document.querySelectorAll('.dashboard-section, #top-section');

        // Function to set active link
        function setActiveLink() {
            // Find the section closest to the top of the viewport
            let closestSection = null;
            let minDistance = Number.MAX_SAFE_INTEGER;

            sections.forEach(section => {
                const rect = section.getBoundingClientRect();
                const distance = Math.abs(rect.top);

                if (distance < minDistance) {
                    minDistance = distance;
                    closestSection = section;
                }
            });

            // Update active link
            if (closestSection) {
                const targetId = closestSection.getAttribute('id');
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === `#${targetId}`) {
                        link.classList.add('active');
                    }
                });
            }
        }

        // Set active link on scroll
        window.addEventListener('scroll', setActiveLink);

        // Set active link on click
        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();

                // Remove active class from all
                navLinks.forEach(link => link.classList.remove('active'));

                // Add active to clicked link
                this.classList.add('active');

                // Scroll to target
                const targetId = this.getAttribute('href');
                if (targetId === '#top-section') {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    const targetSection = document.querySelector(targetId);
                    if (targetSection) {
                        targetSection.scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });
        });

        // Initialize active link
        setActiveLink();

        // Location dropdown functionality
        document.getElementById('location-input').addEventListener('input', function () {
            const input = this.value.toLowerCase();
            const dropdown = document.getElementById('location-dropdown');
            const options = dropdown.getElementsByTagName('div');

            if (input.length > 1) {
                dropdown.classList.add('show');
            } else {
                dropdown.classList.remove('show');
            }

            for (let i = 0; i < options.length; i++) {
                const text = options[i].textContent.toLowerCase();
                if (text.includes(input)) {
                    options[i].style.display = 'block';
                } else {
                    options[i].style.display = 'none';
                }
            }
        });

        function selectLocation(location) {
            document.getElementById('location-input').value = location;
            document.getElementById('location-dropdown').classList.remove('show');
        }

        // Close dropdown when clicking outside
        window.addEventListener('click', function (e) {
            if (!e.target.matches('#location-input')) {
                document.getElementById('location-dropdown').classList.remove('show');
            }
        });

        // Favorite button functionality
        document.addEventListener('click', function (e) {
            if (e.target.closest('.favorite-btn')) {
                const btn = e.target.closest('.favorite-btn');
                const propertyId = btn.dataset.id;
                const isFavorite = btn.classList.contains('active');

                // Toggle UI immediately for better UX
                const icon = btn.querySelector('i');
                if (isFavorite) {
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                    btn.classList.remove('active');
                } else {
                    icon.classList.remove('far');
                    icon.classList.add('fas');
                    btn.classList.add('active');
                }

                // Send AJAX request to update database
                const formData = new FormData();
                formData.append('property_id', propertyId);
                formData.append('is_favorite', !isFavorite);

                fetch('buyer_dashboard.php', {
                    method: 'POST',
                    body: formData
                });
            }
        });

        // Smooth scrolling for sidebar links
        document.getElementById('find-properties-link').addEventListener('click', function (e) {
            e.preventDefault();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        document.getElementById('saved-properties-link').addEventListener('click', function (e) {
            e.preventDefault();
            const section = document.getElementById('saved-properties-section');
            section.scrollIntoView({ behavior: 'smooth' });
        });

        document.getElementById('messages-link').addEventListener('click', function (e) {
            e.preventDefault();
            const section = document.getElementById('messages-section');
            section.scrollIntoView({ behavior: 'smooth' });
        });

        // Property details modal
        document.addEventListener('click', function (e) {
            if (e.target.closest('.details-btn')) {
                const card = e.target.closest('.property-card');
                const propertyId = card.dataset.id;

                fetch(`get_property_details.php?id=${propertyId}`)
                    .then(response => response.json())
                    .then(data => {
                        const modal = document.getElementById('property-modal');
                        modal.querySelector('#modal-title').textContent = data.title;
                        modal.querySelector('#modal-price').textContent = '$' +
                            new Intl.NumberFormat().format(data.price);
                        modal.querySelector('#modal-beds').textContent = data.bedrooms;
                        modal.querySelector('#modal-baths').textContent = data.bathrooms;
                        modal.querySelector('#modal-sqft').textContent =
                            new Intl.NumberFormat().format(data.square_feet);
                        modal.querySelector('#modal-address').textContent = data.address;
                        modal.querySelector('#modal-image').style.backgroundImage =
                            `url('${data.image_path}')`;
                        modal.querySelector('.modal-description p').textContent =
                            data.description;

                        modal.classList.add('active');
                    });
            }
        });

        // Logout functionality
        document.getElementById('logout-btn').addEventListener('click', function () {
            if (confirm('Are you sure you want to log out?')) {
                window.location.href = 'logout.php';
            }
        });

        // Profile picture preview functionality
        function handleProfilePicPreview() {
            const fileInput = document.getElementById('profile-pic');
            const preview = document.getElementById('profile-pic-preview');

            fileInput.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();

                    reader.onload = function (e) {
                        preview.style.backgroundImage = `url(${e.target.result})`;
                    }

                    reader.readAsDataURL(this.files[0]);
                }
            });
        }

        // Property card click functionality
        document.querySelectorAll(".property-card").forEach((card) => {
            card.addEventListener("click", function (e) {
                if (!e.target.closest("button")) {
                    const propertyTitle = this.querySelector("h3").textContent;
                    alert(`Viewing details for: ${propertyTitle}`);
                }
            });
        });

        // Reset button functionality
        document.getElementById('reset-btn').addEventListener('click', function () {
            // Reset form fields
            document.getElementById('location-input').value = '';
            document.getElementById('type-select').value = 'any';
            document.getElementById('price-select').value = 'any';
            document.getElementById('beds-select').value = '0';

            // Also reset the form to clear any submitted data
            document.getElementById('search-form').reset();

            // Optionally reload the page to clear search results
            // window.location.href = window.location.pathname;
        });

        document.getElementById('viewings-link').addEventListener('click', function (e) {
            e.preventDefault();
            const section = document.getElementById('viewings-section');
            section.scrollIntoView({ behavior: 'smooth' });
        });

        // Add action handlers
        document.querySelectorAll('.reschedule-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                alert('Reschedule functionality would open here');
                // Would typically open a modal with calendar
            });
        });

        document.querySelectorAll('.cancel-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (confirm('Are you sure you want to cancel this viewing?')) {
                    this.closest('.viewing-card').remove();
                    showToast('Viewing cancelled successfully');
                }
            });
        });

        document.querySelectorAll('.confirm-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                this.closest('.viewing-card').querySelector('.status-badge').textContent = 'Confirmed';
                this.closest('.viewing-card').querySelector('.status-badge').className = 'status-badge confirmed';
                this.remove();
                showToast('Viewing confirmed!');
            });
        });

        // Add to existing toast function
        function showToast(message, isError = false) {
            const toast = document.createElement('div');
            toast.className = `toast ${isError ? 'error' : ''}`;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        document.getElementById('agents-link').addEventListener('click', function (e) {
            e.preventDefault();
            const section = document.getElementById('agents-section');
            section.scrollIntoView({ behavior: 'smooth' });
        });

        // Agent search functionality
        document.getElementById('agent-search-input').addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('.agent-card').forEach(card => {
                const agentName = card.querySelector('h4').textContent.toLowerCase();
                if (agentName.includes(searchTerm)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Schedule call functionality
        document.querySelectorAll('.schedule-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const card = this.closest('.agent-card');
                const agentName = card.querySelector('h4').textContent;
                const agentAvatar = card.querySelector('.agent-avatar').style.backgroundImage;
                const agentSpecialty = card.querySelector('.agent-meta span:nth-child(2)').textContent;

                document.getElementById('schedule-agent-name').textContent = agentName;
                document.getElementById('schedule-agent-specialty').textContent = agentSpecialty;
                document.getElementById('schedule-agent-avatar').style.backgroundImage = agentAvatar;

                document.getElementById('schedule-modal').classList.add('active');
            });
        });

        // Close modal functionality
        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.classList.remove('active');
                });
            });
        });

        // Update profile save functionality
        document.getElementById('save-profile-btn').addEventListener('click', function (e) {
            e.preventDefault();
            const username = document.getElementById('profile-username').value;
            const fileInput = document.getElementById('profile-pic');

            const formData = new FormData();
            formData.append('username', username);
            if (fileInput.files.length > 0) {
                formData.append('profile_pic', fileInput.files[0]);
            }

            fetch('update_profile.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update username display
                        document.getElementById('username-display').textContent = data.username;

                        // Update profile picture
                        const userAvatar = document.querySelector('.user-avatar');
                        if (data.profile_pic) {
                            userAvatar.innerHTML = `<img src="${data.profile_pic}" alt="Profile Picture">`;
                        } else {
                            // Fallback to initials
                            const initials = data.username.substring(0, 2).toUpperCase();
                            userAvatar.textContent = initials;
                        }

                        showToast('Profile updated successfully!');
                        document.getElementById('profile-modal').classList.remove('active');
                    } else {
                        showToast(data.error || 'Error updating profile', true);
                    }
                })
                .catch(error => {
                    showToast('An error occurred. Please try again.', true);
                });
        });

        // Add this to handle modal close on overlay click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize profile picture preview
            handleProfilePicPreview();

            // Add event listener for edit profile button
            document.getElementById('edit-username-btn').addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('profile-modal').classList.add('active');
            });
        });
    </script>
</body>

</html>
