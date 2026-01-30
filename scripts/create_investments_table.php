<?php
// Create investments table script
// Run from project root: php scripts/create_investments_table.php

require_once __DIR__ . '/../config.php';

// Only allow CLI execution
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS investments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    plan_type VARCHAR(50) NOT NULL,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    roi_percentage DECIMAL(5,2) NOT NULL,
    maturity_date DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES profiles(id) ON DELETE CASCADE
)";

if ($conn->query($sql) === TRUE) {
    echo "Investments table created successfully.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$conn->close();
?>