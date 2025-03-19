<?php
// Check if user is admin
$isAdmin = isset($_SESSION['username']) && $_SESSION['username'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>xShow</title>
    <link rel="stylesheet" href="/xshow/assets/css/style.css">
</head>
<body>
    <div class="explorer-container">
        <div class="main-content">
            <div class="header">
                <div class="search-bar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16">
                        <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/>
                    </svg>
                    <input type="text" id="searchInput" placeholder="Filter files...">
                </div>

                <!-- Action buttons in the header -->
                <div class="action-buttons">
                    <button type="button" class="btn" data-action="new-folder">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 7C3 5.89543 3.89543 5 5 5H9L11 7H19C20.1046 7 21 7.89543 21 9V17C21 18.1046 20.1046 19 19 19H5C3.89543 19 3 18.1046 3 17V7Z" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        New Folder
                    </button>
                    <button type="button" class="btn" data-action="new-file">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 7V17M7 12H17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            <path d="M4 4V20C4 21.1046 4.89543 22 6 22H18C19.1046 22 20 21.1046 20 20V8.34921C20 7.8077 19.7831 7.29289 19.4 6.91L15.09 2.6C14.7071 2.21679 14.1923 2 13.65 2H6C4.89543 2 4 2.89543 4 4Z" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        New File
                    </button>
                   
                    <button type="button" class="btn" data-action="upload">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 4V16M12 4L7 9M12 4L17 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            <path d="M3 19H21" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Upload Files
                    </button> 
                    
                    <?php if ($isAdmin): ?>
                    <button class="btn" data-action="admin" onclick="window.location.href='/xshow/xshow.php?admin'">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 4L19 8L12 12L5 8L12 4Z" stroke="currentColor" stroke-width="1.5" />
                            <path d="M19 8V16L12 20L5 16V8" stroke="currentColor" stroke-width="1.5" />
                        </svg>
                        Admin
                    </button>
                    <?php endif; ?>
                    
                    <button type="button" class="btn logout-btn" data-action="logout">Logout</button>
                </div>
            </div>

            <div id="breadcrumb" class="breadcrumb">
                <span onclick="fileExplorer.loadDirectory('')">Home</span>
            </div>

            <div id="folderTree" class="files-grid">
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="createTitle" class="modal-title">Create New</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="createName">Name:</label>
                    <input type="text" id="createName" placeholder="Enter name">
                </div>

                <div id="folderOptions" class="form-group" style="display: none;">
                    <div class="checkbox-group">
                        <input type="checkbox" id="createIndex" checked>
                        <label for="createIndex">Create index.php</label>
                    </div>
                    <div id="redirectUrlGroup" class="form-group">
                        <label for="redirectUrl">Redirect URL (optional):</label>
                        <input type="text" id="redirectUrl" placeholder="Enter redirect URL">
                        <small class="help-text">Leave empty for a default index file</small>
                    </div>
                </div>

                <div id="contentWrapper" class="form-group" style="display: none;">
                    <label for="createContent">Content:</label>
                    <textarea id="createContent" rows="10" placeholder="Enter file content"></textarea>
                </div>
            </div>
            <div class="preview-actions">
                <button class="btn-secondary" data-action="cancel">Cancel</button>
                <button class="btn-primary" data-action="create">Create</button>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="previewTitle" class="modal-title">File Preview</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div id="previewContainer" class="preview-container">
            </div>
            <div class="preview-actions">
                <button type="button" class="btn btn-secondary" data-action="close">Close</button>
                <button type="button" class="btn btn-primary" data-action="newtab">Open in New Tab</button>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content-upload">
            <div class="modal-header">
                <h3 class="modal-title">Upload Files</h3>
                <button class="modal-close" data-action="close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="upload-options">
                    <button type="button" data-action="select-files">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M12 4V16M12 4L7 9M12 4L17 9" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Select Files
                    </button>
                    <button type="button" data-action="select-folder">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M3 7C3 5.89543 3.89543 5 5 5H9L11 7H19C20.1046 7 21 7.89543 21 9V17C21 18.1046 20.1046 19 19 19H5C3.89543 19 3 18.1046 3 17V7Z" stroke-width="2"/>
                        </svg>
                        Select Folder
                    </button>
                    <p>or drag and drop files here</p>
                </div>

                <div class="upload-progress" style="display: none;">
                    <div class="progress-header">
                        <span class="progress-title">Uploading...</span>
                        <span class="progress-percentage">0%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-details">
                        <span class="files-count">0 / 0 files</span>
                        <span class="upload-speed">0 KB/s</span>
                    </div>
                </div>

                <div class="file-exists-dialog" style="display: none;">
                    <div class="dialog-content">
                        <div class="dialog-message"></div>
                        <div class="dialog-actions">
                            <button type="button" data-action="cancel-overwrite">Cancel</button>
                            <button type="button" data-action="confirm-overwrite">Overwrite</button>
                        </div>
                    </div>
                </div>

                <div class="upload-result" style="display: none;">
                    <div class="result-content">
                        <svg class="success-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M20 6L9 17L4 12" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="result-message"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="/xshow/assets/js/main.js"></script>

</body>
</html>