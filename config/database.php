<?php
// Database Configuration
// Replace these with your InfinityFree MySQL credentials
// define('DB_HOST', 'sql207.infinityfree.com');  // Your InfinityFree MySQL host
// define('DB_USERNAME', 'if0_42222215');      // Your InfinityFree MySQL username
// define('DB_PASSWORD', '6dX59M2w3ljZge0');  // Your InfinityFree MySQL password
// define('DB_DATABASE', 'if0_42222215_media_gateway');  // Your InfinityFree database name
// define('DB_PORT', '3306');

define('DB_HOST', 'localhost');  // Your InfinityFree MySQL host
define('DB_USERNAME', 'root');      // Your InfinityFree MySQL username
define('DB_PASSWORD', '');  // Your InfinityFree MySQL password
define('DB_DATABASE', 'custom_storage');  // Your InfinityFree database name
define('DB_PORT', '3306');

// File Storage Configuration
define('UPLOAD_DIR', __DIR__ . '/../uploads'); // Corrected path: config/../uploads = uploads/
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB

// Get base URL dynamically
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    
    // Get the script path relative to document root
    $script = $_SERVER['SCRIPT_NAME'];
    
    // Extract just the directory path (e.g., "/subdir" or "")
    $basePath = dirname($script);
    
    // If we're in the document root, basePath will be "/" or "."
    if ($basePath == '/' || $basePath == '\\' || $basePath == '.') {
        $basePath = '';
    }
    
    // Special handling for InfinityFree: prevent domain duplication
    // If basePath contains the host name, remove it to avoid duplication
    $hostParts = explode('.', $host);
    $firstHostPart = $hostParts[0]; // e.g., "media-gallery" from "media-gallery.free.nf"
    
    if (strpos($basePath, $firstHostPart) !== false) {
        // If basePath contains the host name, use just the host
        $baseUrl = $protocol . "://" . $host;
    } else {
        // Normal case: combine host and basePath
        $baseUrl = $protocol . "://" . $host . $basePath;
    }
    
    // Ensure no double slashes and trim trailing slash
    $baseUrl = rtrim(preg_replace('#/+#', '/', $baseUrl), '/');
    
    return $baseUrl;
}

define('BASE_URL', getBaseUrl());

// Allowed MIME types
function isAllowedMimeType($mimeType) {
    $allowedTypes = [
        // Images
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/tiff',
        // Videos
        'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska',
        // Documents
        'application/pdf',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        // Presentations
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Archives
        'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/x-7z-compressed', 'application/x-tar', 'application/x-gzip',
        // Audio
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/midi', 'audio/webm',
        // Data/Code
        'application/json', 'application/xml', 'text/xml', 'text/html', 'text/css', 'application/javascript'
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
        // Images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        // Videos
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        // Documents
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        // Presentations
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Archives
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar',
        'gz' => 'application/x-gzip',
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mid' => 'audio/midi',
        'midi' => 'audio/midi',
        // Data/Code
        'json' => 'application/json',
        'xml' => 'application/xml',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript'
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
        
        // Create table if it doesn't exist (with user_email column)
        $createTableSQL = "CREATE TABLE IF NOT EXISTS media_assets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assetId VARCHAR(36) NOT NULL UNIQUE,
            originalFilename VARCHAR(255) NOT NULL,
            secureFilename VARCHAR(255) NOT NULL UNIQUE,
            mimeType VARCHAR(100) NOT NULL,
            fileSize INT NOT NULL,
            storagePath VARCHAR(255) NOT NULL,
            publicUrl VARCHAR(255) NOT NULL,
            user_email VARCHAR(255) DEFAULT NULL,
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (assetId),
            INDEX (createdAt),
            INDEX (user_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($createTableSQL);

        // Run ALTER TABLE in case user_email column doesn't exist in an already created table
        try {
            // Check if column exists first
            $checkColumn = $pdo->query("SHOW COLUMNS FROM media_assets LIKE 'user_email'")->fetch();
            if (!$checkColumn) {
                $pdo->exec("ALTER TABLE media_assets ADD COLUMN user_email VARCHAR(255) DEFAULT NULL AFTER publicUrl, ADD INDEX (user_email)");
            }
        } catch (PDOException $e) {
            // Ignore error if column already exists
        }
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        die("Failed to connect to database");
    }
}