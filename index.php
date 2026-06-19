<?php
// Main entry point for the application

// Suppress HTML errors for API requests
if (strpos($_SERVER['REQUEST_URI'], '/api/') === 0) {
    ini_set('display_errors', 0);
    error_reporting(0);
    header('Content-Type: application/json');
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Define base path
define('BASE_PATH', __DIR__);

// Route requests
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Strip the script's directory prefix (handles subdirectory installations in XAMPP/InfinityFree)
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath !== '/' && $basePath !== '\\') {
    if (strpos($requestPath, $basePath) === 0) {
        $requestPath = substr($requestPath, strlen($basePath));
    }
}

$requestPath = trim($requestPath, '/');
$requestPath = preg_replace('#/+#', '/', $requestPath);

// Handle API requests
if (strpos($requestPath, 'api/') === 0) {
    error_log("API Request: " . $requestPath);  // Debug log
    try {
        if (!file_exists(__DIR__ . '/includes/api.php')) {
            error_log("API file not found: " . __DIR__ . '/includes/api.php');  // Debug log
            throw new Exception('API file not found');
        }
        require_once __DIR__ . '/includes/api.php';
    } catch (Throwable $e) {
        error_log("API Error: " . $e->getMessage());  // Debug log
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal Server Error: ' . $e->getMessage(),
            'file' => __DIR__ . '/includes/api.php'
        ]);
    }
    exit;
}

// Serve index.html as the main page for the root URL
if ($requestPath === '' || $requestPath === 'index') {
    header('Content-Type: text/html');
    readfile(__DIR__ . '/index.html');
    exit;
}

// Serve other static HTML files
$htmlFiles = [
    'upload' => 'upload.html',
    'docs' => 'docs.html'
];

$requestedFile = $htmlFiles[$requestPath] ?? null;

if ($requestedFile && file_exists(__DIR__ . '/' . $requestedFile)) {
    $ext = pathinfo($requestedFile, PATHINFO_EXTENSION);
    $contentTypes = [
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json'
    ];
    header('Content-Type: ' . ($contentTypes[$ext] ?? 'text/plain'));
    readfile(__DIR__ . '/' . $requestedFile);
    exit;
}

// 404 Not Found
http_response_code(404);
echo '<h1>404 Not Found</h1>';
echo '<p>The requested resource was not found on this server.</p>';