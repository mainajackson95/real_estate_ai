<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetDir = "uploads/";
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $fileName = uniqid() . '_' . basename($_FILES["image"]["name"]);
    $targetFile = $targetDir . $fileName;

    if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFile)) {
        // Save to database
        $conn = new mysqli("localhost", "root", "", "real_estate_ai_db");
        $propertyId = $_POST['property_id']; // Get from form
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

        $stmt = $conn->prepare("INSERT INTO property_images (property_id, image_path, is_primary) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $propertyId, $fileName, $isPrimary);
        $stmt->execute();
        $stmt->close();

        echo "Image uploaded successfully!";
    } else {
        echo "Error uploading image.";
    }
}
?>
