<?php
// Database Setup Script for MySQL Version
// Run this script once to create the database and table

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';

try {
    // Connect to MySQL server
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);

    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_DATABASE . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '" . DB_DATABASE . "' created or already exists.<br>";

    // Select database
    $pdo->exec("USE " . DB_DATABASE);

    // Create table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS media_assets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assetId VARCHAR(36) NOT NULL UNIQUE,
            originalFilename VARCHAR(255) NOT NULL,
            secureFilename VARCHAR(255) NOT NULL UNIQUE,
            mimeType VARCHAR(100) NOT NULL,
            fileSize INT NOT NULL,
            storagePath VARCHAR(255) NOT NULL,
            publicUrl VARCHAR(255) NOT NULL,
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (assetId),
            INDEX (createdAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Table 'media_assets' created or already exists.<br>";
    echo "Database setup completed successfully!<br>";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Database setup failed: " . htmlspecialchars($e->getMessage());
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo "Setup failed: " . htmlspecialchars($e->getMessage());
    exit;
}