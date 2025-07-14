<?php
require_once 'config.php';

class AuthController
{
    private $conn;

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
    }

    public function register()
    {
        try {
            // Get JSON input
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            // Validate input
            if (!$data) {
                send_error_response('Invalid JSON input');
            }

            $required_fields = ['username', 'email', 'password', 'role'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    send_error_response("$field is required");
                }
            }

            // Sanitize inputs
            $username = sanitize_input($data['username']);
            $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
            $password = $data['password'];
            $role = sanitize_input($data['role']);

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                send_error_response('Invalid email format');
            }

            // Validate role
            $allowed_roles = ['agent', 'buyer', 'admin'];
            if (!in_array($role, $allowed_roles)) {
                send_error_response('Invalid role. Must be: agent, buyer, or admin');
            }

            // Check password strength
            if (strlen($password) < 6) {
                send_error_response('Password must be at least 6 characters long');
            }

            // Check if email already exists
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                send_error_response('Email already exists');
            }

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Generate UUID
            $uuid = generate_uuid();

            // Insert user
            $stmt = $this->conn->prepare("INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $uuid, $username, $email, $hashed_password, $role);

            if ($stmt->execute()) {
                send_success_response([
                    'user_id' => $uuid,
                    'username' => $username,
                    'email' => $email,
                    'role' => $role
                ], 'User registered successfully');
            } else {
                send_error_response('Registration failed: ' . $this->conn->error, 500);
            }

        } catch (Exception $e) {
            send_error_response('Registration error: ' . $e->getMessage(), 500);
        }
    }

    public function login()
    {
        try {
            // Get JSON input
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            // Validate input
            if (!$data || empty($data['email']) || empty($data['password'])) {
                send_error_response('Email and password are required');
            }

            $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
            $password = $data['password'];

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                send_error_response('Invalid email format');
            }

            // Get user from database
            $stmt = $this->conn->prepare("SELECT id, username, email, password, role FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                send_error_response('Invalid email or password');
            }

            $user = $result->fetch_assoc();

            // Verify password
            if (!password_verify($password, $user['password'])) {
                send_error_response('Invalid email or password');
            }

            // Start session and store user data
            start_secure_session();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];

            send_success_response([
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role']
            ], 'Login successful');

        } catch (Exception $e) {
            send_error_response('Login error: ' . $e->getMessage(), 500);
        }
    }

    public function logout()
    {
        start_secure_session();
        session_destroy();
        send_success_response([], 'Logout successful');
    }

    public function get_current_user()
    {
        if (!is_authenticated()) {
            send_error_response('User not authenticated', 401);
        }

        $user_id = get_current_user_id();

        $stmt = $this->conn->prepare("SELECT id, username, email, role, created_at FROM users WHERE id = ?");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            send_error_response('User not found', 404);
        }

        $user = $result->fetch_assoc();
        send_success_response($user);
    }
}

// Handle requests
$auth = new AuthController();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'POST':
        switch ($action) {
            case 'register':
                $auth->register();
                break;
            case 'login':
                $auth->login();
                break;
            case 'logout':
                $auth->logout();
                break;
            default:
                send_error_response('Invalid action');
        }
        break;
    case 'GET':
        switch ($action) {
            case 'user':
                $auth->get_current_user();
                break;
            default:
                send_error_response('Invalid action');
        }
        break;
    default:
        send_error_response('Method not allowed', 405);
}
?>
