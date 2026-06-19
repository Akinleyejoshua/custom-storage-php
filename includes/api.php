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

// Polyfill for getallheaders() if it is not supported in the host environment (e.g. Nginx/FastCGI on InfinityFree)
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

// Helper to retrieve authenticated user email
function getAuthenticatedUserEmail() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (empty($authHeader)) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }
    
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
        if (filter_var($token, FILTER_VALIDATE_EMAIL)) {
            return $token;
        }
        $decoded = base64_decode($token, true);
        if ($decoded !== false && filter_var($decoded, FILTER_VALIDATE_EMAIL)) {
            return $decoded;
        }
        return $token;
    }
    
    $xEmail = $headers['X-User-Email'] ?? $headers['x-user-email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '';
    if (!empty($xEmail)) {
        return trim($xEmail);
    }
    
    throw new Exception('Unauthorized: Active email session required', 401);
}

try {
    // Handle different API endpoints
    if (count($endpointParts) >= 2 && $endpointParts[0] === 'api') {
        $apiEndpoint = $endpointParts[1];
        
        switch ($apiEndpoint) {
            case 'assets':
                handleAssetsRequest($db, $requestMethod, $endpointParts);
                break;
            case 'folders':
                handleFoldersRequest($db, $requestMethod, $endpointParts);
                break;
            case 'public':
                handlePublicRequest($db, $requestMethod, $endpointParts);
                break;
            case 'auth':
                handleAuthRequest($requestMethod, $endpointParts);
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

// Handle /api/auth requests
function handleAuthRequest($requestMethod, $endpointParts) {
    if ($requestMethod !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }
    
    $action = $endpointParts[2] ?? '';
    if ($action === 'login') {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address', 400);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'email' => $email
        ]);
    } else {
        throw new Exception('Invalid auth action', 400);
    }
}

// Handle /api/folders requests
function handleFoldersRequest($db, $requestMethod, $endpointParts) {
    $email = getAuthenticatedUserEmail();
    
    switch ($requestMethod) {
        case 'GET':
            $stmt = $db->prepare("SELECT * FROM folders WHERE user_email = ? ORDER BY name ASC");
            $stmt->execute([$email]);
            $folders = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $folders]);
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Folder name is required', 400);
            }
            
            $folderId = bin2hex(random_bytes(16));
            
            $stmt = $db->prepare("INSERT INTO folders (folderId, name, user_email) VALUES (?, ?, ?)");
            $stmt->execute([$folderId, $name, $email]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Folder created successfully',
                'data' => [
                    'folderId' => $folderId,
                    'name' => $name,
                    'user_email' => $email
                ]
            ]);
            break;
            
        case 'DELETE':
            if (!isset($endpointParts[2])) {
                throw new Exception('Folder ID is required for deletion', 400);
            }
            $folderId = $endpointParts[2];
            
            // Verify folder ownership
            $stmt = $db->prepare("SELECT * FROM folders WHERE folderId = ? AND user_email = ?");
            $stmt->execute([$folderId, $email]);
            $folder = $stmt->fetch();
            if (!$folder) {
                throw new Exception('Folder not found or access denied', 404);
            }
            
            // Update all files inside this folder to move them back to root (folderId = NULL)
            // This leaves the actual files on disk and public URLs completely untouched!
            $stmt = $db->prepare("UPDATE media_assets SET folderId = NULL WHERE folderId = ? AND user_email = ?");
            $stmt->execute([$folderId, $email]);
            
            // Delete folder record
            $stmt = $db->prepare("DELETE FROM folders WHERE folderId = ?");
            $stmt->execute([$folderId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Folder deleted successfully. Assets moved to root.',
                'folderId' => $folderId
            ]);
            break;
            
        default:
            throw new Exception('Method not allowed', 405);
    }
}

