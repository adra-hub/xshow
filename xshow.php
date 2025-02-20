<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

// Check if installed
if (!Config::isInstalled()) {
    header('Location: install.php');
    exit;
}

define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT']);

// Debug function
function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/debug.log';
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message . ' - ' . print_r($data, true) . "\n";
    error_log($logMessage, 3, $logFile);
}

// Load plugin system
require_once __DIR__ . '/plugins.php';
$pluginLoader = new PluginLoader(__DIR__ . '/plugins');
$loadedPlugins = $pluginLoader->loadPlugins();

// Initialize plugin handlers array
$pluginHandlers = [];

function getCriticalPaths() {
    return [
        realpath(__DIR__),                  // Protect xshow application folder
        realpath(ROOT_PATH . '/php'),       // Protect PHP files
        realpath(ROOT_PATH . '/cgi-bin'),   // Protect CGI scripts
        realpath(ROOT_PATH . '/.htaccess')  // Protect htaccess
    ];
}

function sanitizePath($path) {
    // Remove any potential directory traversal attempts and normalize slashes
    $path = str_replace(['../', '..\\', '\\'], ['', '', '/'], trim($path, '/'));
    $path = urldecode($path);
    
    // If path is empty, return ROOT_PATH
    if (empty($path)) {
        return ROOT_PATH;
    }
    
    // Remove any potential duplicate paths
    $path = preg_replace('#/+#', '/', $path);
    
    // Remove any 'srv/disk*/www/domain.com' pattern from the path
    $path = preg_replace('#^(srv/disk\d+/\d+/www/[^/]+/)(.*)$#', '$2', $path);
    
    // Combine with ROOT_PATH
    $fullPath = ROOT_PATH . '/' . $path;
    $fullPath = preg_replace('#/+#', '/', $fullPath);
    
    if (file_exists($fullPath)) {
        $realPath = realpath($fullPath);
        return $realPath ?: $fullPath;
    }
    
    return $fullPath;
}

function getRelativePath($fullPath) {
    // Normalize directory separators
    $fullPath = str_replace('\\', '/', $fullPath);
    $rootPath = str_replace('\\', '/', ROOT_PATH);
    
    // Get relative path by removing ROOT_PATH
    $relativePath = ltrim(str_replace($rootPath, '', $fullPath), '/');
    
    // Remove any potential duplicate paths
    $relativePath = preg_replace('#^(srv/disk\d+/\d+/www/[^/]+/)(.*)$#', '$2', $relativePath);
    $relativePath = preg_replace('#/+#', '/', $relativePath);
    
    debugLog('Relative path calculation', [
        'full_path' => $fullPath,
        'root_path' => $rootPath,
        'relative_path' => $relativePath
    ]);
    
    return $relativePath;
}

function createFullPath($basePath, $name) {
    $basePath = rtrim($basePath, '/');
    $name = trim($name, '/');
    
    // Clean the base path from hosting structure if present
    $basePath = preg_replace('#^(srv/disk\d+/\d+/www/[^/]+/)(.*)$#', '$2', $basePath);
    
    // Create clean path
    $fullPath = $basePath . '/' . $name;
    $fullPath = preg_replace('#/+#', '/', $fullPath);
    
    return ROOT_PATH . '/' . $fullPath;
}

function isPathSafe($path) {
    // For new paths that don't exist yet
    if (!file_exists($path)) {
        $parentDir = dirname($path);
        if (!file_exists($parentDir)) {
            $path = $parentDir;
        }
    }

    $realPath = realpath($path);
    if (!$realPath) {
        return true; // For new paths
    }

    // Get real path of ROOT_PATH
    $rootRealPath = realpath(ROOT_PATH);
    if (!$rootRealPath) {
        return false;
    }

    // Check if path is within document root
    if (strpos(str_replace('\\', '/', $realPath), str_replace('\\', '/', $rootRealPath)) !== 0) {
        return false;
    }

    // Check critical paths
    foreach (getCriticalPaths() as $criticalPath) {
        if ($criticalPath && file_exists($criticalPath)) {
            if (strpos(str_replace('\\', '/', $realPath), str_replace('\\', '/', $criticalPath)) === 0) {
                return false;
            }
        }
    }

    return true;
}

