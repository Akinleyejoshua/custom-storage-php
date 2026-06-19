<?php
require_once __DIR__ . '/../config/database.php';

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set content type
header('Content-Type: application/json');

// Get database connection
$db = getDBConnection();

// Route API requests
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Extract endpoint from URI (remove query parameters first)
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$basePath = str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);
$endpoint = substr($requestPath, strlen($basePath));
$endpoint = trim($endpoint, '/');
$endpointParts = explode('/', $endpoint);

error_log("API Debug - Request URI: " . $requestUri);
error_log("API Debug - Request Path: " . $requestPath);
error_log("API Debug - Base Path: " . $basePath);
error_log("API Debug - Endpoint: " . $endpoint);
error_log("API Debug - Endpoint Parts: " . print_r($endpointParts, true));

try {
    // Handle different API endpoints
    if (count($endpointParts) >= 2 && $endpointParts[0] === 'api') {
        $apiEndpoint = $endpointParts[1];
        
        switch ($apiEndpoint) {
            case 'assets':
                handleAssetsRequest($db, $requestMethod, $endpointParts);
                break;
            case 'public':
                handlePublicRequest($db, $requestMethod, $endpointParts);
                break;
            default:
                throw new Exception('Endpoint not found: ' . $apiEndpoint, 404);
        }
    } else {
        throw new Exception('Invalid API endpoint format. Expected /api/{endpoint}', 400);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}

// Handle /api/assets requests
function handleAssetsRequest($db, $requestMethod, $endpointParts) {
    error_log("handleAssetsRequest called with method: " . $requestMethod . ", endpoint: " . implode('/', $endpointParts));
    
    switch ($requestMethod) {
        case 'GET':
            handleGetAssets($db, $endpointParts);
            break;
        case 'POST':
            handleUploadAsset($db);
            break;
        case 'DELETE':
            if (isset($endpointParts[2])) {
                handleDeleteAsset($db, $endpointParts[2]);
            } else {
                throw new Exception('Asset ID required for deletion', 400);
            }
            break;
        default:
            throw new Exception('Method not allowed', 405);
    }
}

// Handle /api/public requests
function handlePublicRequest($db, $requestMethod, $endpointParts) {
    if ($requestMethod !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }
    
    if (isset($endpointParts[2])) {
        handleStreamAsset($db, $endpointParts[2]);
    } else {
        throw new Exception('Asset ID required', 400);
    }
}

// Get all assets or a specific asset
function handleGetAssets($db, $endpointParts) {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $offset = ($page - 1) * $limit;
    
    if (isset($endpointParts[2])) {
        // Get specific asset
        $assetId = $endpointParts[2];
        $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ?");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();
        
        if (!$asset) {
            throw new Exception('Asset not found', 404);
        }
        
        echo json_encode([
            'success' => true,
            'data' => $asset
        ]);
    } else {
        // Get all assets with pagination
        $stmt = $db->prepare("SELECT * FROM media_assets ORDER BY createdAt DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $assets = $stmt->fetchAll();
        
        // Get total count for pagination
        $countStmt = $db->query("SELECT COUNT(*) FROM media_assets");
        $total = $countStmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => $assets,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
}

// Handle asset deletion
function handleDeleteAsset($db, $assetId) {
    // Find the asset first
    $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();
    
    if (!$asset) {
        throw new Exception('Asset not found', 404);
    }
    
    // Delete the file from storage
    $filePath = UPLOAD_DIR . '/' . $asset['storagePath'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Delete from database
    $stmt = $db->prepare("DELETE FROM media_assets WHERE assetId = ?");
    $stmt->execute([$assetId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Asset deleted successfully',
        'assetId' => $assetId
    ]);
}

// Handle file upload
function handleUploadAsset($db) {
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error', 400);
    }
    
    $file = $_FILES['file'];
    
    // Validate file size
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('File size exceeds maximum limit of ' . formatBytes(MAX_FILE_SIZE), 400);
    }
    
    // Validate MIME type
    $mimeType = mime_content_type($file['tmp_name']);
    if (!isAllowedMimeType($mimeType)) {
        throw new Exception('File type not allowed', 400);
    }
    
    // Generate secure filename
    $secureFilename = generateSecureFilename($file['name']);
    $targetPath = UPLOAD_DIR . '/' . $secureFilename;

    // Generate unique asset ID
    $assetId = bin2hex(random_bytes(16));

    // Debug logging
    error_log("Upload Debug - UPLOAD_DIR: " . UPLOAD_DIR);
    error_log("Upload Debug - targetPath: " . $targetPath);
    error_log("Upload Debug - tmp_name: " . $file['tmp_name']);
    error_log("Upload Debug - file exists: " . (file_exists($file['tmp_name']) ? 'yes' : 'no'));
    error_log("Upload Debug - is_uploaded_file: " . (is_uploaded_file($file['tmp_name']) ? 'yes' : 'no'));

    // Create upload directory if it doesn't exist
    if (!file_exists(UPLOAD_DIR)) {
        error_log("Upload Debug - Creating upload directory: " . UPLOAD_DIR);
        if (!mkdir(UPLOAD_DIR, 0755, true)) {
            $error = error_get_last();
            error_log("Upload Debug - mkdir failed: " . $error['message']);
            throw new Exception('Failed to create upload directory', 500);
        }
    }
    
    // Check if upload directory is writable
    if (!is_writable(UPLOAD_DIR)) {
        error_log("Upload Debug - Upload directory is not writable: " . UPLOAD_DIR);
        throw new Exception('Upload directory is not writable', 500);
    }
    
    // Move uploaded file
    error_log("Upload Debug - Attempting move_uploaded_file from {$file['tmp_name']} to {$targetPath}");
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        $error = error_get_last();
        error_log("Upload Debug - move_uploaded_file failed: " . $error['message']);
        // Check for common issues
        if (!is_uploaded_file($file['tmp_name'])) {
            error_log("Upload Debug - File is not a valid uploaded file");
        }
        if (!file_exists(UPLOAD_DIR)) {
            error_log("Upload Debug - Upload directory doesn't exist after mkdir");
        }
        throw new Exception('Failed to move uploaded file', 500);
    }
    
    error_log("Upload Debug - File successfully moved to: " . $targetPath);
    
    // Generate public URL pointing directly to the uploaded file
    $publicUrl = BASE_URL . '/uploads/' . $secureFilename;
    // Ensure the public URL is correctly formatted (no double slashes)
    $publicUrl = preg_replace('#/+#', '/', $publicUrl);
    
    // Store in database
    $stmt = $db->prepare("INSERT INTO media_assets (assetId, originalFilename, secureFilename, mimeType, fileSize, storagePath, publicUrl) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $assetId,
        $file['name'],
        $secureFilename,
        $mimeType,
        $file['size'],
        $secureFilename,
        $publicUrl
    ]);
    
    // Get the inserted asset by assetId (not id)
    $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();
    
    if (!$asset) {
        throw new Exception('Failed to retrieve uploaded asset', 500);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $asset
    ]);
}

