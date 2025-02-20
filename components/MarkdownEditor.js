window.MarkdownEditor = class MarkdownEditor {
  constructor(container, options) {
      this.container = container;
      this.content = options.content || '';
      this.onSave = options.onSave || (() => {});
      this.editor = null;
      this.isEditing = false;
      
      this.init();
  }

  init() {
      this.render();
      this.loadDependencies().then(() => {
          this.initializeEditor();
      });
  }

  async loadDependencies() {
      // Load SimpleMDE CSS if not already loaded
      if (!document.querySelector('link[href*="simplemde"]')) {
          const link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = 'https://cdnjs.cloudflare.com/ajax/libs/simplemde/1.11.2/simplemde.min.css';
          document.head.appendChild(link);
      }

      // Load SimpleMDE JS if not already loaded
      if (!window.SimpleMDE) {
          await new Promise((resolve) => {
              const script = document.createElement('script');
              script.src = 'https://cdnjs.cloudflare.com/ajax/libs/simplemde/1.11.2/simplemde.min.js';
              script.onload = resolve;
              document.head.appendChild(script);
          });
      }

      // Load marked library if not already loaded
      if (!window.marked) {
          await new Promise((resolve) => {
              const script = document.createElement('script');
              script.src = 'https://cdn.jsdelivr.net/npm/marked/marked.min.js';
              script.onload = resolve;
              document.head.appendChild(script);
          });
      }
  }

  render() {
      this.container.innerHTML = `
          <div class="markdown-editor-container">
              <div class="editor-header">
                  <div class="editor-actions">
                      ${!this.isEditing ? `
                          <button class="edit-btn">Edit</button>
                      ` : `
                          <button class="save-btn">Save</button>
                          <button class="cancel-btn">Cancel</button>
                      `}
                      
                  </div>
              </div>
              <div class="editor-content">
                  ${this.isEditing ? `
                      <textarea id="markdown-editor"></textarea>
                  ` : `
                      <div class="preview-content markdown-body"></div>
                  `}
              </div>
          </div>
      `;

      this.attachEventListeners();
  }

  attachEventListeners() {
      const editBtn = this.container.querySelector('.edit-btn');
      const saveBtn = this.container.querySelector('.save-btn');
      const cancelBtn = this.container.querySelector('.cancel-btn');
      

      if (editBtn) editBtn.addEventListener('click', () => this.startEditing());
      if (saveBtn) saveBtn.addEventListener('click', () => this.save());
      if (cancelBtn) cancelBtn.addEventListener('click', () => this.cancelEditing());
      
  }

  startEditing() {
      this.isEditing = true;
      this.render();
      this.initializeEditor();
  }

  async save() {
      try {
          await this.onSave(this.editor.value());
          this.content = this.editor.value();
          this.isEditing = false;
          this.render();
          this.renderPreview();
      } catch (error) {
          alert('Failed to save: ' + error.message);
      }
  }

  cancelEditing() {
      this.isEditing = false;
      this.render();
      this.renderPreview();
  }

  initializeEditor() {
      if (this.isEditing) {
          const textarea = this.container.querySelector('#markdown-editor');
          if (textarea) {
              this.editor = new SimpleMDE({
                  element: textarea,
                  spellChecker: false,
                  status: ['lines', 'words', 'cursor'],
                  toolbar: [
                      'bold', 'italic', 'heading', '|',
                      'quote', 'unordered-list', 'ordered-list', '|',
                      'link', 'image', '|',
                      'preview', 'side-by-side', 'fullscreen', '|',
                      'guide'
                  ],
                  initialValue: this.content
              });
          }
      } else {
          this.renderPreview();
      }
  }

  renderPreview() {
      const previewEl = this.container.querySelector('.preview-content');
      if (previewEl && window.marked) {
          try {
              // Configure marked
              window.marked.use({
                  gfm: true,
                  breaks: true,
                  headerIds: true,
                  mangle: false
              });

              // Parse markdown
              const htmlContent = window.marked.parse(this.content);
              previewEl.innerHTML = htmlContent;
          } catch (error) {
              console.error('Markdown rendering error:', error);
              previewEl.innerHTML = '<div class="error">Error rendering markdown</div>';
          }
      } else {
          previewEl.innerHTML = this.content;
      }
  }
}