function scanDirectory($path) {
    try {
        debugLog('Scanning directory', $path);
        
        $realPath = sanitizePath($path);
        if (!is_readable($realPath)) {
            debugLog('Directory not readable', $realPath);
            return [
                'status' => 'error',
                'message' => 'Directory is not readable'
            ];
        }

        $files = scandir($realPath);
        $result = [];
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullPath = $realPath . DIRECTORY_SEPARATOR . $file;
            if (!is_readable($fullPath)) continue;
            
            $isDir = is_dir($fullPath);
            $relativePath = getRelativePath($fullPath);
            
            try {
                $mime = $isDir ? 'directory' : (mime_content_type($fullPath) ?: 'application/octet-stream');
            } catch (Exception $e) {
                $mime = 'application/octet-stream';
            }
            
            $isProtected = !isPathSafe($fullPath);
            
            $fileInfo = [
                'name' => $file,
                'path' => $relativePath,
                'type' => $isDir ? 'folder' : 'file',
                'size' => $isDir ? 0 : filesize($fullPath),
                'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
                'mime' => $mime,
                'protected' => $isProtected
            ];
            
            debugLog('Adding file', $fileInfo);
            $result[] = $fileInfo;
        }

        $currentPath = getRelativePath($realPath);
        $response = [
            'status' => 'success',
            'data' => $result,
            'current_path' => $currentPath
        ];
        
        debugLog('Scan complete', ['path' => $currentPath, 'file_count' => count($result)]);
        return $response;
        
    } catch (Exception $e) {
        debugLog('Scan error', $e->getMessage());
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}
// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // Check authentication for all actions except logout
    if (!isset($_SESSION['authenticated']) && $_GET['action'] !== 'logout') {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        exit;
    }

    try {
        switch ($_GET['action']) {
            case 'scan':
                debugLog('Scan request', $_GET);
                $path = isset($_GET['path']) ? $_GET['path'] : '';
                echo json_encode(scanDirectory($path));
                break;

            case 'create_folder':
                debugLog('Create folder request', $_POST);
                if (!isset($_POST['path']) || !isset($_POST['name'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing required parameters'
                    ]);
                    break;
                }
                
                $path = $_POST['path'];
                $name = basename($_POST['name']);
                $createIndex = isset($_POST['createIndex']) && $_POST['createIndex'] === '1';
                $redirectUrl = isset($_POST['redirectUrl']) ? trim($_POST['redirectUrl']) : '';
                
                // Clean path and create full path
                $fullPath = createFullPath($path, $name);
                
                try {
                    debugLog('Creating folder', [
                        'path' => $path,
                        'name' => $name,
                        'full_path' => $fullPath,
                        'create_index' => $createIndex,
                        'redirect_url' => $redirectUrl
                    ]);
                    
                    // Create parent directories if they don't exist
                    $parentDir = dirname($fullPath);
                    if (!file_exists($parentDir)) {
                        if (!mkdir($parentDir, 0755, true)) {
                            throw new Exception('Failed to create parent directories');
                        }
                    }
                    
                    // Check if destination is writable
                    if (!is_writable($parentDir)) {
                        throw new Exception('Destination directory is not writable');
                    }
                    
                    // Check if folder already exists
                    if (file_exists($fullPath) && !isset($_POST['overwrite'])) {
                        throw new Exception('A folder with this name already exists');
                    }
                    
                    // Create the folder
                    if (!file_exists($fullPath)) {
                        if (!mkdir($fullPath, 0755)) {
                            throw new Exception('Failed to create folder');
                        }
                    }
                    
                    // Create index.php if requested
                    if ($createIndex) {
                        $indexPath = $fullPath . '/index.php';
                        $indexContent = '<?php' . PHP_EOL;
                        
                        if (!empty($redirectUrl)) {
                            $indexContent .= 'header("Location: ' . str_replace('"', '\"', $redirectUrl) . '");' . PHP_EOL;
                            $indexContent .= 'exit;';
                        } else {
                            $indexContent .= '// Default index file';
                        }
                        
                        if (file_put_contents($indexPath, $indexContent) === false) {
                            throw new Exception('Failed to create index.php');
                        }
                        chmod($indexPath, 0644);
                    }
                    
                    echo json_encode(['status' => 'success']);
                    
                } catch (Exception $e) {
                    debugLog('Create folder error', $e->getMessage());
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'create_file':
                debugLog('Create file request', $_POST);
                if (!isset($_POST['path']) || !isset($_POST['name'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing required parameters'
                    ]);
                    break;
                }
                
                $path = $_POST['path'];
                $name = basename($_POST['name']);
                $content = $_POST['content'] ?? '';
                
                try {
                    $fullPath = createFullPath($path, $name);
                    debugLog('Creating file', [
                        'path' => $path,
                        'name' => $name,
                        'full_path' => $fullPath
                    ]);
                    
                    $parentDir = dirname($fullPath);
                    if (!file_exists($parentDir)) {
                        if (!mkdir($parentDir, 0755, true)) {
                            throw new Exception('Failed to create parent directories');
                        }
                    }
                    
                    if (!is_writable($parentDir)) {
                        throw new Exception('Destination directory is not writable');
                    }
                    
                    if (file_exists($fullPath) && !isset($_POST['overwrite'])) {
                        throw new Exception('A file with this name already exists');
                    }
                    
                    if (file_put_contents($fullPath, $content) !== false) {
                        chmod($fullPath, 0644);
                        echo json_encode(['status' => 'success']);
                    } else {
                        throw new Exception('Failed to create file');
                    }
                } catch (Exception $e) {
                    debugLog('Create file error', $e->getMessage());
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;
                
                case 'upload':
                    debugLog('Upload request', $_POST);
                    if (empty($_FILES['files'])) {
                        echo json_encode(['status' => 'error', 'message' => 'No files uploaded']);
                        break;
                    }
                    $uploadDir = ROOTPATH . '/uploads/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $uploadedFiles = [];
                    $failedFiles = [];
                    foreach ($_FILES['files']['name'] as $index => $fileName) {
                        $fileTmpName = $_FILES['files']['tmp_name'][$index];
                        $fileSize = $_FILES['files']['size'][$index];
                        $fileError = $_FILES['files']['error'][$index];
                        $fileType = $_FILES['files']['type'][$index];
                        $filePath = $uploadDir . basename($fileName);
                        if ($fileError === UPLOAD_ERR_OK) {
                            if (move_uploaded_file($fileTmpName, $filePath)) {
                                $uploadedFiles[] = $fileName;
                            } else {
                                $failedFiles[] = $fileName;
                            }
                        } else {
                            $failedFiles[] = $fileName;
                        }
                    }
                    if (empty($failedFiles)) {
                        echo json_encode(['status' => 'success', 'message' => 'All files uploaded successfully']);
                    } else {
                        echo json_encode(['status' => 'partial', 'message' => 'Some files failed to upload', 'failed' => $failedFiles]);
                    }
                    break;
                

            case 'delete':
                debugLog('Delete request', $_POST);
                if (empty($_POST['path'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'No path specified'
                    ]);
                    break;
                }

                $path = sanitizePath($_POST['path']);
                
                try {
                    if ($path === ROOT_PATH) {
                        throw new Exception('Cannot delete root directory');
                    }
                    
                    if (!is_writable($path)) {
                        throw new Exception('File or directory is not writable');
                    }
                    
                    if (is_dir($path)) {
                        $iterator = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
                        $files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($files as $file) {
                            if ($file->isDir()) {
                                rmdir($file->getRealPath());
                            } else {
                                unlink($file->getRealPath());
                            }
                        }
                        rmdir($path);
                    } else {
                        unlink($path);
                    }
                    
                    echo json_encode(['status' => 'success']);
                } catch (Exception $e) {
                    debugLog('Delete error', $e->getMessage());
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'rename':
                debugLog('Rename request', $_POST);
                if (!isset($_POST['oldPath']) || !isset($_POST['newName'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing required parameters'
                    ]);
                    break;
                }

                try {
                    $oldPath = sanitizePath($_POST['oldPath']);
                    $newName = basename($_POST['newName']);
                    $fullPath = createFullPath(dirname($_POST['oldPath']), $newName);
                    
                    if (!file_exists($oldPath)) {
                        throw new Exception('Source file/folder does not exist');
                    }
                    
                    if (!is_writable($oldPath) || !is_writable(dirname($oldPath))) {
                        throw new Exception('Source is not writable');
                    }

                    if (file_exists($fullPath)) {
                        throw new Exception('A file or folder with that name already exists');
                    }
                    
                    if (rename($oldPath, $fullPath)) {
                        echo json_encode(['status' => 'success']);
                    } else {
                        throw new Exception('Failed to rename');
                    }
                } catch (Exception $e) {
                    debugLog('Rename error', $e->getMessage());
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

                case 'save_markdown':
                    if (!isset($_POST['path']) || !isset($_POST['content'])) {
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'Missing required parameters'
                        ]);
                        break;
                    }
                    
                    try {
                        $path = sanitizePath($_POST['path']);
                        $content = $_POST['content'];
                        $newName = isset($_POST['newName']) ? $_POST['newName'] : basename($path);
                        
                        // If the file name is being changed
                        if (basename($path) !== $newName) {
                            $newPath = dirname($path) . '/' . $newName;
                            
                            // Check if the new file would already exist
                            if (file_exists($newPath)) {
                                throw new Exception('A file with this name already exists');
                            }
                            
                            // Rename the file
                            if (!rename($path, $newPath)) {
                                throw new Exception('Failed to rename file');
                            }
                            
                            $path = $newPath;
                        }
                        
                        // Save the content
                        if (file_put_contents($path, $content) === false) {
                            throw new Exception('Failed to save file content');
                        }
                        
                        echo json_encode([
                            'status' => 'success',
                            'newPath' => getRelativePath($path)
                        ]);
                        
                    } catch (Exception $e) {
                        debugLog('Save markdown error', $e->getMessage());
                        echo json_encode([
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ]);
                    }
                    break;
                case 'get_users':
                try {
                    $users = Config::getUsers();
                    $userList = [];
                    foreach ($users as $username => $hash) {
                        $userList[] = ['username' => $username];
                    }
                    echo json_encode([
                        'status' => 'success',
                        'users' => $userList
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'add_user':
                if (!isset($_POST['username']) || !isset($_POST['password'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing required parameters'
                    ]);
                    break;
                }

                try {
                    Config::addUser($_POST['username'], $_POST['password']);
                    echo json_encode(['status' => 'success']);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'change_password':
                if (!isset($_POST['username']) || !isset($_POST['password'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing required parameters'
                    ]);
                    break;
                }

                try {
                    Config::updatePassword($_POST['username'], $_POST['password']);
                    echo json_encode(['status' => 'success']);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'delete_user':
                if (!isset($_POST['username'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing username'
                    ]);
                    break;
                }

                try {
                    Config::deleteUser($_POST['username']);
                    echo json_encode(['status' => 'success']);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'logout':
                session_destroy();
                echo json_encode(['status' => 'success']);
                break;

            default:
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid action'
                ]);
                break;
        }
    } catch (Exception $e) {
        debugLog('Action error', $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// View file handler
if (isset($_GET['view']) && isset($_SESSION['authenticated'])) {
    try {
        $file = sanitizePath($_GET['view']);
        debugLog('View file request', $file);
        
        if ($file && file_exists($file) && !is_dir($file) && is_readable($file)) {
            try {
                $mime = mime_content_type($file) ?: 'application/octet-stream';
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . filesize($file));
                header('Content-Disposition: inline; filename="' . basename($file) . '"');
                readfile($file);
                exit;
            } catch (Exception $e) {
                debugLog('View file error', $e->getMessage());
                header('HTTP/1.1 500 Internal Server Error');
                exit;
            }
        }
        header('HTTP/1.1 404 Not Found');
        exit;
    } catch (Exception $e) {
        debugLog('View file error', $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        exit;
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['status' => 'error', 'message' => 'Invalid credentials'];
    
    if (isset($_POST['username']) && isset($_POST['password'])) {
        if (Config::verifyCredentials($_POST['username'], $_POST['password'])) {
            $_SESSION['authenticated'] = true;
            $response = ['status' => 'success'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Admin Handler
if (isset($_GET['admin'])) {
    if (!isset($_SESSION['authenticated'])) {
        header('Location: /xshow/xshow.php');
        exit;
    }
    require __DIR__ . '/assets/views/admin.html';
    exit;
}

// Check installation and authentication
if (!Config::isInstalled()) {
    header('Location: install.php');
    exit;
}

// Load frontend based on authentication
if (!isset($_SESSION['authenticated'])) {
    require __DIR__ . '/assets/views/login.html';
    exit;
}

// User is authenticated, load appropriate view
if (isset($_GET['editor'])) {
    require __DIR__ . '/assets/views/editor.html';
    exit;
}

// Default to frontend view
require __DIR__ . '/assets/views/frontend.html';