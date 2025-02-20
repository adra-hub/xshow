class FileExplorer {
    constructor() {
        this.currentPath = '';
        this.currentFile = null;
        this.createModal = document.getElementById('createModal');
        this.createType = null;
        this.isProcessing = false;

        // Bind methods to preserve 'this' context
        this.showCreateModal = this.showCreateModal.bind(this);
        this.closeCreateModal = this.closeCreateModal.bind(this);
        this.createItem = this.createItem.bind(this);
        this.deleteItem = this.deleteItem.bind(this);
        this.renameItem = this.renameItem.bind(this);
        this.uploadFiles = this.uploadFiles.bind(this);
        this.loadDirectory = this.loadDirectory.bind(this);

        this.initializeElements();
        this.initializeEventListeners();
        this.loadDirectory();
    }

    initializeElements() {
        this.container = document.getElementById('folderTree');
        this.modal = document.getElementById('previewModal');
        this.searchInput = document.querySelector('.search-bar input');
        this.breadcrumb = document.getElementById('breadcrumb');
    }

    initializeEventListeners() {
        // Search functionality
        if (this.searchInput) {
            let debounceTimer;
            this.searchInput.addEventListener('input', (e) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => this.searchFiles(e.target.value), 300);
            });
        }

        // Action buttons for new folder/file
        const actionButtons = document.querySelector('.action-buttons');
        if (actionButtons) {
            actionButtons.addEventListener('click', (e) => {
                const button = e.target.closest('button');
                if (!button) return;

                const action = button.dataset.action;
                switch (action) {
                    case 'new-folder':
                        this.showCreateModal('folder');
                        break;
                    case 'new-file':
                        this.showCreateModal('file');
                        break;
                    case 'upload':
                        document.querySelector('input[type="file"]')?.click();
                        break;
                    case 'logout':
                        logout();
                        break;
                }
            });
        }

        // Modal close handlers
        document.querySelectorAll('.modal-close').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                if (button.closest('#previewModal')) {
                    this.closePreview();
                } else if (button.closest('#createModal')) {
                    this.closeCreateModal();
                }
            });
        });

        // Modal action buttons
        document.querySelectorAll('.preview-actions button').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const action = button.dataset.action;
                switch (action) {
                    case 'create':
                        this.createItem();
                        break;
                    case 'cancel':
                        this.closeCreateModal();
                        break;
                    case 'newtab':
                        this.openInNewTab();
                        break;
                    case 'close':
                        this.closePreview();
                        break;
                }
            });
        });

        // Create Index checkbox handler
        const createIndexCheckbox = document.getElementById('createIndex');
        const redirectUrlGroup = document.getElementById('redirectUrlGroup');
        if (createIndexCheckbox) {
            createIndexCheckbox.addEventListener('change', (e) => {
                redirectUrlGroup.style.display = e.target.checked ? 'block' : 'none';
            });
        }

        // Handle file upload
        const uploadInput = document.createElement('input');
        uploadInput.type = 'file';
        uploadInput.multiple = true;
        uploadInput.style.display = 'none';
        document.body.appendChild(uploadInput);
        uploadInput.addEventListener('change', async (e) => {
            const files = e.target.files;
            if (files && files.length > 0) {
                await this.uploadFiles(files);
            }
            uploadInput.value = ''; // Reset input to allow re-uploading the same file
        });

        // Drag and drop support
        this.container.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.container.classList.add('drag-over');
        });
        this.container.addEventListener('dragleave', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.container.classList.remove('drag-over');
        });
        this.container.addEventListener('drop', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.container.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files && files.length > 0) {
                await this.uploadFiles(files);
            }
        });

        // ESC key handler
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (this.modal?.classList.contains('active')) {
                    this.closePreview();
                }
                if (this.createModal?.classList.contains('active')) {
                    this.closeCreateModal();
                }
            }
        });

        // Click outside modal to close
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    if (modal.id === 'previewModal') {
                        this.closePreview();
                    } else if (modal.id === 'createModal') {
                        this.closeCreateModal();
                    }
                }
            });
        });
    }

    async loadDirectory(path = '') {
        try {
            this.container.innerHTML = this.createLoadingAnimation();
            const encodedPath = path ? encodeURIComponent(path) : '';
            const result = await scanDirectory(encodedPath);
            if (result.status === 'error') {
                this.container.innerHTML = this.createErrorMessage(result.message);
                return;
            }
            this.currentPath = result.currentpath;
            this.renderFiles(result.data);
            this.updateBreadcrumb(this.currentPath);
        } catch (error) {
            console.error('Error loading directory:', error);
            this.container.innerHTML = this.createErrorMessage(error.message);
        }
    }

    renderFiles(files) {
        if (!files || !files.length) {
            this.container.innerHTML = this.createEmptyState();
            return;
        }

        files.sort((a, b) => {
            if (a.type === b.type) return a.name.localeCompare(b.name);
            return a.type === 'folder' ? -1 : 1;
        });

        this.container.innerHTML = '';
        files.forEach((file, index) => {
            const element = this.createFileElement(file);
            element.style.animationDelay = `${index * 50}ms`;
            this.container.appendChild(element);
        });
    }

    showCreateModal(type) {
        if (this.isProcessing) return;

        this.createType = type;
        this.createModal.classList.add('active');
        const titleElem = document.getElementById('createTitle');
        const nameInput = document.getElementById('createName');
        const folderOptions = document.getElementById('folderOptions');
        const contentWrapper = document.getElementById('contentWrapper');

        titleElem.textContent = `Create New ${type.charAt(0).toUpperCase() + type.slice(1)}`;
        nameInput.value = '';

        // Show appropriate options based on type
        folderOptions.style.display = type === 'folder' ? 'block' : 'none';
        contentWrapper.style.display = type === 'file' ? 'block' : 'none';

        // Reset checkbox and redirect URL for folders
        if (type === 'folder') {
            const createIndexCheck = document.getElementById('createIndex');
            const redirectUrlInput = document.getElementById('redirectUrl');
            if (createIndexCheck) {
                createIndexCheck.checked = true;
            }
            if (redirectUrlInput) {
                redirectUrlInput.value = '';
            }
            const redirectUrlGroup = document.getElementById('redirectUrlGroup');
            if (redirectUrlGroup) {
                redirectUrlGroup.style.display = 'block';
            }
        }

        nameInput?.focus();
    }

    async createItem() {
        if (this.isProcessing) return;

        const nameInput = document.getElementById('createName');
        if (!nameInput) return;

        const name = nameInput.value.trim();
        if (!name) {
            alert('Please enter a name');
            return;
        }

        try {
            this.isProcessing = true;
            const formData = new FormData();
            formData.append('path', this.currentPath);
            formData.append('name', name);

            if (this.createType === 'folder') {
                const createIndexCheck = document.getElementById('createIndex');
                const redirectUrl = document.getElementById('redirectUrl')?.value;
                formData.append('createIndex', createIndexCheck?.checked ? '1' : '0');
                formData.append('redirectUrl', redirectUrl);
            } else {
                const contentInput = document.getElementById('createContent');
                formData.append('content', contentInput?.value);
            }

            const response = await fetch(`xshowxshow.php?action=create-${this.createType}`, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.status === 'success') {
                this.closeCreateModal();
                await this.loadDirectory(this.currentPath);
            } else {
                throw new Error(`${result.message} Failed to create item`);
            }
        } catch (error) {
            console.error('Create error:', error);
            alert(`Failed to create ${this.createType}: ${error.message}`);
        } finally {
            this.isProcessing = false;
        }
    }

    closeCreateModal() {
        this.createModal?.classList.remove('active');
        this.createType = null;

        // Reset all inputs
        const nameInput = document.getElementById('createName');
        const contentInput = document.getElementById('createContent');
        const redirectInput = document.getElementById('redirectUrl');
        if (nameInput) nameInput.value = '';
        if (contentInput) contentInput.value = '';
        if (redirectInput) redirectInput.value = '';
    }

    createFileElement(file) {
        const element = document.createElement('div');
        element.className = 'file-card';
        element.dataset.path = file.path;

        const fileType = this.getFileTypeDetails(file);
        element.innerHTML = `
            <div class="file-icon">${fileType.icon}</div>
            <div class="file-details">
                <div class="file-name">${this.escapeHtml(file.name)}</div>
                <div class="file-meta">${this.formatSize(file.size)} ${this.formatDate(file.modified)}</div>
            </div>
            <div class="file-actions">
                <button class="action-btn rename" title="Rename"></button>
                <button class="action-btn delete" title="Delete"></button>
            </div>
        `;

        element.querySelector('.action-btn.rename').onclick = (e) => {
            e.stopPropagation();
            this.renameItem(file);
        };

        element.querySelector('.action-btn.delete').onclick = (e) => {
            e.stopPropagation();
            this.deleteItem(file);
        };

        element.onclick = (e) => {
            if (!e.target.closest('.action-btn')) {
                if (file.type === 'folder') {
                    this.loadDirectory(file.path);
                } else {
                    this.previewFile(file);
                }
            }
        };

        return element;
    }

    async deleteItem(file) {
        if (this.isProcessing) return;

        if (!confirm(`Are you sure you want to delete ${file.name}?`)) return;

        try {
            this.isProcessing = true;
            const formData = new FormData();
            formData.append('path', file.path);

            const response = await fetch('xshowxshow.php?action=delete', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.status === 'success') {
                await this.loadDirectory(this.currentPath);
            } else {
                throw new Error(`${result.message} Delete failed`);
            }
        } catch (error) {
            console.error('Delete error:', error);
            alert(`Failed to delete: ${error.message}`);
        } finally {
            this.isProcessing = false;
        }
    }

    async renameItem(file) {
        if (this.isProcessing) return;

        const newName = prompt(`Enter new name for ${file.name}`, file.name);
        if (!newName || newName === file.name) return;

        try {
            this.isProcessing = true;
            const formData = new FormData();
            formData.append('oldPath', file.path);
            formData.append('newName', newName);

            const response = await fetch('xshowxshow.php?action=rename', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.status === 'success') {
                await this.loadDirectory(this.currentPath);
            } else {
                throw new Error(`${result.message} Rename failed`);
            }
        } catch (error) {
            console.error('Rename error:', error);
            alert(`Failed to rename: ${error.message}`);
        } finally {
            this.isProcessing = false;
        }
    }

    async uploadFiles(files) {
        if (this.isProcessing) return;

        try {
            this.isProcessing = true;
            const formData = new FormData();
            for (let i = 0; i < files.length; i++) {
                formData.append('files[]', files[i]);
            }
            formData.append('path', this.currentPath);

            const response = await fetch('xshowxshow.php?action=upload', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.status === 'success') {
                await this.loadDirectory(this.currentPath);
            } else if (result.status === 'partial') {
                await this.loadDirectory(this.currentPath);
                alert('Some files failed to upload: ' + result.failed.join(', '));
            } else {
                throw new Error(`${result.message} Upload failed`);
            }
        } catch (error) {
            console.error('Upload error:', error);
            alert('Failed to upload: ' + error.message);
        } finally {
            this.isProcessing = false;
        }
    }

    // Utility functions
    getFileTypeDetails(file) {
        if (file.type === 'folder') return { icon: '📁' };

        const mimeMap = {
            'text/': '📝',
            'image/': '🖼️',
            'video/': '🎥',
            'audio/': '🎵',
            'application/pdf': '📄',
            'application/zip': '📦',
            'application/x-compressed': '📦',
            'application/x-zip-compressed': '📦',
            'application/javascript': '📜',
            'application/json': '📜',
            'text/markdown': '📝',
            'text/md': '📝'
        };

        if (file.name.endsWith('.md')) return { icon: '📝' };

        for (const [mime, icon] of Object.entries(mimeMap)) {
            if (file.mime.startsWith(mime)) return { icon };
        }

        return { icon: '📄' };
    }

    formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${sizes[i]}`;
    }

    formatDate(dateString) {
        return new Date(dateString).toLocaleString();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    createLoadingAnimation() {
        return '<div class="loader"></div>';
    }

    createEmptyState() {
        return `
            <div class="empty-state">
                <div class="file-icon">📁</div>
                <h3>This folder is empty</h3>
                <p>No files or folders to display</p>
            </div>
        `;
    }

    createErrorMessage(message) {
        return `
            <div class="error-message">
                <div class="file-icon">⚠️</div>
                <p>${this.escapeHtml(message)}</p>
            </div>
        `;
    }

    createNoPreviewMessage() {
        return `
            <div class="empty-state">
                <div class="file-icon">📄</div>
                <h3>Preview not available</h3>
                <p>This file type cannot be previewed</p>
            </div>
        `;
    }

    searchFiles(query) {
        const files = Array.from(this.container.children);
        const searchTerm = query.toLowerCase();
        files.forEach(file => {
            const fileName = file.querySelector('.file-name').textContent.toLowerCase();
            file.style.display = fileName.includes(searchTerm) ? '' : 'none';
        });
    }

    updateBreadcrumb(path) {
        const parts = path.split('/').filter(Boolean);
        let html = '<span onclick="fileExplorer.loadDirectory(\'\')">Home</span>';
        let currentPath = '';
        parts.forEach(part => {
            currentPath += '/' + part;
            html += `<span onclick="fileExplorer.loadDirectory('${this.escapeHtml(currentPath.slice(1))}')">${this.escapeHtml(part)}</span>`;
        });
        this.breadcrumb.innerHTML = html;
    }

    async previewFile(file) {
        if (this.isProcessing || this.modal.classList.contains('active')) return;

        this.isProcessing = true;
        this.currentFile = file;
        const previewContainer = document.getElementById('previewContainer');
        const previewTitle = document.getElementById('previewTitle');
        const previewActions = document.querySelector('#previewModal .preview-actions');

        previewTitle.textContent = file.name;
        previewContainer.innerHTML = this.createLoadingAnimation();

        // Add edit button for markdown files
        if (file.mime === 'text/markdown' || file.name.endsWith('.md')) {
            // Remove existing edit button if any
            const existingEditBtn = previewActions.querySelector('.edit-btn');
            if (existingEditBtn) existingEditBtn.remove();

            // Create new edit button
            const editBtn = document.createElement('button');
            editBtn.className = 'btn-secondary edit-btn';
            editBtn.textContent = 'Edit';
            editBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const editorUrl = `xshowxshow.php?editor&path=${encodeURIComponent(file.path)}`;
                window.location = editorUrl;
                return false;
            });

            // Insert at the beginning of preview actions
            if (previewActions.firstChild) {
                previewActions.insertBefore(editBtn, previewActions.firstChild);
            } else {
                previewActions.appendChild(editBtn);
            }
        }

        this.modal.classList.add('active');

        try {
            if (file.mime.startsWith('image/')) {
                previewContainer.innerHTML = `<img src="xshowxshow.php?view=${encodeURIComponent(file.path)}" class="preview-image" alt="${this.escapeHtml(file.name)}">`;
            } else if (file.mime.startsWith('text/') || file.mime === 'application/javascript' || file.mime === 'application/json') {
                const response = await fetch(`xshowxshow.php?view=${encodeURIComponent(file.path)}`);
                const text = await response.text();
                previewContainer.innerHTML = `<pre class="preview-text">${this.escapeHtml(text)}</pre>`;
            } else {
                previewContainer.innerHTML = this.createNoPreviewMessage();
            }
        } catch (error) {
            console.error('Preview error:', error);
            previewContainer.innerHTML = this.createErrorMessage('Failed to load preview');
        } finally {
            this.isProcessing = false;
        }
    }

    openInNewTab() {
        if (this.isProcessing || !this.currentFile) return;

        try {
            const url = `xshowxshow.php?view=${encodeURIComponent(this.currentFile.path)}`;
            window.open(url, '_blank');
        } catch (error) {
            console.error('Error opening new tab:', error);
        }
    }

    closePreview() {
        this.modal?.classList.remove('active');
        this.currentFile = null;
    }
}

// Utility function to scan directory
async function scanDirectory(path) {
    try {
        const response = await fetch(`xshowxshow.php?action=scanpath&path=${path}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const result = await response.json();
        if (result.status === 'error') throw new Error(result.message);
        return result;
    } catch (error) {
        console.error('Scan error:', error);
        return { status: 'error', message: `Failed to load directory: ${error.message}` };
    }
}

// Utility function to handle logout
async function logout() {
    try {
        const response = await fetch('xshowxshow.php?action=logout');
        const result = await response.json();
        if (result.status === 'success') {
            window.location.reload();
        }
    } catch (error) {
        console.error('Logout error:', error);
        alert('Logout failed: ' + error.message);
    }
}

// Initialize FileExplorer when document is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.fileExplorer = new FileExplorer();
});
