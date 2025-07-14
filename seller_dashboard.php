<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and is a seller
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'seller') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';

// Get seller's properties
$properties = [];
$stmt = $conn->prepare("
    SELECT p.*, 
           (SELECT image_path FROM property_images WHERE property_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image
    FROM properties p
    WHERE p.owner_id = ?
    ORDER BY p.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}

// Get stats for dashboard
$stats = [
    'total_properties' => 0,
    'active_properties' => 0,
    'pending_properties' => 0,
    'sold_properties' => 0
];

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold
    FROM properties
    WHERE owner_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stats = $result->fetch_assoc();
}

// Get recent inquiries
$inquiries = [];
$stmt = $conn->prepare("
    SELECT m.*, u.username as sender_name, p.title as property_title
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    JOIN properties p ON m.property_id = p.id
    WHERE p.owner_id = ?
    ORDER BY m.created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $inquiries[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Real Estate AI - Seller Dashboard</title>
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
            --warning-orange: #fbbf24;
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
            background: linear-gradient(45deg,
                    var(--accent-gold),
                    var(--secondary-coral));
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

        .stat-icon.properties {
            background: rgba(13, 148, 136, 0.15);
            color: var(--primary-teal);
        }

        .stat-icon.views {
            background: rgba(248, 113, 113, 0.15);
            color: var(--secondary-coral);
        }

        .stat-icon.inquiries {
            background: rgba(254, 240, 138, 0.25);
            color: #d97706;
        }

        .stat-icon.rating {
            background: rgba(52, 211, 153, 0.15);
            color: var(--success-green);
        }

        .stat-info h3 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        /* Dashboard Sections */
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

        .section-header button {
            background: var(--primary-teal);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
        }

        .section-header button:hover {
            background: #0f766e;
        }

        .section-header button i {
            margin-right: 8px;
        }

        /* Property List Styles */
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
            position: relative;
        }

        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .property-image {
            height: 180px;
            position: relative;
            background-size: cover;
            background-position: center;
            background-color: #e0e0e0;
            /* Fallback color */
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
            z-index: 2;
        }

        .status-active {
            color: var(--success-green);
        }

        .status-pending {
            color: var(--warning-orange);
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
            font-size: 0.9rem;
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

        .edit-btn {
            background: rgba(13, 148, 136, 0.1);
            color: var(--primary-teal);
        }

        .edit-btn:hover {
            background: rgba(13, 148, 136, 0.2);
        }

        .delete-btn {
            background: rgba(248, 113, 113, 0.1);
            color: var(--secondary-coral);
        }

        .delete-btn:hover {
            background: rgba(248, 113, 113, 0.2);
        }

        /* Inquiries Section */
        .inquiries-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .inquiry-item {
            display: flex;
            padding: 20px 0;
            border-bottom: 1px solid var(--border-gray);
            transition: background 0.2s;
        }

        .inquiry-item:hover {
            background: rgba(13, 148, 136, 0.03);
        }

        .inquiry-item:last-child {
            border-bottom: none;
        }

        .inquirer-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            margin-right: 15px;
            flex-shrink: 0;
            background: linear-gradient(45deg,
                    var(--secondary-coral),
                    var(--warning-orange));
        }

        .inquiry-content {
            flex-grow: 1;
        }

        .inquiry-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            align-items: center;
        }

        .inquiry-header h4 {
            font-size: 1.1rem;
        }

        .inquiry-time {
            color: var(--text-light);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
        }

        .inquiry-time i {
            margin-right: 5px;
        }

        .inquiry-property {
            color: var(--primary-teal);
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
        }

        .inquiry-property i {
            margin-right: 6px;
        }

        .inquiry-message {
            color: var(--text-dark);
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .inquiry-actions button {
            background: var(--primary-teal);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
        }

        .inquiry-actions button:hover {
            background: #0f766e;
        }

        .inquiry-actions button i {
            margin-right: 8px;
        }

        /* Empty State */
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

        /* Tab Navigation */
        .section-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-gray);
            margin-bottom: 20px;
        }

        .tab-btn {
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            color: var(--text-light);
            position: relative;
            transition: color 0.3s;
        }

        .tab-btn.active {
            color: var(--primary-teal);
            font-weight: 500;
        }

        .tab-btn.active::after {
            content: "";
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--primary-teal);
            border-radius: 3px 3px 0 0;
        }

        .tab-btn:hover:not(.active) {
            color: var(--text-dark);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: modalFadeIn 0.3s ease-out;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-gray);
        }

        .modal-header h2 {
            color: var(--text-dark);
            font-size: 1.5rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-light);
            cursor: pointer;
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: var(--secondary-coral);
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-dark);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-teal);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-gray);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: var(--primary-teal);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: #0f766e;
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-light);
            border: 1px solid var(--border-gray);
        }

        .btn-secondary:hover {
            background: var(--neutral-bg);
        }

        .status-radio-group {
            display: flex;
            gap: 20px;
        }

        .status-option {
            display: flex;
            align-items: center;
            gap: 8px;
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
                gap: 0;
            }
        }

        @media (max-width: 480px) {
            .modal-content {
                max-height: 85vh;
            }

            .modal-header h2 {
                font-size: 1.2rem;
            }

            .form-group input,
            .form-group textarea,
            .form-group select {
                padding: 10px 12px;
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

        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($username, 0, 2)) ?></div>
            <div class="user-details">
                <h3><?= htmlspecialchars($username) ?></h3>
                <span>Seller</span>
            </div>
        </div>

        <!-- Navigation links -->
        <div class="nav-links">
            <a href="#" class="active">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            <a href="my_properties.php">
                <i class="fas fa-home"></i>
                <span>My Properties</span>
            </a>
            <a href="inquiries.php">
                <i class="fas fa-comment-alt"></i>
                <span>Inquiries</span>
            </a>
            <a href="settings.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
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
            <h1>Seller Dashboard</h1>
            <div class="date-display">
                <i class="far fa-calendar"></i>
                <span id="current-date"><?= date('l, F j, Y') ?></span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon properties">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $stats['total'] ?></h3>
                    <p>Properties Listed</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon views">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $stats['active'] ?></h3>
                    <p>Active Properties</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon inquiries">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $stats['pending'] ?></h3>
                    <p>Pending Properties</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon rating">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $stats['sold'] ?></h3>
                    <p>Sold Properties</p>
                </div>
            </div>
        </div>

        <!-- My Properties Section -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>My Properties</h2>
                <button id="add-property-btn">
                    <i class="fas fa-plus"></i> Add New Property
                </button>
            </div>

            <div class="section-tabs">
                <button class="tab-btn active">All Properties</button>
                <button class="tab-btn">Active</button>
                <button class="tab-btn">Pending</button>
                <button class="tab-btn">Sold</button>
            </div>

            <div class="property-list">
                <?php if (!empty($properties)): ?>
                    <?php foreach ($properties as $property): ?>
                        <?php
                        $status_class = '';
                        if ($property['status'] == 'available')
                            $status_class = 'status-active';
                        elseif ($property['status'] == 'pending')
                            $status_class = 'status-pending';
                        else
                            $status_class = 'status-sold';
                        ?>
                        <div class="property-card" data-id="<?= $property['id'] ?>">
                            <div class="property-image" style="background-image: url('<?=
                                !empty($property['primary_image']) ?
                                htmlspecialchars($property['primary_image']) :
                                'images/property-placeholder.jpg'
                                ?>')">
                                <div class="property-status <?= $status_class ?>">
                                    <?= ucfirst($property['status']) ?>
                                </div>
                            </div>
                            <div class="property-details">
                                <h3><?= htmlspecialchars($property['title']) ?></h3>
                                <div class="property-price">$<?= number_format($property['price'], 2) ?></div>
                                <div class="property-meta">
                                    <span><i class="fas fa-bed"></i> <?= $property['bedrooms'] ?> Beds</span>
                                    <span><i class="fas fa-bath"></i> <?= $property['bathrooms'] ?> Baths</span>
                                    <span><i class="fas fa-ruler-combined"></i> <?= number_format($property['square_feet']) ?>
                                        sqft</span>
                                </div>
                                <p class="property-address">
                                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($property['address']) ?>
                                </p>
                                <div class="property-actions">
                                    <button class="edit-btn" data-id="<?= $property['id'] ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="delete-btn" data-id="<?= $property['id'] ?>">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-home"></i>
                        <p>You haven't listed any properties yet. Click "Add New Property" to get started!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Activity Section -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>Recent Inquiries</h2>
                <a href="inquiries.php" class="modal-btn btn-primary">
                    <i class="fas fa-list"></i> View All
                </a>
            </div>

            <div class="inquiries-container">
                <?php if (!empty($inquiries)): ?>
                    <?php foreach ($inquiries as $inquiry): ?>
                        <div class="inquiry-item">
                            <div class="inquirer-avatar">
                                <?= strtoupper(substr($inquiry['sender_name'], 0, 2)) ?>
                            </div>
                            <div class="inquiry-content">
                                <div class="inquiry-header">
                                    <h4><?= htmlspecialchars($inquiry['sender_name']) ?></h4>
                                    <div class="inquiry-time">
                                        <i class="far fa-clock"></i>
                                        <?= date('M j, g:i a', strtotime($inquiry['created_at'])) ?>
                                    </div>
                                </div>
                                <div class="inquiry-property">
                                    <i class="fas fa-home"></i> <?= htmlspecialchars($inquiry['property_title']) ?>
                                </div>
                                <p class="inquiry-message">
                                    <?= htmlspecialchars(substr($inquiry['message'], 0, 100)) ?>
                                    <?= strlen($inquiry['message']) > 100 ? '...' : '' ?>
                                </p>
                                <div class="inquiry-actions">
                                    <a href="view_inquiry.php?id=<?= $inquiry['id'] ?>" class="modal-btn btn-primary">
                                        <i class="fas fa-reply"></i> Reply
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-envelope"></i>
                        <p>No inquiries yet. When buyers contact you about your properties, they'll appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Property Modal -->
    <div id="property-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Property</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="property-form" action="add_property_handler.php" method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="property-title">Property Title*</label>
                        <input type="text" id="property-title" name="title" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="property-price">Price ($)*</label>
                            <input type="number" id="property-price" name="price" min="0" step="1000" required>
                        </div>
                        <div class="form-group">
                            <label for="property-type">Property Type*</label>
                            <select id="property-type" name="property_type" required>
                                <option value="House">House</option>
                                <option value="Apartment">Apartment</option>
                                <option value="Condo">Condo</option>
                                <option value="Townhouse">Townhouse</option>
                                <option value="Land">Land</option>
                                <option value="Commercial">Commercial</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="property-beds">Beds*</label>
                            <input type="number" id="property-beds" name="bedrooms" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="property-baths">Baths*</label>
                            <input type="number" id="property-baths" name="bathrooms" min="0" step="0.5" required>
                        </div>
                        <div class="form-group">
                            <label for="property-sqft">Square Feet*</label>
                            <input type="number" id="property-sqft" name="square_feet" min="0" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="property-address">Address*</label>
                        <input type="text" id="property-address" name="address" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="property-city">City*</label>
                            <input type="text" id="property-city" name="city" required>
                        </div>
                        <div class="form-group">
                            <label for="property-state">State*</label>
                            <input type="text" id="property-state" name="state" required>
                        </div>
                        <div class="form-group">
                            <label for="property-zip">ZIP Code*</label>
                            <input type="text" id="property-zip" name="zip_code" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="property-images">Images (First image will be primary)</label>
                        <input type="file" id="property-images" name="images[]" multiple accept="image/*">
                    </div>

                    <div class="form-group">
                        <label for="property-description">Description*</label>
                        <textarea id="property-description" name="description" required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="modal-btn btn-secondary" id="cancel-property">
                    Cancel
                </button>
                <button type="submit" form="property-form" class="modal-btn btn-primary" id="save-property">
                    Add Property
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Property Modal -->
    <div id="edit-property-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Property</h2>
                <button class="close-edit-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="edit-property-form" action="edit_property.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" id="edit-property-id" name="id">

                    <!-- Same fields as add property form, but populated with existing data -->
                    <!-- ... -->

                </form>
            </div>
            <div class="modal-footer">
                <button class="modal-btn btn-secondary" id="cancel-edit-property">
                    Cancel
                </button>
                <button type="submit" form="edit-property-form" class="modal-btn btn-primary" id="update-property">
                    Update Property
                </button>
            </div>
        </div>
    </div>

    <script>
        // Set current date
        const now = new Date();
        const options = {
            weekday: "long",
            year: "numeric",
            month: "long",
            day: "numeric",
        };
        document.getElementById("current-date").textContent =
            now.toLocaleDateString("en-US", options);

        // Modal elements
        const modal = document.getElementById("property-modal");
        const openModalBtn = document.getElementById("add-property-btn");
        const closeModalBtn = document.querySelector(".close-modal");
        const cancelBtn = document.getElementById("cancel-property");
        const saveBtn = document.getElementById("save-property");
        const form = document.getElementById("property-form");

        // Open modal function
        function openModal() {
            modal.style.display = "flex";
            document.body.style.overflow = "hidden"; // Prevent scrolling
        }

        // Close modal function
        function closeModal() {
            modal.style.display = "none";
            document.body.style.overflow = "auto"; // Re-enable scrolling
            form.reset(); // Reset form fields
        }

        // Event listeners for modals
        openModalBtn.addEventListener("click", openModal);
        closeModalBtn.addEventListener("click", closeModal);
        cancelBtn.addEventListener("click", closeModal);

        // Close modal when clicking outside the modal content
        window.addEventListener("click", (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });

        // Logout functionality
        document.querySelector(".logout-btn").addEventListener("click", function () {
            if (confirm("Are you sure you want to log out?")) {
                window.location.href = "logout.php";
            }
        });

        // Property edit click handlers - FIXED
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const propertyId = this.getAttribute('data-id');
                window.location.href = `edit_property.php?id=${propertyId}`;
            });
        });

        // Property delete click handlers - FIXED
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const propertyId = this.getAttribute('data-id');
                if (confirm('Are you sure you want to delete this property?')) {
                    window.location.href = `delete_property.php?id=${propertyId}`;
                }
            });
        });

        // Tab functionality
        document.querySelectorAll(".tab-btn").forEach((tab) => {
            tab.addEventListener("click", function () {
                // Remove active class from all tabs
                document.querySelectorAll(".tab-btn").forEach((t) => {
                    t.classList.remove("active");
                });

                // Add active class to clicked tab
                this.classList.add("active");

                // Filter functionality would go here in a real app
                alert(`Filtering properties by: ${this.textContent}`);
            });
        });
    </script>
</body>

</html>
