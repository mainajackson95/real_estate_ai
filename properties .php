<?php
// =============================================
// CONFIGURATION & DATABASE CONNECTION
// =============================================
session_start(); // Start session if not already started, often useful for user roles/authentication

// Enable error reporting for debugging - REMOVE IN PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$servername = "localhost";
$username = "root";
$password = "kaisec@2025";
$dbname = "real_estate_ai_db"; // Ensure this matches your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// =============================================
// SEARCH & FILTER PARAMETERS
// =============================================
$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['search']) ? trim($_GET['search']) : '');
$propertyType = isset($_GET['type']) ? $_GET['type'] : '';
$priceRange = isset($_GET['price']) ? $_GET['price'] : '';
$bedrooms = isset($_GET['beds']) ? $_GET['beds'] : '';
$bathrooms = isset($_GET['baths']) ? $_GET['baths'] : '';
$squareFeet = isset($_GET['sqft']) ? $_GET['sqft'] : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// =============================================
// PAGINATION SETUP
// =============================================
$perPage = 9; // Properties per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1; // Current page, default to 1
$offset = ($page - 1) * $perPage;

// =============================================
// BUILD SQL CONDITIONS
// =============================================
$conditions = ["p.status = 'available'"];
$params = [];
$types = '';

// Search term condition
if (!empty($searchTerm)) {
    $conditions[] = "(p.title LIKE ? OR p.description LIKE ? OR p.address LIKE ? OR p.city LIKE ? OR p.state LIKE ?)";
    $searchParam = "%{$searchTerm}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    $types .= 'sssss';
}

// Property type filter
if (!empty($propertyType)) {
    $conditions[] = "p.property_type = ?";
    $params[] = $propertyType;
    $types .= 's';
}

// Price range filter
if (!empty($priceRange)) {
    $priceParts = explode('-', $priceRange);
    if (count($priceParts) === 2) {
        $minPrice = floatval($priceParts[0]);
        $maxPrice = floatval($priceParts[1]);
        $conditions[] = "p.price BETWEEN ? AND ?";
        $params[] = $minPrice;
        $params[] = $maxPrice;
        $types .= 'dd';
    }
}

// Bedrooms filter
if (!empty($bedrooms)) {
    $conditions[] = "p.bedrooms >= ?";
    $params[] = intval($bedrooms);
    $types .= 'i';
}

// Bathrooms filter
if (!empty($bathrooms)) {
    $conditions[] = "p.bathrooms >= ?";
    $params[] = floatval($bathrooms);
    $types .= 'd';
}

// Square feet filter
if (!empty($squareFeet)) {
    $sqftParts = explode('-', $squareFeet);
    if (count($sqftParts) === 2) {
        $minSqft = intval($sqftParts[0]);
        $maxSqft = intval($sqftParts[1]);
        $conditions[] = "p.square_feet BETWEEN ? AND ?";
        $params[] = $minSqft;
        $params[] = $maxSqft;
        $types .= 'ii';
    }
}

// Build WHERE clause
$whereClause = implode(' AND ', $conditions);

// Sort order
$orderBy = "p.created_at DESC";
switch ($sortBy) {
    case 'featured':
        $orderBy = "p.is_featured DESC, p.created_at DESC";
        break;
    case 'price_asc':
        $orderBy = "p.price ASC";
        break;
    case 'price_desc':
        $orderBy = "p.price DESC";
        break;
    case 'newest':
        $orderBy = "p.created_at DESC";
        break;
}

// =============================================
// COUNT QUERY WITH FILTERS
// =============================================
$countSql = "SELECT COUNT(*) AS total FROM properties p WHERE $whereClause";
$countStmt = $conn->prepare($countSql);

if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$countResult = $countStmt->get_result();

if (!$countResult) {
    die("Count query failed: " . $conn->error);
}

$totalProperties = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalProperties / $perPage);

// =============================================
// MAIN QUERY WITH FILTERS
// =============================================
$sql = "
    SELECT
        p.id, p.title, p.description, p.price, p.property_type, p.status,
        p.bedrooms, p.bathrooms, p.square_feet, p.lot_size, p.year_built,
        p.address, p.city, p.state, p.zip_code, p.country,
        p.latitude, p.longitude, p.agent_id, p.owner_id, p.is_featured, p.created_at,
        (SELECT image_path FROM property_images
         WHERE property_id = p.id
         ORDER BY is_primary DESC, id ASC
         LIMIT 1) AS image_url
    FROM properties p
    WHERE $whereClause
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";