// Handle /api/assets requests
function handleAssetsRequest($db, $requestMethod, $endpointParts) {
    error_log("handleAssetsRequest called with method: " . $requestMethod . ", endpoint: " . implode('/', $endpointParts));
    
    switch ($requestMethod) {
        case 'GET':
            handleGetAssets($db, $endpointParts);
            break;
        case 'POST':
            if (isset($endpointParts[2]) && $endpointParts[2] === 'move') {
                handleBatchMoveAssets($db);
            } elseif (isset($endpointParts[2]) && isset($endpointParts[3]) && $endpointParts[3] === 'move') {
                handleMoveAsset($db, $endpointParts[2]);
            } elseif (isset($endpointParts[2]) && isset($endpointParts[3]) && $endpointParts[3] === 'rename') {
                handleRenameAsset($db, $endpointParts[2]);
            } else {
                handleUploadAsset($db);
            }
            break;
        case 'DELETE':
            if (isset($endpointParts[2])) {
                handleDeleteAsset($db, $endpointParts[2]);
            } else {
                handleBatchDeleteAssets($db);
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
    $email = getAuthenticatedUserEmail();
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 24;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $offset = ($page - 1) * $limit;
    
    if (isset($endpointParts[2])) {
        // Get specific asset
        $assetId = $endpointParts[2];
        $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ? AND (user_email = ? OR user_email IS NULL)");
        $stmt->execute([$assetId, $email]);
        $asset = $stmt->fetch();
        
        if (!$asset) {
            throw new Exception('Asset not found or access denied', 404);
        }
        
        echo json_encode([
            'success' => true,
            'data' => $asset
        ]);
    } else {
        // Filter by folderId
        $folderId = $_GET['folderId'] ?? null;
        
        if ($folderId === 'all') {
            // Get all files regardless of folder
            $stmt = $db->prepare("SELECT * FROM media_assets WHERE (user_email = ? OR user_email IS NULL) ORDER BY createdAt DESC LIMIT ? OFFSET ?");
            $stmt->execute([$email, $limit, $offset]);
            
            $countStmt = $db->prepare("SELECT COUNT(*) FROM media_assets WHERE (user_email = ? OR user_email IS NULL)");
            $countStmt->execute([$email]);
        } elseif ($folderId !== null && $folderId !== '') {
            // Get files inside the specific folder
            $stmt = $db->prepare("SELECT * FROM media_assets WHERE (user_email = ? OR user_email IS NULL) AND folderId = ? ORDER BY createdAt DESC LIMIT ? OFFSET ?");
            $stmt->execute([$email, $folderId, $limit, $offset]);
            
            $countStmt = $db->prepare("SELECT COUNT(*) FROM media_assets WHERE (user_email = ? OR user_email IS NULL) AND folderId = ?");
            $countStmt->execute([$email, $folderId]);
        } else {
            // Default: show Root files only (where folderId is null)
            $stmt = $db->prepare("SELECT * FROM media_assets WHERE (user_email = ? OR user_email IS NULL) AND folderId IS NULL ORDER BY createdAt DESC LIMIT ? OFFSET ?");
            $stmt->execute([$email, $limit, $offset]);
            
            $countStmt = $db->prepare("SELECT COUNT(*) FROM media_assets WHERE (user_email = ? OR user_email IS NULL) AND folderId IS NULL");
            $countStmt->execute([$email]);
        }
        
        $assets = $stmt->fetchAll();
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

// Handle batch moving assets - updates folderId metadata only
function handleBatchMoveAssets($db) {
    $email = getAuthenticatedUserEmail();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $assetIds = $input['assetIds'] ?? [];
    $folderId = $input['folderId'] ?? null;
    
    if (empty($assetIds) || !is_array($assetIds)) {
        throw new Exception('Asset IDs are required', 400);
    }
    
    if (empty($folderId)) {
        $folderId = null;
    }
    
    if ($folderId !== null) {
        // Verify folder exists and belongs to the user
        $stmt = $db->prepare("SELECT * FROM folders WHERE folderId = ? AND user_email = ?");
        $stmt->execute([$folderId, $email]);
        $folder = $stmt->fetch();
        if (!$folder) {
            throw new Exception('Target folder not found', 404);
        }
    }
    
    // Batch update the asset records
    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $sql = "UPDATE media_assets SET folderId = ? WHERE (user_email = ? OR user_email IS NULL) AND assetId IN ($placeholders)";
    
    $stmt = $db->prepare($sql);
    $params = array_merge([$folderId, $email], $assetIds);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => count($assetIds) . ' assets moved successfully',
        'folderId' => $folderId
    ]);
}

// Handle moving assets inside database metadata - leaving physical paths identical!
function handleMoveAsset($db, $assetId) {
    $email = getAuthenticatedUserEmail();
    
    // Verify asset exists and belongs to the user
    $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ? AND user_email = ?");
    $stmt->execute([$assetId, $email]);
    $asset = $stmt->fetch();
    if (!$asset) {
        throw new Exception('Asset not found or access denied', 404);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $folderId = $input['folderId'] ?? null;
    
    if (empty($folderId)) {
        $folderId = null;
    }
    
    if ($folderId !== null) {
        // Verify folder exists and belongs to the user
        $stmt = $db->prepare("SELECT * FROM folders WHERE folderId = ? AND user_email = ?");
        $stmt->execute([$folderId, $email]);
        $folder = $stmt->fetch();
        if (!$folder) {
            throw new Exception('Target folder not found', 404);
        }
    }
    
    // Update folderId only, keeping files on disk and publicUrls identical!
    $stmt = $db->prepare("UPDATE media_assets SET folderId = ? WHERE assetId = ? AND user_email = ?");
    $stmt->execute([$folderId, $assetId, $email]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Asset moved successfully',
        'assetId' => $assetId,
        'folderId' => $folderId
    ]);
}

// Handle renaming asset metadata in database
function handleRenameAsset($db, $assetId) {
    $email = getAuthenticatedUserEmail();
    
    // Verify asset exists and belongs to the user
    $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ? AND user_email = ?");
    $stmt->execute([$assetId, $email]);
    $asset = $stmt->fetch();
    if (!$asset) {
        throw new Exception('Asset not found or access denied', 404);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $filename = $input['filename'] ?? null;
    
    if (empty($filename) || trim($filename) === '') {
        throw new Exception('Filename cannot be empty', 400);
    }
    
    $stmt = $db->prepare("UPDATE media_assets SET originalFilename = ? WHERE assetId = ? AND user_email = ?");
    $stmt->execute([trim($filename), $assetId, $email]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Asset renamed successfully',
        'assetId' => $assetId,
        'filename' => trim($filename)
    ]);
}


// Handle asset deletion
function handleDeleteAsset($db, $assetId) {
    $email = getAuthenticatedUserEmail();
    
    // Find the asset first
    $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();
    
    if (!$asset) {
        throw new Exception('Asset not found', 404);
    }
    
    // Enforce ownership: only the uploader (or if it's NULL, anyone authenticated) can delete it
    if ($asset['user_email'] !== null && $asset['user_email'] !== $email) {
        throw new Exception('Unauthorized: You do not own this asset', 403);
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

// Handle batch asset deletion
function handleBatchDeleteAssets($db) {
    $email = getAuthenticatedUserEmail();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $assetIds = $input['assetIds'] ?? [];
    
    if (empty($assetIds) || !is_array($assetIds)) {
        throw new Exception('Asset IDs are required for batch deletion', 400);
    }
    
    // Select all these assets to verify ownership and get storage paths
    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $sql = "SELECT * FROM media_assets WHERE assetId IN ($placeholders)";
    $stmt = $db->prepare($sql);
    $stmt->execute($assetIds);
    $assets = $stmt->fetchAll();
    
    $verifiedIds = [];
    foreach ($assets as $asset) {
        if ($asset['user_email'] !== null && $asset['user_email'] !== $email) {
            throw new Exception('Unauthorized: You do not own all selected assets', 403);
        }
        
        // Delete physical file
        $filePath = UPLOAD_DIR . '/' . $asset['storagePath'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $verifiedIds[] = $asset['assetId'];
    }
    
    if (!empty($verifiedIds)) {
        $deletePlaceholders = implode(',', array_fill(0, count($verifiedIds), '?'));
        $deleteSql = "DELETE FROM media_assets WHERE assetId IN ($deletePlaceholders)";
        $deleteStmt = $db->prepare($deleteSql);
        $deleteStmt->execute($verifiedIds);
    }
    
    echo json_encode([
        'success' => true,
        'message' => count($verifiedIds) . ' assets deleted successfully',
        'assetIds' => $verifiedIds
    ]);
}


// Handle file upload
function handleUploadAsset($db) {
    $email = getAuthenticatedUserEmail();

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
    // Safely format the public URL (prevent duplicate slashes in path while preserving the protocol slash)
    $urlParts = explode('://', $publicUrl, 2);
    if (count($urlParts) === 2) {
        $urlParts[1] = preg_replace('#/+#', '/', $urlParts[1]);
        $publicUrl = $urlParts[0] . '://' . $urlParts[1];
    } else {
        $publicUrl = preg_replace('#/+#', '/', $publicUrl);
    }
    
    // Optional folderId parameter
    $folderId = $_POST['folderId'] ?? null;
    if (empty($folderId)) {
        $folderId = null;
    }
    
    // Store in database
    $stmt = $db->prepare("INSERT INTO media_assets (assetId, originalFilename, secureFilename, mimeType, fileSize, storagePath, publicUrl, user_email, folderId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $assetId,
        $file['name'],
        $secureFilename,
        $mimeType,
        $file['size'],
        $secureFilename,
        $publicUrl,
        $email,
        $folderId
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