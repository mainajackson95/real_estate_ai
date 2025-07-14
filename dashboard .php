<?php
// Start session and connect to database
session_start();

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "real_estate_ai_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get logged-in agent ID from session
$agent_id = 1; // For demo - replace with $_SESSION['user_id'] in production

// Fetch agent information
$agent_info = [];
$stmt = $conn->prepare("SELECT username, email, role FROM users WHERE id = ?");
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $agent_info = $result->fetch_assoc();
}
$stmt->close();

// Get dashboard metrics
$metrics = [
    'activeListings' => 0,
    'activeClients' => 0,
    'commissionEarned' => 0,
    'avgRating' => 0
];

// Active Listings
$stmt = $conn->prepare("SELECT COUNT(*) AS total 
                       FROM properties 
                       WHERE user_id = ? 
                       AND status = 'active'");
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$result = $stmt->get_result();
$metrics['activeListings'] = $result->fetch_assoc()['total'] ?? 0;
$stmt->close();

// Active Clients (from inquiries and messages)
$sql = "SELECT COUNT(DISTINCT u.id) AS total
        FROM (
            SELECT inquirer_id AS client_id 
            FROM property_inquiries 
            WHERE agent_id = ?
            AND status IN ('new', 'contacted', 'scheduled')
            
            UNION
            
            SELECT sender_id AS client_id 
            FROM messages 
            WHERE receiver_id = ? 
            AND is_read = 0
            AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ) AS active_clients
        JOIN users u ON active_clients.client_id = u.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $agent_id, $agent_id);
$stmt->execute();
$result = $stmt->get_result();
$metrics['activeClients'] = $result->fetch_assoc()['total'] ?? 0;
$stmt->close();

// Commission Earned (placeholder - add transactions table in future)
$metrics['commissionEarned'] = 18400; // Hardcoded for now

// Average Rating (placeholder - add ratings table in future)
$metrics['avgRating'] = 4.9; // Hardcoded for now

// Get recent listings with primary images
$recentListings = [];
$sql = "SELECT p.id, p.title, p.price, p.status, p.bedrooms, 
               p.bathrooms, p.square_feet, p.address, pi.image_path AS thumbnail
        FROM properties p
        LEFT JOIN property_images pi ON p.id = pi.property_id AND pi.is_primary = 1
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC 
        LIMIT 3";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $recentListings[] = $row;
    }
}
$stmt->close();

// Close connection
$conn->close();