// Add pagination parameters
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    die("Properties query failed: " . $conn->error);
}

$properties = [];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // FIXED: Properly handle image paths
        if (!empty($row['image_url'])) {
            // Check if the path already contains 'uploads/'
            if (strpos($row['image_url'], 'uploads/') === 0) {
                $row['image_url'] = $row['image_url'];
            } else {
                $row['image_url'] = 'uploads/' . $row['image_url'];
            }
        } else {
            // Use a placeholder if no image exists
            $row['image_url'] = 'images/placeholder.jpg';
        }

        // Ensure numeric fields are properly typed and handle potential nulls for display robustness
        $row['bathrooms'] = isset($row['bathrooms']) ? (float) $row['bathrooms'] : 0.0;
        $row['square_feet'] = isset($row['square_feet']) ? (int) $row['square_feet'] : 0;
        $row['bedrooms'] = isset($row['bedrooms']) ? (int) $row['bedrooms'] : 0; // Ensure bedrooms is also handled
        $row['price'] = isset($row['price']) ? (float) $row['price'] : 0.0;

        $properties[] = $row;
    }
}

$conn->close(); // Close the database connection
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Properties - Real Estate AI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --shadow-light: rgba(0, 0, 0, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Roboto", sans-serif;
            background-color: var(--neutral-bg);
            color: var(--text-dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, var(--primary-teal), #0891b2);
            color: #ffffff;
            padding: 60px 20px;
            border-radius: 8px;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }

        header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
            opacity: 0.2;
        }

        header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 15px;
            position: relative;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        header p {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto 30px;
            position: relative;
        }

        /* SEARCH BAR STYLES */
        .search-container {
            max-width: 700px;
            margin: 0 auto 30px;
            position: relative;
        }

        .search-box {
            display: flex;
            background: white;
            border-radius: 60px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .search-box input {
            flex: 1;
            border: none;
            padding: 18px 25px;
            font-size: 1.1rem;
            outline: none;
            background: rgba(255, 255, 255, 0.9);
        }

        .search-btn {
            width: 70px;
            background: var(--primary-teal);
            color: white;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .search-btn:hover {
            background: #0f766e;
        }

        /* END SEARCH BAR STYLES */

        .nav {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            position: relative;
        }

        .nav a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            padding: 12px 24px;
            border-radius: 30px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .nav a.active {
            background: rgba(255, 255, 255, 0.3);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .nav a:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .auth {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 20px;
            position: relative;
        }

        .auth a {
            padding: 12px 30px;
            background-color: var(--accent-gold);
            color: var(--text-dark);
            text-decoration: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .auth a:hover {
            background-color: #facc15;
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        /* Filter Section */
        .filters {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 40px;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-gray);
            border-radius: 8px;
            font-size: 1rem;
            background-color: white;
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .filter-btn {
            padding: 12px 25px;
            background: var(--primary-teal);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            background: #0f766e;
            transform: translateY(-2px);
        }

        .reset-btn {
            background: var(--border-gray);
            color: var(--text-dark);
        }

        .reset-btn:hover {
            background: #e2e8f0;
        }

        /* Property Grid */
        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 30px;
            margin-bottom: 50px;
        }

        .property-card {
            background: var(--card-bg);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .property-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }

        .property-image {
            height: 220px;
            position: relative;
            overflow: hidden;
        }

        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .favorite-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            color: var(--secondary-coral);
            font-size: 1.2rem;
            transition: all 0.3s ease;
            z-index: 10;
        }

        .view-btn {
            width: 100%;
            padding: 12px;
            background: var(--primary-teal);
            /* Solid background */
            color: white;
            border: none;
            border-radius: 8px;
            /* More rounded corners */
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            /* Remove underline */
            display: block;
            /* Make it a block element */
        }

        .view-btn:hover {
            background: #0f766e;
            /* Slightly darker on hover */
            transform: translateY(-2px);
            /* Move up slightly on hover */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            /* Add subtle shadow */
        }

        .favorite-btn:hover {
            background: white;
            transform: scale(1.1);
        }

        .favorite-btn.active {
            color: var(--secondary-coral);
        }

        /* MODAL REDESIGN */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85);
            z-index: 10000;
            overflow-y: auto;
            padding: 40px 20px;
            box-sizing: border-box;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.active {
            opacity: 1;
        }

        .modal-content {
            background-color: white;
            border-radius: 16px;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: modalOpen 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        @keyframes modalOpen {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .close-modal {
            position: absolute;
            top: 25px;
            right: 25px;
            font-size: 1.8rem;
            color: white;
            cursor: pointer;
            z-index: 100;
            background: rgba(0, 0, 0, 0.5);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .close-modal:hover {
            transform: rotate(90deg);
            background: rgba(0, 0, 0, 0.7);
        }

        .modal-header {
            position: relative;
            height: 500px;
            overflow: hidden;
        }

        .modal-main-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.5s ease;
        }

        .modal-image-nav {
            position: absolute;
            top: 50%;
            width: 100%;
            display: flex;
            justify-content: space-between;
            padding: 0 20px;
            transform: translateY(-50%);
            z-index: 10;
        }

        .nav-arrow {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--primary-teal);
            font-size: 1.5rem;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .nav-arrow:hover {
            background: white;
            transform: scale(1.1);
        }

        .modal-image-thumbnails {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 0 20px;
            z-index: 10;
        }

        .modal-thumbnail {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        }

        .modal-thumbnail:hover,
        .modal-thumbnail.active {
            transform: translateY(-5px);
            border-color: var(--primary-teal);
        }

        .modal-body {
            display: flex;
            flex-wrap: wrap;
            padding: 40px;
        }

        .modal-left {
            flex: 1;
            min-width: 300px;
            padding-right: 40px;
        }

        .modal-right {
            width: 350px;
            background: var(--neutral-bg);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .modal-title {
            font-size: 2.2rem;
            color: var(--text-dark);
            margin-bottom: 10px;
            line-height: 1.3;
        }

        .modal-price {
            font-size: 2rem;
            color: var(--primary-teal);
            font-weight: 700;
            margin-bottom: 20px;
        }

        .modal-location {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
            color: var(--text-light);
            font-size: 1.1rem;
        }

        .modal-location i {
            color: var(--primary-teal);
            font-size: 1.3rem;
        }

        .modal-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid var(--border-gray);
        }

        .meta-item {
            text-align: center;
            padding: 15px;
            background: rgba(13, 148, 136, 0.05);
            border-radius: 10px;
        }

        .meta-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-teal);
            margin-bottom: 5px;
        }

        .meta-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .modal-description {
            margin-bottom: 30px;
            line-height: 1.8;
            color: var(--text-dark);
            font-size: 1.05rem;
        }

        .modal-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .feature-item i {
            color: var(--primary-teal);
            width: 25px;
            text-align: center;
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

        .agent-card {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--border-gray);
        }

        .agent-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-teal);
        }

        .agent-info h4 {
            font-size: 1.3rem;
            margin-bottom: 5px;
            color: var(--text-dark);
        }

        .agent-info p {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .modal-actions {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .modal-btn {
            padding: 16px 25px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 1.05rem;
        }

        .contact-btn {
            background: var(--primary-teal);
            color: white;
            box-shadow: 0 4px 15px rgba(13, 148, 136, 0.3);
        }

        .contact-btn:hover {
            background: #0c857a;
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(13, 148, 136, 0.4);
        }

        .favorite-btn-modal {
            background: white;
            color: var(--text-dark);
            border: 2px solid var(--border-gray);
        }

        .favorite-btn-modal:hover {
            background: #f8f8f8;
            border-color: var(--primary-teal);
        }

        .modal-footer {
            display: flex;
            justify-content: space-between;
            padding: 25px 40px;
            background: var(--neutral-bg);
            border-top: 1px solid var(--border-gray);
            flex-wrap: wrap;
            gap: 15px;
        }

        .property-id {
            color: var(--text-light);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .social-share {
            display: flex;
            gap: 12px;
        }

        .share-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f0;
            color: var(--text-dark);
            transition: all 0.3s ease;
        }

        .share-btn:hover {
            transform: translateY(-3px);
            background: var(--primary-teal);
            color: white;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .modal-body {
                flex-direction: column;
                padding: 30px;
            }

            .modal-left {
                padding-right: 0;
                margin-bottom: 30px;
            }

            .modal-right {
                width: 100%;
            }

            .modal-header {
                height: 400px;
            }
        }

        @media (max-width: 768px) {
            .modal-header {
                height: 300px;
            }

            .modal-title {
                font-size: 1.8rem;
            }

            .modal-price {
                font-size: 1.7rem;
            }

            .modal-body {
                padding: 25px;
            }

            .modal-footer {
                padding: 20px 25px;
            }

            .nav-arrow {
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }

            .modal-thumbnail {
                width: 60px;
                height: 45px;
            }
        }

        @media (max-width: 576px) {
            .modal-header {
                height: 250px;
            }

            .modal-title {
                font-size: 1.5rem;
            }

            .modal-body {
                padding: 20px;
            }

            .modal-meta {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .modal-features {
                grid-template-columns: 1fr;
            }

            .modal-footer {
                flex-direction: column;
                align-items: center;
            }
        }

        .property-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: var(--accent-gold);
            color: #854d0e;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            z-index: 10;
        }

        .property-details {
            padding: 20px;
        }

        .property-details h3 {
            font-size: 1.4rem;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        .property-price {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-teal);
            margin-bottom: 15px;
        }

        .property-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            color: var(--text-light);
            font-size: 0.95rem;
            flex-wrap: wrap;
        }

        .property-meta span {
            display: flex;
            align-items: center;
        }

        .property-meta i {
            margin-right: 5px;
            color: var(--primary-teal);
        }

        .property-location {
            color: var(--text-light);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-description {
            margin-bottom: 20px;
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .view-btn {
            width: 100%;
            padding: 12px;
            background: var(--primary-teal);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-btn:hover {
            background: #0f766e;
            transform: translateY(-2px);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 50px;
        }

        .page-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--card-bg);
            border: 1px solid var(--border-gray);
            color: var(--text-dark);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .page-btn.active,
        .page-btn:hover {
            background: var(--primary-teal);
            color: white;
            border-color: var(--primary-teal);
        }

        .page-btn.dots {
            border: none;
            background: transparent;
            cursor: default;
        }

        .container header {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 50px;
        }

        .page-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--card-bg);
            border: 1px solid var(--border-gray);
            color: var(--text-dark);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            text-decoration: none;
            /* Remove underline */
        }

        .page-btn.active,
        .page-btn:hover {
            background: var(--primary-teal);
            color: white;
            border-color: var(--primary-teal);
            text-decoration: none;
            /* Ensure no underline on hover */
        }

        /* Footer */
        footer {
            background: var(--primary-teal);
            color: white;
            padding: 40px 0;
            margin-top: 80px;
            border-radius: 8px;
        }

        .footer-content {
            max-width: 800px;
            margin: 0 auto;
        }

        .footer-logo {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: inline-block;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            transition: opacity 0.3s ease;
        }

        .footer-links a:hover {
            opacity: 0.8;
        }

        .copyright {
            margin-top: 20px;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            header h1 {
                font-size: 2.2rem;
            }

            .property-grid {
                grid-template-columns: 1fr;
            }

            .nav {
                flex-direction: column;
                align-items: center;
            }

            .nav a {
                width: 80%;
                text-align: center;
            }

            .search-container {
                max-width: 90%;
            }

            .search-box input {
                padding: 15px 20px;
                font-size: 1rem;
            }

            .filter-row {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <h1>Find Your Dream Property</h1>
            <p>
                Browse our extensive collection of properties with AI-powered insights
            </p>

            <!-- Search Bar -->
            <form method="GET" action="properties.php" class="search-container">
                <div class="search-box">
                    <input type="text" placeholder="Search properties, locations, or keywords..." name="search"
                        value="<?= htmlspecialchars($searchTerm) ?>">
                    <button type="submit" class="search-btn">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>

            <!-- Navbar Links -->
            <div class="nav">
                <a href="index.html">Home</a>
                <a href="#" class="active">Properties</a>
                <a href="about.html">About</a>
                <a href="contact.html">Contact</a>
            </div>

            <div class="auth">
                <a href="signin.html"><i class="fas fa-sign-in-alt"></i> Sign In</a>
                <a href="register.html"><i class="fas fa-user-plus"></i> Register</a>
            </div>
        </header>

        <!-- Filters Section -->
        <form method="GET" action="properties.php">
            <input type="hidden" name="page" value="1">
            <section class="filters">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="propertyType">Property Type</label>
                        <select id="propertyType" name="type">
                            <option value="">All Types</option>
                            <option value="House" <?= $propertyType == 'House' ? 'selected' : '' ?>>House</option>
                            <option value="Apartment" <?= $propertyType == 'Apartment' ? 'selected' : '' ?>>Apartment
                            </option>
                            <option value="Condo" <?= $propertyType == 'Condo' ? 'selected' : '' ?>>Condo</option>
                            <option value="Land" <?= $propertyType == 'Land' ? 'selected' : '' ?>>Land</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="priceRange">Price Range</label>
                        <select id="priceRange" name="price">
                            <option value="">Any Price</option>
                            <option value="0-100000" <?= $priceRange == '0-100000' ? 'selected' : '' ?>>Under $100,000
                            </option>
                            <option value="100001-300000" <?= $priceRange == '100001-300000' ? 'selected' : '' ?>>$100,000
                                - $300,000</option>
                            <option value="300001-500000" <?= $priceRange == '300001-500000' ? 'selected' : '' ?>>$300,000
                                - $500,000</option>
                            <option value="500001-800000" <?= $priceRange == '500001-800000' ? 'selected' : '' ?>>$500,000
                                - $800,000</option>
                            <option value="800001-1200000" <?= $priceRange == '800001-1200000' ? 'selected' : '' ?>>
                                $800,000 - $1.2M</option>
                            <option value="1200001-999999999" <?= $priceRange == '1200001-999999999' ? 'selected' : '' ?>>
                                Over $1.2M</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="bedrooms">Bedrooms</label>
                        <select id="bedrooms" name="beds">
                            <option value="">Any</option>
                            <option value="1" <?= $bedrooms == '1' ? 'selected' : '' ?>>1+</option>
                            <option value="2" <?= $bedrooms == '2' ? 'selected' : '' ?>>2+</option>
                            <option value="3" <?= $bedrooms == '3' ? 'selected' : '' ?>>3+</option>
                            <option value="4" <?= $bedrooms == '4' ? 'selected' : '' ?>>4+</option>
                            <option value="5" <?= $bedrooms == '5' ? 'selected' : '' ?>>5+</option>
                        </select>
                    </div>
                </div>

                <div class="filter-row">
                    <div class="filter-group">
                        <label for="bathrooms">Bathrooms</label>
                        <select id="bathrooms" name="baths">
                            <option value="">Any</option>
                            <option value="1" <?= $bathrooms == '1' ? 'selected' : '' ?>>1+</option>
                            <option value="2" <?= $bathrooms == '2' ? 'selected' : '' ?>>2+</option>
                            <option value="3" <?= $bathrooms == '3' ? 'selected' : '' ?>>3+</option>
                            <option value="4" <?= $bathrooms == '4' ? 'selected' : '' ?>>4+</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="squareFeet">Square Feet</label>
                        <select id="squareFeet" name="sqft">
                            <option value="">Any Size</option>
                            <option value="0-1000" <?= $squareFeet == '0-1000' ? 'selected' : '' ?>>Under 1,000 sqft
                            </option>
                            <option value="1000-1500" <?= $squareFeet == '1000-1500' ? 'selected' : '' ?>>1,000 - 1,500
                                sqft</option>
                            <option value="1500-2000" <?= $squareFeet == '1500-2000' ? 'selected' : '' ?>>1,500 - 2,000
                                sqft</option>
                            <option value="2000-3000" <?= $squareFeet == '2000-3000' ? 'selected' : '' ?>>2,000 - 3,000
                                sqft</option>
                            <option value="3000-999999" <?= $squareFeet == '3000-999999' ? 'selected' : '' ?>>Over 3,000
                                sqft</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sortBy">Sort By</label>
                        <select id="sortBy" name="sort">
                            <option value="featured" <?= $sortBy == 'featured' ? 'selected' : '' ?>>Featured</option>
                            <option value="price_asc" <?= $sortBy == 'price_asc' ? 'selected' : '' ?>>Price: Low to High
                            </option>
                            <option value="price_desc" <?= $sortBy == 'price_desc' ? 'selected' : '' ?>>Price: High to Low
                            </option>
                            <option value="newest" <?= $sortBy == 'newest' ? 'selected' : '' ?>>Newest</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="filter-btn">Apply Filters</button>
                    <a href="properties.php" class="filter-btn reset-btn">Reset Filters</a>
                </div>
            </section>
        </form>

        <!-- Property Grid -->
        <div class="property-grid">
            <?php if (!empty($properties)): ?>
                <?php foreach ($properties as $property): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <img src="<?php echo htmlspecialchars($property['image_url']); ?>"
                                alt="<?php echo htmlspecialchars($property['title']); ?>" />
                            <button class="favorite-btn"><i class="far fa-heart"></i></button>
                            <?php if ($property['is_featured']): ?>
                                <div class="property-badge">Featured</div>
                            <?php endif; ?>
                        </div>
                        <div class="property-details">
                            <h3><?php echo htmlspecialchars($property['title']); ?></h3>
                            <div class="property-price">$<?php echo number_format($property['price'], 2); ?></div>
                            <div class="property-meta">
                                <span><i class="fas fa-bed"></i> <?php echo htmlspecialchars($property['bedrooms']); ?>
                                    Beds</span>
                                <span><i class="fas fa-bath"></i> <?php echo htmlspecialchars($property['bathrooms']); ?>
                                    Baths</span>
                                <span><i class="fas fa-ruler-combined"></i>
                                    <?php echo htmlspecialchars($property['square_feet']); ?> sqft</span>
                            </div>
                            <div class="property-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($property['city'] . ', ' . $property['state']); ?>
                            </div>
                            <p class="property-description">
                                <?php echo substr(htmlspecialchars($property['description']), 0, 100); ?>...
                            </p>
                            <button class="view-btn view-details-btn" data-id="<?php echo $property['id']; ?>">View
                                Details</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="grid-column: 1/-1; text-align: center; padding: 40px;">
                    No properties found matching your criteria.
                </p>
            <?php endif; ?>
        </div>

        <!-- Property Details Modal -->
        <div class="modal" id="propertyModal">
            <div class="modal-content">
                <span class="close-modal">&times;</span>

                <div class="modal-header">
                    <img src="" alt="Property Image" class="modal-main-image" id="modalMainImage">
                    <div class="modal-image-nav">
                        <div class="nav-arrow prev-arrow">
                            <i class="fas fa-chevron-left"></i>
                        </div>
                        <div class="nav-arrow next-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>
                    <div class="modal-image-thumbnails" id="imageThumbnails">
                        <!-- Thumbnails will be added here dynamically -->
                    </div>
                </div>

                <div class="modal-body">
                    <div class="modal-left">
                        <h2 class="modal-title" id="modalTitle"></h2>
                        <div class="modal-price" id="modalPrice"></div>

                        <div class="modal-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <span id="locationText"></span>
                        </div>

                        <div class="modal-meta">
                            <div class="meta-item">
                                <div class="meta-value" id="metaBedrooms"></div>
                                <div class="meta-label">Bedrooms</div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-value" id="metaBathrooms"></div>
                                <div class="meta-label">Bathrooms</div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-value" id="metaSqft"></div>
                                <div class="meta-label">Sq. Feet</div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-value" id="metaLotSize"></div>
                                <div class="meta-label">Lot Size</div>
                            </div>
                        </div>

                        <div class="modal-description" id="modalDescription"></div>

                        <div class="modal-features">
                            <div class="feature-item">
                                <i class="fas fa-home"></i>
                                <span>Type: <span id="modalPropertyType"></span></span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-tag"></i>
                                <span>Status: <span id="modalStatus"></span></span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-calendar"></i>
                                <span>Listed: <span id="modalCreatedAt"></span></span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-building"></i>
                                <span>Year Built: <span id="modalYearBuilt"></span></span>
                            </div>
                        </div>
                    </div>

                    <div class="modal-right">
                        <div class="agent-card">
                            <img src="https://images.unsplash.com/photo-1573497019940-1c28c88b4f3e?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=200&q=80"
                                alt="Agent" class="agent-avatar">
                            <div class="agent-info">
                                <h4>Alex Morgan</h4>
                                <p>Listing Agent</p>
                            </div>
                        </div>

                        <div class="modal-actions">
                            <button class="modal-btn contact-btn">
                                <i class="fas fa-envelope"></i> Contact Agent
                            </button>
                            <button class="modal-btn favorite-btn-modal" id="save-property-btn">
                                <i class="far fa-heart"></i> Save Property
                            </button>
                            <button class="modal-btn">
                                <i class="fas fa-print"></i> Print Details
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="property-id">
                        <i class="fas fa-fingerprint"></i>
                        Property ID: <span id="modalPropertyId"></span>
                    </div>
                    <div class="social-share">
                        <a href="#" class="share-btn">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="share-btn">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="share-btn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="#" class="share-btn">
                            <i class="fas fa-link"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div class="pagination">
            <?php
            // Create base URL with current filters
            $baseUrl = 'properties.php?';
            $queryParams = $_GET;
            unset($queryParams['page']);

            if ($page > 1):
                $prevParams = array_merge($queryParams, ['page' => $page - 1]);
                ?>
                <a href="<?= $baseUrl . http_build_query($prevParams) ?>" class="page-btn">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++):
                $pageParams = array_merge($queryParams, ['page' => $i]);
                ?>
                <a href="<?= $baseUrl . http_build_query($pageParams) ?>"
                    class="page-btn <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages):
                $nextParams = array_merge($queryParams, ['page' => $page + 1]);
                ?>
                <a href="<?= $baseUrl . http_build_query($nextParams) ?>" class="page-btn">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="footer-logo">RealEstate AI</div>
                <p>Intelligent solutions for modern real estate needs</p>

                <div class="footer-links">
                    <a href="index.php">Home</a>
                    <a href="properties.php">Properties</a>
                    <a href="about.php">About Us</a>
                    <a href="contact.php">Contact</a>
                    <a href="terms.php">Terms</a>
                    <a href="privacy.php">Privacy</a>
                </div>

                <div class="copyright">
                    &copy; <?php echo date('Y'); ?> Real Estate AI. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Toast notification function
            function showToast(message, isError = false) {
                const toast = document.getElementById('saveToast');
                toast.textContent = message;
                toast.className = 'toast' + (isError ? ' error' : '');
                toast.classList.add('show');

                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }

            // Generic save function
            function saveProperty(propertyId, buttonElement) {
                fetch('save_property.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ property_id: propertyId })
                })
                    .then(response => {
                        if (response.status === 401) {
                            window.location.href = `signin.html?redirect=${encodeURIComponent(window.location.href)}`;
                            return;
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data?.success) {
                            buttonElement.querySelector('i').className = 'fas fa-heart';
                            buttonElement.disabled = true;
                            showToast('Property saved to your favorites!');
                        } else if (data?.error) {
                            showToast(data.error, true);
                        }
                    })
                    .catch(error => {
                        console.error('Save error:', error);
                        showToast('Failed to save. Please try again.', true);
                    });
            }

            // Save button in modal
            document.getElementById('save-property-btn').addEventListener('click', function () {
                const propertyId = this.dataset.propertyId;
                saveProperty(propertyId, this);
            });

            // Favorite buttons on property cards
            document.querySelectorAll('.favorite-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const propertyCard = this.closest('.property-card');
                    const viewBtn = propertyCard.querySelector('.view-details-btn');
                    const propertyId = viewBtn.dataset.id;

                    saveProperty(propertyId, this);
                });
            });

            // View Details button click handler
            document.addEventListener('click', function (event) {
                if (event.target.classList.contains('view-details-btn')) {
                    const propertyId = event.target.getAttribute('data-id');
                    showPropertyDetails(propertyId);
                }
            });

            // Show property details in modal
            function showPropertyDetails(propertyId) {
                // Find the property in the PHP array
                const properties = <?php echo json_encode($properties); ?>;
                const property = properties.find(p => p.id == propertyId);

                if (property) {
                    // Update modal content
                    document.getElementById('modalTitle').textContent = property.title;
                    document.getElementById('modalPrice').textContent = '$' +
                        parseFloat(property.price).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });

                    document.getElementById('locationText').textContent =
                        property.address + ', ' + property.city + ', ' + property.state + ' ' + property.zip_code;

                    document.getElementById('metaBedrooms').textContent = property.bedrooms;
                    document.getElementById('metaBathrooms').textContent = property.bathrooms;
                    document.getElementById('metaSqft').textContent =
                        property.square_feet.toLocaleString();
                    document.getElementById('metaLotSize').textContent =
                        property.lot_size ? property.lot_size + ' acres' : 'N/A';
                    document.getElementById('modalYearBuilt').textContent =
                        property.year_built || 'N/A';

                    document.getElementById('modalDescription').textContent = property.description;
                    document.getElementById('modalPropertyType').textContent = property.property_type;
                    document.getElementById('modalStatus').textContent = property.status;
                    document.getElementById('modalPropertyId').textContent = 'REA-' + property.id;

                    // Format date
                    const date = new Date(property.created_at);
                    document.getElementById('modalCreatedAt').textContent =
                        date.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric'
                        });

                    // Set main image
                    const mainImage = document.getElementById('modalMainImage');
                    mainImage.src = property.image_url;
                    mainImage.alt = property.title;

                    // Create image thumbnails (using the same image for demo)
                    const thumbnailsContainer = document.getElementById('imageThumbnails');
                    thumbnailsContainer.innerHTML = '';

                    // Declare variables here
                    const propertyImages = [];
                    let currentImageIndex = 0;

                    // For demo, we'll create 5 thumbnails using the same image
                    for (let i = 0; i < 5; i++) {
                        propertyImages.push(property.image_url);
                        const thumbnail = document.createElement('img');
                        thumbnail.src = property.image_url;
                        thumbnail.alt = `Property image ${i + 1}`;
                        thumbnail.className = 'modal-thumbnail';
                        if (i === 0) thumbnail.classList.add('active');

                        thumbnail.addEventListener('click', function () {
                            // Update main image
                            mainImage.src = this.src;
                            currentImageIndex = i;

                            // Update active thumbnail
                            document.querySelectorAll('.modal-thumbnail').forEach(t => {
                                t.classList.remove('active');
                            });
                            this.classList.add('active');
                        });

                        thumbnailsContainer.appendChild(thumbnail);
                    }

                    // Set up arrow navigation
                    document.querySelector('.prev-arrow').addEventListener('click', function () {
                        currentImageIndex = (currentImageIndex - 1 + propertyImages.length) % propertyImages.length;
                        updateMainImage();
                    });

                    document.querySelector('.next-arrow').addEventListener('click', function () {
                        currentImageIndex = (currentImageIndex + 1) % propertyImages.length;
                        updateMainImage();
                    });

                    // Helper function for image updates
                    function updateMainImage() {
                        const mainImage = document.getElementById('modalMainImage');
                        mainImage.src = propertyImages[currentImageIndex];

                        // Update active thumbnail
                        const thumbnails = document.querySelectorAll('.modal-thumbnail');
                        thumbnails.forEach((t, i) => {
                            t.classList.toggle('active', i === currentImageIndex);
                        });

                        // Add zoom effect
                        mainImage.style.transform = 'scale(1.05)';
                        setTimeout(() => {
                            mainImage.style.transform = 'scale(1)';
                        }, 300);
                    }

                    // NEW: Update save button with property ID
                    const saveBtn = document.getElementById('save-property-btn');
                    saveBtn.dataset.propertyId = property.id;

                    // Reset button state for new property
                    saveBtn.disabled = false;
                    saveBtn.querySelector('i').className = 'far fa-heart';

                    // Show the modal
                    const modal = document.getElementById('propertyModal');
                    modal.style.display = 'block';
                    setTimeout(() => {
                        modal.classList.add('active');
                    }, 10);
                }
            }

            // NEW: Save button in modal
            document.getElementById('save-property-btn').addEventListener('click', function () {
                const propertyId = this.dataset.propertyId;
                saveProperty(propertyId, this);
            });

            // NEW: Favorite buttons on property cards
            document.querySelectorAll('.favorite-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const propertyId = this.closest('.property-card')
                        .querySelector('.view-details-btn').dataset.id;

                    saveProperty(propertyId, this);
                });
            });

            // Close modal functionality
            const modal = document.getElementById('propertyModal');
            const closeModal = document.querySelector('.close-modal');

            // Close modal when clicking X
            closeModal.addEventListener('click', function () {
                modal.classList.remove('active');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            });

            // Close modal when clicking outside content
            window.addEventListener('click', function (event) {
                if (event.target === modal) {
                    modal.classList.remove('active');
                    setTimeout(() => {
                        modal.style.display = 'none';
                    }, 300);
                }
            });
        });
    </script>
    <div class="toast" id="saveToast"></div>
</body>

</html>
