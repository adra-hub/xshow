<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

// Check if installed
if (!Config::isInstalled()) {
    header('Location: install.php');
    exit;
}

define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT']);

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
        $realPath = sanitizePath($path);
        if (!is_readable($realPath)) {
            return [
                'status' => 'error',
                'message' => 'Directory is not readable'
            ];
        }

        $files = scandir($realPath);
        $result = [];
        
        // Get the xshow directory path to exclude it
        $xshowPath = realpath(__DIR__);
        $xshowDirName = basename($xshowPath);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullPath = $realPath . DIRECTORY_SEPARATOR . $file;
            if (!is_readable($fullPath)) continue;
            
            // Skip the xshow directory
            if (realpath($fullPath) === $xshowPath || $file === $xshowDirName) {
                continue;
            }
            
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
            
            $result[] = $fileInfo;
        }

        $currentPath = getRelativePath($realPath);
        $response = [
            'status' => 'success',
            'data' => $result,
            'current_path' => $currentPath
        ];
        
        return $response;
        
    } catch (Exception $e) {
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
                $path = isset($_GET['path']) ? $_GET['path'] : '';
                echo json_encode(scanDirectory($path));
                break;

            case 'create_folder':
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
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'create_file':
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
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'delete':
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
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'rename':
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
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'upload':
                if (!isset($_FILES['files']) || !isset($_POST['path'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'No files uploaded or path not specified'
                    ]);
                    break;
                }
                
                $path = $_POST['path'];
                $uploadDir = sanitizePath($path);
                
                // Ensure upload directory exists and is writable
                if (!file_exists($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'Failed to create upload directory'
                        ]);
                        break;
                    }
                }
                
                if (!is_writable($uploadDir)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Upload directory is not writable'
                    ]);
                    break;
                }
                
                $files = $_FILES['files'];
                $successful = [];
                $failed = [];
                
                // Handle multiple file uploads
                for ($i = 0; $i < count($files['name']); $i++) {
                    $fileName = $files['name'][$i];
                    $tmpPath = $files['tmp_name'][$i];
                    $error = $files['error'][$i];
                    
                    if ($error === UPLOAD_ERR_OK) {
                        $destPath = $uploadDir . '/' . $fileName;
                        
                        // Check if file already exists
                        if (file_exists($destPath) && !isset($_POST['overwrite'])) {
                            $failed[] = $fileName . ' (already exists)';
                            continue;
                        }
                        
                        // Move the uploaded file
                        if (move_uploaded_file($tmpPath, $destPath)) {
                            chmod($destPath, 0644);
                            $successful[] = $fileName;
                        } else {
                            $failed[] = $fileName . ' (move failed)';
                        }
                    } else {
                        $errorMessage = match ($error) {
                            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form',
                            UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
                            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
                            default => 'Unknown upload error'
                        };
                        $failed[] = $fileName . ' (' . $errorMessage . ')';
                    }
                }
                
                if (empty($failed)) {
                    echo json_encode([
                        'status' => 'success',
                        'uploaded' => $successful
                    ]);
                } else if (!empty($successful)) {
                    echo json_encode([
                        'status' => 'partial',
                        'uploaded' => $successful,
                        'failed' => $failed
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'All uploads failed',
                        'failed' => $failed
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
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;

            case 'get_users':
                // Check if current user is admin
                if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'admin') {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Access denied. Only admin can perform this action.'
                    ]);
                    break;
                }
                
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
                // Check if current user is admin
                if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'admin') {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Access denied. Only admin can perform this action.'
                    ]);
                    break;
                }
                
                if (!isset($_POST['username']) || !isset($_POST['password'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing required parameters'
                    ]);
                    break;
                }
            
                // Enhanced password validation
                $password = $_POST['password'];
                $errors = [];
                
                // Check length
                if (strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters long';
                }
                
                // Check for uppercase letters
                if (!preg_match('/[A-Z]/', $password)) {
                    $errors[] = 'Password must contain at least one uppercase letter';
                }
                
                // Check for lowercase letters
                if (!preg_match('/[a-z]/', $password)) {
                    $errors[] = 'Password must contain at least one lowercase letter';
                }
                
                // Check for numbers
                if (!preg_match('/[0-9]/', $password)) {
                    $errors[] = 'Password must contain at least one number';
                }
                
                // Check for special characters
                if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                    $errors[] = 'Password must contain at least one special character';
                }
                
                // If there are errors, return them
                if (!empty($errors)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => implode('. ', $errors)
                    ]);
                    break;
                }
            
                try {
                    Config::addUser($_POST['username'], $password);
                    echo json_encode(['status' => 'success']);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;
        
            case 'change_password':
                // Admin can change any password, other users can only change their own
                if (!isset($_SESSION['username']) || 
                    ($_SESSION['username'] !== 'admin' && $_SESSION['username'] !== $_POST['username'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Access denied. You can only change your own password.'
                    ]);
                    break;
                }
                
                if (!isset($_POST['username']) || !isset($_POST['password'])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Missing required parameters'
                    ]);
                    break;
                }
            
                // Enhanced password validation
                $password = $_POST['password'];
                $errors = [];
                
                // Check length
                if (strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters long';
                }
                
                // Check for uppercase letters
                if (!preg_match('/[A-Z]/', $password)) {
                    $errors[] = 'Password must contain at least one uppercase letter';
                }
                
                // Check for lowercase letters
                if (!preg_match('/[a-z]/', $password)) {
                    $errors[] = 'Password must contain at least one lowercase letter';
                }
                
                // Check for numbers
                if (!preg_match('/[0-9]/', $password)) {
                    $errors[] = 'Password must contain at least one number';
                }
                
                // Check for special characters
                if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                    $errors[] = 'Password must contain at least one special character';
                }
                
                // If there are errors, return them
                if (!empty($errors)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => implode('. ', $errors)
                    ]);
                    break;
                }
            
                try {
                    Config::updatePassword($_POST['username'], $password);
                    echo json_encode(['status' => 'success']);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                break;
        
            case 'delete_user':
                // Check if current user is admin
                if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'admin') {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Access denied. Only admin can perform this action.'
                    ]);
                    break;
                }
                
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
        
        if ($file && file_exists($file) && !is_dir($file) && is_readable($file)) {
            try {
                $mime = mime_content_type($file) ?: 'application/octet-stream';
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . filesize($file));
                header('Content-Disposition: inline; filename="' . basename($file) . '"');
                readfile($file);
                exit;
            } catch (Exception $e) {
                header('HTTP/1.1 500 Internal Server Error');
                exit;
            }
        }
        header('HTTP/1.1 404 Not Found');
        exit;
    } catch (Exception $e) {
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
            $_SESSION['username'] = $_POST['username']; // Store the username in session
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
    
    // Check if the current user is the admin
    if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'admin') {
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
require __DIR__ . '/assets/views/frontend.php';