// Get initials for avatar
$initials = '';
if (!empty($agent_info['username'])) {
    $names = explode(' ', $agent_info['username']);
    foreach ($names as $name) {
        $initials .= strtoupper(substr($name, 0, 1));
    }
    $initials = substr($initials, 0, 2);
}
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
            background-size: cover;
            background-position: center;
            position: relative;
            background-color: #e0f2fe;
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
        }

        .status-active {
            color: var(--success-green);
        }

        .status-pending {
            color: var(--secondary-coral);
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
        }

        @media (max-width: 480px) {
            .tools-grid {
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

        <div class="user-info">
            <div class="user-avatar"><?php echo $initials; ?></div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($agent_info['username'] ?? 'Agent Name'); ?></h3>
                <span><?php echo ucfirst($agent_info['role'] ?? 'agent'); ?></span>
            </div>
        </div>

        <div class="nav-links">
            <a href="#" class="active">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            <a href="#">
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

        <button class="logout-btn" onclick="logout()">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </button>
    </div>

    <!-- Main Content -->
    <div class="content">
        <div class="dashboard-header">
            <h1>Agent Dashboard</h1>
            <div class="date-display">
                <i class="far fa-calendar"></i>
                <span id="current-date"><?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon listings">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $metrics['activeListings']; ?></h3>
                    <p>Active Listings</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon clients">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $metrics['activeClients']; ?></h3>
                    <p>Active Clients</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon commission">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($metrics['commissionEarned']); ?></h3>
                    <p>Commission Earned</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon rating">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $metrics['avgRating']; ?></h3>
                    <p>Average Rating</p>
                </div>
            </div>
        </div>

        <!-- Agent Tools -->
        <div class="tools-container">
            <div class="tools-header">
                <h2>Agent Tools</h2>
            </div>

            <div class="tools-grid">
                <div class="tool-card" onclick="openTool('add-listing')">
                    <div class="tool-icon">
                        <i class="fas fa-plus"></i>
                    </div>
                    <h3>Add Listing</h3>
                    <p>Create new property listing</p>
                </div>

                <div class="tool-card" onclick="openTool('pricing-tool')">
                    <div class="tool-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Pricing Tool</h3>
                    <p>AI-powered pricing analysis</p>
                </div>

                <div class="tool-card" onclick="openTool('virtual-tour')">
                    <div class="tool-icon">
                        <i class="fas fa-camera"></i>
                    </div>
                    <h3>Virtual Tour</h3>
                    <p>Create 3D property tours</p>
                </div>

                <div class="tool-card" onclick="openTool('esign')">
                    <div class="tool-icon">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <h3>eSign Documents</h3>
                    <p>Digital document signing</p>
                </div>

                <div class="tool-card" onclick="openTool('marketing')">
                    <div class="tool-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <h3>Marketing</h3>
                    <p>Promote your listings</p>
                </div>

                <div class="tool-card" onclick="openTool('ai-assistant')">
                    <div class="tool-icon">
                        <i class="fas fa-robot"></i>
                    </div>
                    <h3>AI Assistant</h3>
                    <p>Get property insights</p>
                </div>
            </div>
        </div>

        <!-- Listings Section -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>Recent Listings</h2>
                <a href="#" class="view-all">View All</a>
            </div>

            <div class="property-list">
                <?php foreach ($recentListings as $listing): ?>
                    <div class="property-card">
                        <div class="property-image" style="background: linear-gradient(rgba(0,0,0,0.2), rgba(0,0,0,0.2)), <?php
                        echo $listing['thumbnail'] ?
                            "url('" . htmlspecialchars($listing['thumbnail']) . "')" :
                            "linear-gradient(45deg, #0d9488, #0891b2)";
                        ?>; background-size: cover;">
                            <div class="property-status status-<?php echo strtolower($listing['status']); ?>">
                                <?php echo ucfirst($listing['status']); ?>
                            </div>
                        </div>
                        <div class="property-details">
                            <h3><?php echo htmlspecialchars($listing['title']); ?></h3>
                            <div class="property-price">$<?php echo number_format($listing['price']); ?></div>
                            <div class="property-meta">
                                <span><i class="fas fa-bed"></i> <?php echo $listing['bedrooms']; ?> Beds</span>
                                <span><i class="fas fa-bath"></i> <?php echo $listing['bathrooms']; ?> Baths</span>
                                <span><i class="fas fa-ruler-combined"></i>
                                    <?php echo number_format($listing['square_feet']); ?> sqft</span>
                            </div>
                            <p class="property-address"><?php echo htmlspecialchars($listing['address']); ?></p>
                            <div class="property-actions">
                                <button class="edit-btn" data-id="<?php echo $listing['id']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="insights-btn" data-id="<?php echo $listing['id']; ?>">
                                    <i class="fas fa-chart-line"></i> Insights
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Calendar Section -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>Upcoming Schedule</h2>
                <a href="#" class="view-all">View Calendar</a>
            </div>

            <div class="calendar-container">
                <div class="calendar-header">
                    <h3><?php echo date('F Y'); ?></h3>
                    <div class="calendar-controls">
                        <button id="prev-month">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button id="next-month">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>

                <div class="calendar-grid">
                    <div class="calendar-day">Sun</div>
                    <div class="calendar-day">Mon</div>
                    <div class="calendar-day">Tue</div>
                    <div class="calendar-day">Wed</div>
                    <div class="calendar-day">Thu</div>
                    <div class="calendar-day">Fri</div>
                    <div class="calendar-day">Sat</div>

                    <!-- Calendar dates would be generated dynamically in a real app -->
                    <div class="calendar-date"></div>
                    <div class="calendar-date">
                        <div class="date-num">30</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">1</div>
                    </div>
                    <div class="calendar-date active">
                        <div class="date-num">2</div>
                        <div class="calendar-event">Open House</div>
                        <div class="calendar-event viewing">Client Tour</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">3</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">4</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">5</div>
                    </div>

                    <div class="calendar-date">
                        <div class="date-num">6</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">7</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">8</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">9</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">10</div>
                        <div class="calendar-event closing">Closing</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">11</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">12</div>
                    </div>

                    <div class="calendar-date">
                        <div class="date-num">13</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">14</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">15</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">16</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">17</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">18</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">19</div>
                    </div>

                    <div class="calendar-date">
                        <div class="date-num">20</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">21</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">22</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">23</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">24</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">25</div>
                        <div class="calendar-event">Team Meeting</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">26</div>
                    </div>

                    <div class="calendar-date">
                        <div class="date-num">27</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">28</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">29</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">30</div>
                    </div>
                    <div class="calendar-date">
                        <div class="date-num">31</div>
                    </div>
                    <div class="calendar-date"></div>
                    <div class="calendar-date"></div>
                </div>
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

        // Logout functionality
        function logout() {
            if (confirm("Are you sure you want to log out?")) {
                // Clear session data and redirect
                sessionStorage.clear();
                window.location.href = "signin.html";
            }
        }

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

        // Tool card functionality
        function openTool(toolName) {
            alert(`Opening ${toolName.replace('-', ' ')} tool...`);
        }

        // Property action buttons
        document.querySelectorAll(".edit-btn").forEach((button) => {
            button.addEventListener("click", function (e) {
                e.stopPropagation();
                const propertyId = this.getAttribute('data-id');
                alert(`Editing property ID: ${propertyId}`);
                // window.location.href = `edit_property.php?id=${propertyId}`;
            });
        });

        document.querySelectorAll(".insights-btn").forEach((button) => {
            button.addEventListener("click", function (e) {
                e.stopPropagation();
                const propertyId = this.getAttribute('data-id');
                alert(`Showing insights for property ID: ${propertyId}`);
                // window.location.href = `property_insights.php?id=${propertyId}`;
            });
        });

        // Property card click functionality
        document.querySelectorAll(".property-card").forEach((card) => {
            card.addEventListener("click", function () {
                const propertyId = this.querySelector('.edit-btn').getAttribute('data-id');
                alert(`Viewing details for property ID: ${propertyId}`);
                // window.location.href = `property_details.php?id=${propertyId}`;
            });
        });
    </script>
</body>

</html>
