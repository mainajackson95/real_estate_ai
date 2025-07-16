<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "kaisec@2025";
$dbname = "real_estate_ai_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: admin_register.php");
    exit();
}

// Handle form submissions
$error_message = "";
$success_message = "";

// Image upload handling
$upload_dir = 'uploads/properties/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Add new property
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_property'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = (float) $_POST['price'];
    $address = trim($_POST['address']);
    $city = trim($_POST['city']);
    $state = trim($_POST['state']);
    $zip_code = trim($_POST['zip_code']);
    $bedrooms = (int) $_POST['bedrooms'];
    $bathrooms = (float) $_POST['bathrooms'];
    $square_feet = (int) $_POST['square_feet'];
    $property_type = trim($_POST['property_type']);
    $agent_id = (int) $_POST['agent_id'];
    $owner_id = (int) $_POST['owner_id'];

    // Initialize image_paths array
    $image_paths = [];

    // Handle image uploads
    if (!empty($_FILES['property_images']['name'][0])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];

        foreach ($_FILES['property_images']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['property_images']['name'][$key];
            $file_type = $_FILES['property_images']['type'][$key];
            $file_error = $_FILES['property_images']['error'][$key];

            // Validate file type and size (2MB max)
            if (
                $file_error === UPLOAD_ERR_OK &&
                in_array($file_type, $allowed_types) &&
                $_FILES['property_images']['size'][$key] <= 2097152
            ) {

                $new_file_name = uniqid('img_', true) . '.' . pathinfo($file_name, PATHINFO_EXTENSION);
                $destination = $upload_dir . $new_file_name;

                if (move_uploaded_file($tmp_name, $destination)) {
                    $image_paths[] = $destination;
                }
            }
        }
    }

    // Validate inputs
    if (empty($title) || empty($address) || empty($city) || empty($state) || empty($zip_code) || $agent_id <= 0 || $owner_id <= 0) {
        $error_message = "All required fields must be filled.";
    } else {
        // In the property insertion block:
        $stmt = $conn->prepare("INSERT INTO properties (...) VALUES (...)");
        // Change bind_param type from "ssdssssiiisii" to "ssdssssiiisdd"
        $stmt->bind_param(
            "ssdssssiiisdd",
            $title,
            $description,
            $price,
            $address,
            $city,
            $state,
            $zip_code,
            $bedrooms,
            $bathrooms,
            $square_feet,
            $property_type,
            $agent_id,
            $owner_id
        );

        if ($stmt->execute()) {
            $property_id = $stmt->insert_id;

            // Insert images
            if (!empty($image_paths)) {
                $stmt_img = $conn->prepare("INSERT INTO property_images (property_id, image_path, is_primary) VALUES (?, ?, ?)");
                $is_first = true;
                foreach ($image_paths as $image_path) {
                    $is_primary = $is_first ? 1 : 0;
                    $stmt_img->bind_param("isi", $property_id, $image_path, $is_primary);
                    $stmt_img->execute();
                    $is_first = false;
                }
                $stmt_img->close();
            }
            $success_message = "Property added successfully! ID: $property_id";
        } else {
            $error_message = "Error adding property: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Delete property
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_property'])) {
    $property_id = (int) $_POST['property_id'];

    if ($property_id > 0) {
        // Delete property images first
        $stmt = $conn->prepare("DELETE FROM property_images WHERE property_id = ?");
        $stmt->bind_param("i", $property_id);
        $stmt->execute();
        $stmt->close();

        // Then delete the property
        $stmt = $conn->prepare("DELETE FROM properties WHERE id = ?");
        $stmt->bind_param("i", $property_id);

        if ($stmt->execute()) {
            $success_message = "Property deleted successfully!";
        } else {
            $error_message = "Error deleting property: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Add new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = trim($_POST['role']);

    // Validate inputs
    if (empty($username) || empty($email) || empty($password) || empty($role)) {
        $error_message = "All user fields must be filled.";
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } else {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);

        if ($stmt->execute()) {
            $user_id = $stmt->insert_id;
            $success_message = "User added successfully! ID: $user_id";
        } else {
            $error_message = "Error adding user: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = (int) $_POST['user_id'];

    if ($user_id > 0) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            $success_message = "User deleted successfully!";
        } else {
            $error_message = "Error deleting user: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Get all users
$users = [];
$result = $conn->query("SELECT id, username, email, role FROM users ORDER BY id DESC");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Logout
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: admin_register.php");
    exit();
}

// Get all properties
$properties = [];
$result = $conn->query("SELECT * FROM properties ORDER BY id DESC");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | Real Estate AI</title>
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
            --success-green: #34d399;
            --danger-red: #f87171;
            --shadow-light: rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--neutral-bg);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header Styles */
        .admin-header {
            background: linear-gradient(135deg, var(--primary-teal), #0891b2);
            color: white;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.8rem;
            font-weight: 700;
        }

        .logo i {
            color: var(--accent-gold);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-details {
            text-align: right;
        }

        .user-details .username {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .user-details .role {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--accent-gold), var(--primary-teal));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            color: white;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        /* Main Content */
        .admin-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
            flex: 1;
            width: 100%;
        }

        .welcome-section {
            margin-bottom: 40px;
        }

        .welcome-section h1 {
            font-size: 2.2rem;
            margin-bottom: 15px;
            color: var(--text-dark);
        }

        .welcome-section p {
            color: var(--text-light);
            font-size: 1.1rem;
            max-width: 700px;
            line-height: 1.6;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
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

        .stat-icon.users {
            background: rgba(167, 139, 250, 0.15);
            color: var(--purple);
        }

        .stat-icon.revenue {
            background: rgba(254, 240, 138, 0.25);
            color: #d97706;
        }

        .stat-icon.views {
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

        /* Properties Section */
        .properties-section {
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 30px;
            margin-bottom: 40px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .section-header h2 {
            font-size: 1.8rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-header h2 i {
            color: var(--primary-teal);
        }

        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary {
            background: var(--primary-teal);
            color: white;
        }

        .btn-primary:hover {
            background: #0a7c72;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(13, 148, 136, 0.25);
        }

        .btn-danger {
            background: var(--danger-red);
            color: white;
        }

        .btn-danger:hover {
            background: #e53e3e;
            transform: translateY(-2px);
        }

        /* Properties Table */
        .property-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .property-table th {
            background-color: rgba(13, 148, 136, 0.1);
            color: var(--primary-teal);
            text-align: left;
            padding: 15px;
            font-weight: 600;
        }

        .property-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .property-table tr:hover {
            background-color: rgba(13, 148, 136, 0.03);
        }

        .actions-cell {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .action-edit {
            background: rgba(13, 148, 136, 0.1);
            color: var(--primary-teal);
        }

        .action-edit:hover {
            background: rgba(13, 148, 136, 0.2);
        }

        .action-delete {
            background: rgba(248, 113, 113, 0.1);
            color: var(--danger-red);
        }

        .action-delete:hover {
            background: rgba(248, 113, 113, 0.2);
        }

        /* Add Property Form */
        .add-property-form {
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 30px;
            margin-bottom: 40px;
        }

        .form-header {
            margin-bottom: 25px;
        }

        .form-header h2 {
            font-size: 1.8rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
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

        .form-group label i {
            color: var(--primary-teal);
            width: 20px;
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 1px solid var(--border-gray);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary-teal);
            outline: none;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        /* Messages */
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success-green);
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-red);
        }

        /* Footer */
        .admin-footer {
            background: var(--card-bg);
            border-top: 1px solid var(--border-gray);
            padding: 25px 40px;
            text-align: center;
            color: var(--text-light);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .admin-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            .user-info {
                flex-direction: column;
            }

            .user-details {
                text-align: center;
            }
        }

        @media (max-width: 768px) {
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .actions-cell {
                flex-direction: column;
            }

            .property-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="admin-header">
        <div class="logo">
            <i class="fas fa-home"></i>
            <span>RealEstate AI Admin</span>
        </div>

        <div class="user-info">
            <div class="user-details">
                <div class="username"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
                <div class="role"><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin')); ?></div>
            </div>
            <div class="user-avatar"><?php echo substr($_SESSION['username'] ?? 'A', 0, 1); ?></div>
            <form method="POST">
                <button type="submit" name="logout" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </form>
        </div>
    </header>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Welcome Section -->
        <section class="welcome-section">
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>!</h1>
            <p>Manage your real estate properties, view analytics, and handle all aspects of your listings from this
                admin panel.</p>
        </section>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon properties">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($properties); ?></h3>
                    <p>Total Properties</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>128</h3>
                    <p>Active Users</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon revenue">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <h3>$4.2M</h3>
                    <p>Total Revenue</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon views">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3>42K</h3>
                    <p>Monthly Views</p>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($error_message)): ?>
            <div class="message error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="message success-message">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <!-- Add User Form -->
        <section class="add-property-form">
            <div class="form-header">
                <h2><i class="fas fa-user-plus"></i> Add New User</h2>
            </div>

            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="username"><i class="fas fa-user"></i> Username*</label>
                        <input type="text" id="username" name="username" class="form-control" required
                            placeholder="john_doe">
                    </div>

                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email*</label>
                        <input type="email" id="email" name="email" class="form-control" required
                            placeholder="john@example.com">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password*</label>
                        <input type="password" id="password" name="password" class="form-control" required
                            minlength="6">
                    </div>

                    <div class="form-group">
                        <label for="role"><i class="fas fa-user-tag"></i> Role*</label>
                        <select id="role" name="role" class="form-control" required>
                            <option value="">Select role</option>
                            <option value="admin">Admin</option>
                            <option value="agent">Agent</option>
                            <option value="buyer">Buyer</option>
                            <option value="seller">Seller</option>
                            <option value="investor">Investor</option>
                        </select>
                    </div>
                </div>

                <button type="submit" name="add_user" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Add User
                </button>
            </form>
        </section>

        <!-- Users Table -->
        <section class="properties-section">
            <div class="section-header">
                <h2><i class="fas fa-users"></i> Manage Users</h2>
                <span><?php echo count($users); ?> Users</span>
            </div>

            <?php if (!empty($users)): ?>
                <table class="property-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['role']); ?></td>
                                <td class="actions-cell">
                                    <button class="action-btn action-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="delete_user" class="action-btn action-delete">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-properties">
                    <p>No users found. Add your first user using the form above.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Add Property Form -->
        <section class="add-property-form">
            <div class="form-header">
                <h2><i class="fas fa-plus-circle"></i> Add New Property</h2>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label for="title"><i class="fas fa-heading"></i> Property Title*</label>
                        <input type="text" id="title" name="title" class="form-control"
                            placeholder="Modern Downtown Apartment" required>
                    </div>

                    <div class="form-group">
                        <label for="property_type"><i class="fas fa-building"></i> Property Type*</label>
                        <select id="property_type" name="property_type" class="form-control" required>
                            <option value="">Select property type</option>
                            <option value="house">House</option>
                            <option value="apartment">Apartment</option>
                            <option value="condo">Condo</option>
                            <option value="townhouse">Townhouse</option>
                            <option value="land">Land</option>
                            <option value="commercial">Commercial</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="price"><i class="fas fa-dollar-sign"></i> Price ($)*</label>
                        <input type="number" id="price" name="price" class="form-control" required min="1000"
                            placeholder="425000">
                    </div>

                    <div class="form-group">
                        <label for="bedrooms"><i class="fas fa-bed"></i> Bedrooms*</label>
                        <input type="number" id="bedrooms" name="bedrooms" class="form-control" required min="0"
                            max="20" placeholder="2">
                    </div>

                    <div class="form-group">
                        <label for="bathrooms"><i class="fas fa-bath"></i> Bathrooms*</label>
                        <input type="number" id="bathrooms" name="bathrooms" class="form-control" required min="0"
                            max="20" step="0.5" placeholder="2.5">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="square_feet"><i class="fas fa-ruler-combined"></i> Square Feet*</label>
                        <input type="number" id="square_feet" name="square_feet" class="form-control" required min="100"
                            max="100000" placeholder="1250">
                    </div>

                    <div class="form-group">
                        <label for="address"><i class="fas fa-map-marker-alt"></i> Address*</label>
                        <input type="text" id="address" name="address" class="form-control" required
                            placeholder="123 Main St">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="city">City*</label>
                        <input type="text" id="city" name="city" class="form-control" required placeholder="New York">
                    </div>

                    <div class="form-group">
                        <label for="state">State*</label>
                        <input type="text" id="state" name="state" class="form-control" required placeholder="NY">
                    </div>

                    <div class="form-group">
                        <label for="zip_code">ZIP Code*</label>
                        <input type="text" id="zip_code" name="zip_code" class="form-control" required
                            placeholder="10001">
                    </div>
                </div>

                <div class="form-group">
                    <label for="description"><i class="fas fa-align-left"></i> Description</label>
                    <textarea id="description" name="description" class="form-control"
                        placeholder="Describe the property..."></textarea>
                </div>

                <div class="form-group">
                    <label for="property_images"><i class="fas fa-images"></i> Property Images</label>
                    <input type="file" id="property_images" name="property_images[]" class="form-control" multiple
                        accept="image/*">
                    <small class="text-muted">Select multiple images (JPEG, PNG, GIF)</small>
                </div>

                <div class="form-row">
                    <!-- Agent Selection -->
                    <div class="form-group">
                        <label for="agent_id"><i class="fas fa-user-tie"></i> Agent*</label>
                        <select id="agent_id" name="agent_id" class="form-control" required>
                            <option value="">Select Agent</option>
                            <?php foreach ($users as $user): ?>
                                <?php if ($user['role'] === 'agent'): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['username']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Owner Selection -->
                    <div class="form-group">
                        <label for="owner_id"><i class="fas fa-user"></i> Owner*</label>
                        <select id="owner_id" name="owner_id" class="form-control" required>
                            <option value="">Select Owner</option>
                            <?php foreach ($users as $user): ?>
                                <?php if ($user['role'] === 'seller' || $user['role'] === 'investor'): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['username']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" name="add_property" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Add Property
                </button>
            </form>
        </section>

        <!-- Properties Table -->
        <section class="properties-section">
            <div class="section-header">
                <h2><i class="fas fa-building"></i> Manage Properties</h2>
                <span><?php echo count($properties); ?> Properties</span>
            </div>

            <?php if (!empty($properties)): ?>
                <table class="property-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Address</th>
                            <th>Price</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($properties as $property): ?>
                            <tr>
                                <td><?php echo $property['id']; ?></td>
                                <td><?php echo htmlspecialchars($property['title']); ?></td>
                                <td><?php echo htmlspecialchars($property['address']); ?></td>
                                <td>$<?php echo number_format($property['price']); ?></td>
                                <td><?php echo htmlspecialchars($property['property_type']); ?></td>
                                <td class="actions-cell">
                                    <button class="action-btn action-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="property_id" value="<?php echo $property['id']; ?>">
                                        <button type="submit" name="delete_property" class="action-btn action-delete">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-properties">
                    <p>No properties found. Add your first property using the form above.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Footer -->
    <footer class="admin-footer">
        <p>&copy; <?php echo date('Y'); ?> Real Estate AI. All rights reserved.</p>
        <p>Admin Panel v1.0</p>
    </footer>

    <script>
        // Simple form validation
        document.querySelector('form').addEventListener('submit', function (e) {
            const requiredFields = this.querySelectorAll('input[required], select[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#f87171';
                } else {
                    field.style.borderColor = '';
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });

        // Delete property confirmation
        document.querySelectorAll('.action-delete').forEach(button => {
            button.addEventListener('click', function (e) {
                if (!confirm('Are you sure you want to delete this property? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>

</html>
