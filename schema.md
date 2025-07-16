#database_schema
CREATE DATABASE IF NOT EXISTS real_estate_ai_db;
USE real_estate_ai_db;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('agent', 'buyer', 'seller', 'investor') NOT NULL DEFAULT 'buyer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Properties Table
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(12,2) NOT NULL,
    property_type VARCHAR(50) NOT NULL,
    status ENUM('available', 'pending', 'sold', 'rented') NOT NULL DEFAULT 'available',
    bedrooms INT NOT NULL,
    bathrooms DECIMAL(3,1) NOT NULL,
    square_feet INT NOT NULL,
    lot_size DECIMAL(10,2),
    year_built YEAR,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(50) NOT NULL,
    zip_code VARCHAR(20) NOT NULL,
    country VARCHAR(50) DEFAULT 'USA',
    latitude DECIMAL(10,6),
    longitude DECIMAL(10,6),
    agent_id INT NOT NULL,
    owner_id INT NOT NULL,
    is_featured BOOLEAN DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES users(id),
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

-- Property Images Table
CREATE TABLE property_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    property_id INT NOT NULL,  // Must exist
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (property_id) REFERENCES properties(id)
);

-- Transactions Table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    property_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    commission_earned DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES users(id),
    FOREIGN KEY (property_id) REFERENCES properties(id)
);

-- Agent Ratings Table
CREATE TABLE agent_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    review TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES users(id)
);

-- Calendar Events Table
CREATE TABLE calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    event_type ENUM('viewing', 'closing', 'meeting', 'other') NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME,
    property_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES users(id),
    FOREIGN KEY (property_id) REFERENCES properties(id)
);

-- Indexes for better performance
CREATE INDEX idx_properties_agent ON properties(agent_id);
CREATE INDEX idx_properties_status ON properties(status);
CREATE INDEX idx_transactions_date ON transactions(transaction_date);

CREATE TABLE saved_properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,  -- This is the correct column name
    property_id INT NOT NULL,
    saved_at DATETIME NOT NULL,
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (property_id) REFERENCES properties(id),
    UNIQUE KEY unique_saved_property (buyer_id, property_id)
);

-- Add to properties table
ALTER TABLE properties
ADD INDEX idx_owner (owner_id),
ADD INDEX idx_agent (agent_id);

-- Add to property_images
ALTER TABLE property_images
ADD INDEX idx_property (property_id);

ALTER TABLE properties
DROP FOREIGN KEY properties_ibfk_2;

ALTER TABLE properties
ADD CONSTRAINT properties_ibfk_2
FOREIGN KEY (owner_id) REFERENCES users(id)
ON DELETE SET NULL;

ALTER TABLE messages
ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER message;

ALTER TABLE transactions
MODIFY commission_earned DECIMAL(10,2) NOT NULL DEFAULT 0.00;