// Stream asset for public access
function handleStreamAsset($db, $assetIdWithExt) {
    // Extract assetId and file extension from the URL
    $assetId = pathinfo($assetIdWithExt, PATHINFO_FILENAME);
    $fileExt = pathinfo($assetIdWithExt, PATHINFO_EXTENSION);
    
    // Find asset by assetId
    $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();
    
    if (!$asset) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }
    
    $filePath = __DIR__ . '/../../uploads/' . $asset['storagePath'];
    
    // Check if file exists
    if (!file_exists($filePath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }
    
    // Get file info
    $fileSize = filesize($filePath);
    $fileMime = getContentType($asset['secureFilename']);
    
    // Handle range requests for video streaming
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        $rangeParts = explode('=', $range, 2);
        $rangeRanges = explode('-', $rangeParts[1], 2);
        $rangeStart = intval($rangeRanges[0]);
        $rangeEnd = !empty($rangeRanges[1]) ? intval($rangeRanges[1]) : $fileSize - 1;
        
        if ($rangeStart >= $fileSize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        
        $rangeLength = $range_end - $range_start + 1;
        header("HTTP/1.1 206 Partial Content");
        header("Content-Range: bytes $range_start-$range_end/$file_size");
        header("Content-Length: $range_length");
    } else {
        header("Content-Length: $fileSize");
    }
    
    // Stream the file
    header("Content-Type: $fileMime");
    header('Accept-Ranges: bytes');
    
    $file = fopen($filePath, 'rb');
    if ($file === false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to open file']);
        exit;
    }
    
    if (isset($range_start)) {
        fseek($file, $range_start);
        $remaining = $range_length;
        while ($remaining > 0) {
            $chunk_size = min(8192, $remaining);
            echo fread($file, $chunk_size);
            $remaining -= $chunk_size;
            flush();
        }
    } else {
        fpassthru($file);
    }
    
    fclose($file);
    exit;
}