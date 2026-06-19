<?php
// Database Configuration
// Replace these with your InfinityFree MySQL credentials
define('DB_HOST', 'sql207.epizy.com');  // Your InfinityFree MySQL host (internal)
define('DB_USERNAME', 'if0_42222215');      // Your InfinityFree MySQL username (no extra spaces)
define('DB_PASSWORD', '6dX59M2w3ljZge0');  // Your InfinityFree MySQL password
define('DB_DATABASE', 'if0_42222215_media_gateway');  // Your InfinityFree database name
define('DB_PORT', '3306');

// File Storage Configuration
define('UPLOAD_DIR', __DIR__ . '/../../uploads');
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB

// Get base URL dynamically
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $basePath = str_replace('/index.php', '', $script);
    $baseUrl = $protocol . "://" . $host . $basePath;
    // Ensure no double slashes in the URL
    $baseUrl = preg_replace('#/+#', '/', $baseUrl);
    return rtrim($baseUrl, '/');
}

define('BASE_URL', getBaseUrl());

// Allowed MIME types
function isAllowedMimeType($mimeType) {
    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/tiff',
        'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'
    ];
    return in_array($mimeType, $allowedTypes);
}

// Generate secure filename
function generateSecureFilename($originalFilename) {
    $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $hash = bin2hex(random_bytes(16));
    $timestamp = time();
    return "{$timestamp}-{$hash}." . strtolower($ext);
}

// Format file size
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Get file content type
function getContentType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska'
    ];
    return $mimeTypes[$ext] ?? 'application/octet-stream';
}

// Create database connection
function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
        
        // Create database if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_DATABASE . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE " . DB_DATABASE);
        
        // Create table if it doesn't exist
        $createTableSQL = "CREATE TABLE IF NOT EXISTS media_assets (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($createTableSQL);
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        die("Failed to connect to database");
    }
}