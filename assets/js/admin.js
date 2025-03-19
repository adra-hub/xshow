class AdminPanel {
    constructor() {
        this.userModal = document.getElementById('userModal');
        this.currentUserId = null;
        this.isEditing = false;
        
        this.initializeEventListeners();
        this.loadUsers();
    }

    initializeEventListeners() {
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', (e) => {
                this.switchTab(e.target.dataset.tab);
            });
        });

        // Add user button
        document.querySelector('.add-user-btn').addEventListener('click', () => {
            this.showUserModal();
        });

        // Modal close button
        this.userModal.querySelector('.modal-close').addEventListener('click', () => {
            this.closeUserModal();
        });

        // Modal action buttons
        this.userModal.querySelector('[data-action="cancel"]').addEventListener('click', () => {
            this.closeUserModal();
        });

        this.userModal.querySelector('[data-action="save"]').addEventListener('click', () => {
            this.saveUser();
        });

        // Close modal when clicking outside
        this.userModal.addEventListener('click', (e) => {
            if (e.target === this.userModal) {
                this.closeUserModal();
            }
        });

        // ESC key to close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.userModal.classList.contains('active')) {
                this.closeUserModal();
            }
        });
    }

    switchTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabId);
        });
        
        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.toggle('active', pane.id === tabId);
        });
    }

    async loadUsers() {
        try {
            const response = await fetch('/xshow/xshow.php?action=get_users');
            const result = await response.json();
            
            if (result.status === 'success') {
                this.renderUsers(result.users);
            } else {
                this.showError(result.message);
            }
        } catch (error) {
            this.showError('Failed to load users');
        }
    }

    renderUsers(users) {
        const userList = document.querySelector('.user-list');
        userList.innerHTML = '';

        users.forEach(user => {
            const userCard = document.createElement('div');
            userCard.className = 'user-card';
            userCard.innerHTML = `
                <div class="user-info">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.5"/>
                        <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.5"/>
                    </svg>
                    <span>${user.username}</span>
                </div>
                <div class="user-actions">
                    <button class="action-btn edit" title="Change Password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15 7l2 2m-8.5 8.5L19 7l-2-2L6.5 15.5l-2 6 6-2z" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                    ${user.username !== 'admin' ? `
                        <button class="action-btn delete" title="Delete User">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M19 7l-3 13H8L5 7m5 4v6m4-6v6M10 7V4h4v3m-9 0h14" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </button>
                    ` : ''}
                </div>
            `;

            // Add event listeners
            userCard.querySelector('.edit').addEventListener('click', () => {
                this.showUserModal(user);
            });

            const deleteBtn = userCard.querySelector('.delete');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', () => {
                    this.deleteUser(user.username);
                });
            }

            userList.appendChild(userCard);
        });
    }

    showUserModal(user = null) {
        this.isEditing = !!user;
        this.currentUserId = user ? user.username : null;
        
        const title = this.userModal.querySelector('#userModalTitle');
        const usernameInput = this.userModal.querySelector('#username');
        
        title.textContent = user ? 'Change Password' : 'Add New User';
        usernameInput.value = user ? user.username : '';
        usernameInput.disabled = !!user;
        
        this.userModal.querySelector('#password').value = '';
        this.userModal.querySelector('#confirmPassword').value = '';
        
        this.userModal.classList.add('active');
        this.userModal.querySelector('#username').focus();
    }

    closeUserModal() {
        this.userModal.classList.remove('active');
        this.currentUserId = null;
        this.isEditing = false;
    }

    async saveUser() {
        const username = this.userModal.querySelector('#username').value;
        const password = this.userModal.querySelector('#password').value;
        const confirmPassword = this.userModal.querySelector('#confirmPassword').value;
    
        if (!username || !password) {
            this.showError('Please fill in all fields');
            return;
        }
    
        if (password !== confirmPassword) {
            this.showError('Passwords do not match');
            return;
        }
    
        // Enhanced password validation
        const errors = [];
        
        // Check length
        if (password.length < 8) {
            errors.push('Password must be at least 8 characters long');
        }
        
        // Check for uppercase letters
        if (!/[A-Z]/.test(password)) {
            errors.push('Password must contain at least one uppercase letter');
        }
        
        // Check for lowercase letters
        if (!/[a-z]/.test(password)) {
            errors.push('Password must contain at least one lowercase letter');
        }
        
        // Check for numbers
        if (!/[0-9]/.test(password)) {
            errors.push('Password must contain at least one number');
        }
        
        // Check for special characters
        if (!/[^A-Za-z0-9]/.test(password)) {
            errors.push('Password must contain at least one special character');
        }
        
        if (errors.length > 0) {
            this.showError(errors.join('. '));
            return;
        }
    
        try {
            const action = this.isEditing ? 'change_password' : 'add_user';
            const response = await fetch(`/xshow/xshow.php?action=${action}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`
            });
    
            const result = await response.json();
            
            if (result.status === 'success') {
                this.closeUserModal();
                this.loadUsers();
                this.showSuccess(this.isEditing ? 'Password updated successfully' : 'User added successfully');
            } else {
                this.showError(result.message);
            }
        } catch (error) {
            this.showError('Operation failed');
        }
    }

    async deleteUser(username) {
        if (!confirm(`Are you sure you want to delete user "${username}"?`)) {
            return;
        }

        try {
            const response = await fetch('/xshow/xshow.php?action=delete_user', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `username=${encodeURIComponent(username)}`
            });

            const result = await response.json();
            
            if (result.status === 'success') {
                this.loadUsers();
                this.showSuccess('User deleted successfully');
            } else {
                this.showError(result.message);
            }
        } catch (error) {
            this.showError('Failed to delete user');
        }
    }

    showError(message) {
        // You can implement this using your preferred notification system
        alert(message);
    }

    showSuccess(message) {
        // You can implement this using your preferred notification system
        alert(message);
    }
}

// Initialize AdminPanel when document is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.adminPanel = new AdminPanel();
});