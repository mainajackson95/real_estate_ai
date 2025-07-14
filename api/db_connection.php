<?php
// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "real_estate_ai_db"; // Make sure this matches your database name

// Create connection
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Set charset to utf8
    $conn->set_charset("utf8");

} catch (Exception $e) {
    // Log error and show user-friendly message
    error_log("Database connection error: " . $e->getMessage());

    // Check if database exists
    $temp_conn = new mysqli($servername, $username, $password);
    if ($temp_conn->connect_error) {
        die("Server connection failed: " . $temp_conn->connect_error);
    }

    $result = $temp_conn->query("SHOW DATABASES LIKE '$dbname'");
    if ($result->num_rows == 0) {
        die("Database '$dbname' does not exist. Please create it first using phpMyAdmin or MySQL command line.");
    }

    $temp_conn->close();
    die("Database connection failed: " . $e->getMessage());
}

// Function to close connection (optional - PHP will close automatically)
function closeConnection($connection)
{
    if ($connection) {
        $connection->close();
    }
}
?>