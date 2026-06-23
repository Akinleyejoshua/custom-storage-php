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
    
    $email = '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
        if (filter_var($token, FILTER_VALIDATE_EMAIL)) {
            $email = $token;
        } else {
            $decoded = base64_decode($token, true);
            if ($decoded !== false && filter_var($decoded, FILTER_VALIDATE_EMAIL)) {
                $email = $decoded;
            } else {
                $email = $token;
            }
        }
    } else {
        $xEmail = $headers['X-User-Email'] ?? $headers['x-user-email'] ?? $_SERVER['HTTP_X_USER_EMAIL'] ?? '';
        if (!empty($xEmail)) {
            $email = trim($xEmail);
        }
    }
    
    if (empty($email)) {
        throw new Exception('Unauthorized: Active email session required', 401);
    }
    
    // Auto-record active user space in users database table
    global $db;
    if ($db && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            // First check if user exists and is soft-deleted
            $stmt = $db->prepare("SELECT deleted_at FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $userRow = $stmt->fetch();
            if ($userRow && $userRow['deleted_at'] !== null) {
                throw new Exception('Unauthorized: This account has been soft-deleted. Please contact support.', 401);
            }

            $stmt = $db->prepare("INSERT INTO users (email, last_login) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_login = NOW()");
            $stmt->execute([$email]);
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                throw $e;
            }
            // Ignore database registration issues
        }
    }
    
    return $email;
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
                handleAuthRequest($db, $requestMethod, $endpointParts);
                break;
            case 'track':
                handleTrackRequest($db, $requestMethod);
                break;
            case 'admin':
                handleAdminRequest($db, $requestMethod, $endpointParts);
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
function handleAuthRequest($db, $requestMethod, $endpointParts) {
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
        
        // Check if user is soft-deleted
        $stmt = $db->prepare("SELECT deleted_at FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $userRow = $stmt->fetch();
        if ($userRow && $userRow['deleted_at'] !== null) {
            throw new Exception('This account has been soft-deleted. Please contact support to recover it.', 403);
        }
        
        // Record or update user in database
        $stmt = $db->prepare("INSERT INTO users (email, last_login) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_login = NOW()");
        $stmt->execute([$email]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'email' => $email
        ]);
    } elseif ($action === 'soft-delete') {
        $email = getAuthenticatedUserEmail();
        $input = json_decode(file_get_contents('php://input'), true);
        $keepStorage = isset($input['keep_storage']) ? (bool)$input['keep_storage'] : true;
        
        $db->beginTransaction();
        try {
            // Update user record to soft-deleted
            $stmt = $db->prepare("UPDATE users SET deleted_at = NOW() WHERE email = ?");
            $stmt->execute([$email]);
            
            if (!$keepStorage) {
                // Retrieve all assets for user
                $stmt = $db->prepare("SELECT storagePath FROM media_assets WHERE user_email = ?");
                $stmt->execute([$email]);
                $assets = $stmt->fetchAll();
                
                // Delete physical files
                foreach ($assets as $asset) {
                    $filePath = UPLOAD_DIR . '/' . $asset['storagePath'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
                
                // Delete assets from db
                $stmt = $db->prepare("DELETE FROM media_assets WHERE user_email = ?");
                $stmt->execute([$email]);
                
                // Delete folders from db
                $stmt = $db->prepare("DELETE FROM folders WHERE user_email = ?");
                $stmt->execute([$email]);
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Account soft-deleted successfully'
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
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
            if (isset($endpointParts[2]) && $endpointParts[2] === 'stats') {
                handleGetAssetsStats($db);
            } elseif (isset($endpointParts[2]) && $endpointParts[2] === 'upload-status') {
                handleGetUploadStatus();
            } else {
                handleGetAssets($db, $endpointParts);
            }
            break;
        case 'POST':
            if (isset($endpointParts[2]) && $endpointParts[2] === 'move') {
                handleBatchMoveAssets($db);
            } elseif (isset($endpointParts[2]) && $endpointParts[2] === 'upload-chunk') {
                handleUploadChunk($db);
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

// Get size of currently uploaded chunks for a file
function handleGetUploadStatus() {
    getAuthenticatedUserEmail(); // Enforce authentication
    
    // Disable caching
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    
    $fileId = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['fileId'] ?? '');
    if (empty($fileId)) {
        throw new Exception('fileId parameter is required', 400);
    }
    
    $tmpDir = UPLOAD_DIR . '/tmp';
    $tmpFilePath = $tmpDir . '/' . $fileId;
    
    $uploadedBytes = 0;
    if (file_exists($tmpFilePath)) {
        $uploadedBytes = filesize($tmpFilePath);
    }
    
    echo json_encode([
        'success' => true,
        'uploadedBytes' => $uploadedBytes
    ]);
}

// Handle single chunk upload
function handleUploadChunk($db) {
    $email = getAuthenticatedUserEmail();
    
    $headers = getallheaders();
    $fileId = preg_replace('/[^a-zA-Z0-9]/', '', $headers['X-File-Id'] ?? $_SERVER['HTTP_X_FILE_ID'] ?? '');
    $fileName = $headers['X-File-Name'] ?? $_SERVER['HTTP_X_FILE_NAME'] ?? '';
    $fileSize = intval($headers['X-File-Size'] ?? $_SERVER['HTTP_X_FILE_SIZE'] ?? 0);
    $chunkOffset = intval($headers['X-Chunk-Offset'] ?? $_SERVER['HTTP_X_CHUNK_OFFSET'] ?? 0);
    $folderId = $headers['X-Folder-Id'] ?? $_SERVER['HTTP_X_FOLDER_ID'] ?? null;
    
    if (empty($fileId)) {
        throw new Exception('X-File-Id header is required', 400);
    }
    if (empty($fileName)) {
        throw new Exception('X-File-Name header is required', 400);
    }
    if ($fileSize <= 0) {
        throw new Exception('Invalid X-File-Size', 400);
    }
    if ($fileSize > MAX_FILE_SIZE) {
        throw new Exception('File size exceeds maximum limit of ' . formatBytes(MAX_FILE_SIZE), 400);
    }
    if (empty($folderId)) {
        $folderId = null;
    }
    
    $input = fopen("php://input", "rb");
    if (!$input) {
        throw new Exception('Failed to open input stream', 400);
    }
    
    $tmpDir = UPLOAD_DIR . '/tmp';
    if (!file_exists($tmpDir)) {
        if (!mkdir($tmpDir, 0755, true)) {
            fclose($input);
            throw new Exception('Failed to create temporary upload directory', 500);
        }
    }
    
    $tmpFilePath = $tmpDir . '/' . $fileId;
    
    // Verify offset
    $currentSize = file_exists($tmpFilePath) ? filesize($tmpFilePath) : 0;
    if ($currentSize !== $chunkOffset) {
        fclose($input);
        throw new Exception("Offset mismatch. Server has {$currentSize} bytes, chunk starts at {$chunkOffset} bytes.", 409);
    }
    
    $mode = ($chunkOffset === 0) ? 'wb' : 'ab';
    $out = fopen($tmpFilePath, $mode);
    if (!$out) {
        fclose($input);
        throw new Exception('Failed to open output file', 500);
    }
    
    while ($buffer = fread($input, 8192)) {
        fwrite($out, $buffer);
    }
    
    fclose($out);
    fclose($input);
    
    clearstatcache(true, $tmpFilePath);
    $uploadedBytes = filesize($tmpFilePath);
    
    if ($uploadedBytes >= $fileSize) {
        // Complete! Verify MIME type
        $mimeType = mime_content_type($tmpFilePath);
        if (!isAllowedMimeType($mimeType)) {
            unlink($tmpFilePath);
            throw new Exception('File type not allowed', 400);
        }
        
        $secureFilename = generateSecureFilename($fileName);
        $targetPath = UPLOAD_DIR . '/' . $secureFilename;
        
        if (!rename($tmpFilePath, $targetPath)) {
            throw new Exception('Failed to finalize uploaded file', 500);
        }
        
        $publicUrl = BASE_URL . '/uploads/' . $secureFilename;
        $urlParts = explode('://', $publicUrl, 2);
        if (count($urlParts) === 2) {
            $urlParts[1] = preg_replace('#/+#', '/', $urlParts[1]);
            $publicUrl = $urlParts[0] . '://' . $urlParts[1];
        } else {
            $publicUrl = preg_replace('#/+#', '/', $publicUrl);
        }
        
        $assetId = bin2hex(random_bytes(16));
        
        $stmt = $db->prepare("INSERT INTO media_assets (assetId, originalFilename, secureFilename, mimeType, fileSize, storagePath, publicUrl, user_email, folderId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $assetId,
            $fileName,
            $secureFilename,
            $mimeType,
            $fileSize,
            $secureFilename,
            $publicUrl,
            $email,
            $folderId
        ]);
        
        $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ?");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'completed' => true,
            'data' => $asset
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'completed' => false,
            'uploadedBytes' => $uploadedBytes
        ]);
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

// Get storage stats by category and total usage
function handleGetAssetsStats($db) {
    $email = getAuthenticatedUserEmail();
    
    $stmt = $db->prepare("SELECT fileSize, mimeType FROM media_assets WHERE (user_email = ? OR user_email IS NULL)");
    $stmt->execute([$email]);
    $assets = $stmt->fetchAll();
    
    $stats = [
        'totalSize' => 0,
        'totalCount' => 0,
        'image' => ['size' => 0, 'count' => 0],
        'video' => ['size' => 0, 'count' => 0],
        'document' => ['size' => 0, 'count' => 0],
        'archive' => ['size' => 0, 'count' => 0],
        'audio' => ['size' => 0, 'count' => 0],
        'other' => ['size' => 0, 'count' => 0],
    ];
    
    foreach ($assets as $asset) {
        $size = intval($asset['fileSize']);
        $mime = $asset['mimeType'];
        
        $stats['totalSize'] += $size;
        $stats['totalCount'] += 1;
        
        $category = 'other';
        if (strpos($mime, 'image/') === 0) {
            $category = 'image';
        } elseif (strpos($mime, 'video/') === 0) {
            $category = 'video';
        } elseif (strpos($mime, 'audio/') === 0) {
            $category = 'audio';
        } else {
            $archiveTypes = [
                'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed',
                'application/x-7z-compressed', 'application/x-tar', 'application/x-gzip'
            ];
            $documentTypes = [
                'application/pdf', 'text/csv', 'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain', 'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/json', 'application/xml', 'text/html'
            ];
            
            if (in_array($mime, $archiveTypes)) {
                $category = 'archive';
            } elseif (in_array($mime, $documentTypes)) {
                $category = 'document';
            }
        }
        
        $stats[$category]['size'] += $size;
        $stats[$category]['count'] += 1;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $stats
    ]);
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

// Handle /api/track requests
function handleTrackRequest($db, $requestMethod) {
    if ($requestMethod !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt = $db->prepare("INSERT INTO visitors (ip_address, user_agent) VALUES (?, ?)");
    $stmt->execute([$ip, $ua]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Visit tracked'
    ]);
}

// Handle /api/admin requests
function handleAdminRequest($db, $requestMethod, $endpointParts) {
    $action = $endpointParts[2] ?? '';
    
    switch ($action) {
        case 'stats':
            if ($requestMethod !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            // Total visitors
            $visitorsCount = $db->query("SELECT COUNT(*) FROM visitors")->fetchColumn();
            
            // Total storage
            $totalStorage = $db->query("SELECT COALESCE(SUM(fileSize), 0) FROM media_assets")->fetchColumn();
            
            // Total users
            $usersCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            // Recent visitors
            $recentVisitors = $db->query("SELECT * FROM visitors ORDER BY visited_at DESC LIMIT 50")->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'total_visitors' => intval($visitorsCount),
                    'total_storage' => intval($totalStorage),
                    'total_users' => intval($usersCount),
                    'recent_visitors' => $recentVisitors
                ]
            ]);
            break;
            
        case 'users':
            if ($requestMethod !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $users = $db->query("
                SELECT u.email, u.last_login, u.createdAt, u.deleted_at,
                       (SELECT COUNT(*) FROM media_assets WHERE user_email = u.email) as files_count,
                       (SELECT COALESCE(SUM(fileSize), 0) FROM media_assets WHERE user_email = u.email) as storage_used
                FROM users u
                ORDER BY last_login DESC
            ")->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => $users
            ]);
            break;
            
        case 'user-details':
            if ($requestMethod !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $email = $_GET['email'] ?? '';
            if (empty($email)) {
                throw new Exception('Email parameter is required', 400);
            }
            
            // Check user status
            $stmt = $db->prepare("SELECT deleted_at FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $userRow = $stmt->fetch();
            $deletedAt = $userRow ? $userRow['deleted_at'] : null;

            // Folders
            $stmt = $db->prepare("SELECT * FROM folders WHERE user_email = ? ORDER BY name ASC");
            $stmt->execute([$email]);
            $folders = $stmt->fetchAll();
            
            // Assets
            $stmt = $db->prepare("SELECT * FROM media_assets WHERE user_email = ? ORDER BY createdAt DESC");
            $stmt->execute([$email]);
            $assets = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'deleted_at' => $deletedAt,
                    'folders' => $folders,
                    'assets' => $assets
                ]
            ]);
            break;
            
        case 'assets':
            if ($requestMethod !== 'DELETE') {
                throw new Exception('Method not allowed', 405);
            }
            
            // Verify admin passcode for deletion write privilege
            $headers = getallheaders();
            $passcode = $headers['X-Admin-Passcode'] ?? $headers['x-admin-passcode'] ?? $_SERVER['HTTP_X_ADMIN_PASSCODE'] ?? '';
            if ($passcode !== ADMIN_PASSCODE) {
                throw new Exception('Unauthorized: Valid Admin Passcode required to execute delete actions', 403);
            }
            
            $assetId = $endpointParts[3] ?? '';
            if (empty($assetId)) {
                throw new Exception('Asset ID is required', 400);
            }
            
            // Find asset
            $stmt = $db->prepare("SELECT * FROM media_assets WHERE assetId = ?");
            $stmt->execute([$assetId]);
            $asset = $stmt->fetch();
            
            if (!$asset) {
                throw new Exception('Asset not found', 404);
            }
            
            // Delete physical file
            $filePath = UPLOAD_DIR . '/' . $asset['storagePath'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            
            // Delete record
            $stmt = $db->prepare("DELETE FROM media_assets WHERE assetId = ?");
            $stmt->execute([$assetId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Asset deleted successfully by admin'
            ]);
            break;
            
        case 'recover-user':
            if ($requestMethod !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            // Verify admin passcode for recover action
            $headers = getallheaders();
            $passcode = $headers['X-Admin-Passcode'] ?? $headers['x-admin-passcode'] ?? $_SERVER['HTTP_X_ADMIN_PASSCODE'] ?? '';
            if ($passcode !== ADMIN_PASSCODE) {
                throw new Exception('Unauthorized: Valid Admin Passcode required to execute recover action', 403);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $email = $input['email'] ?? '';
            
            if (empty($email)) {
                throw new Exception('Email parameter is required', 400);
            }
            
            $stmt = $db->prepare("UPDATE users SET deleted_at = NULL WHERE email = ?");
            $stmt->execute([$email]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User space recovered successfully'
            ]);
            break;
            
        default:
            throw new Exception('Admin action not found: ' . $action, 404);
    }
}