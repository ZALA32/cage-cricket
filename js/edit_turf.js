document.addEventListener('DOMContentLoaded', function () {
  // Client-side form validation
  const form = document.getElementById('edit-turf-form');
  if (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  }

  // Elements
  const fileInput    = document.getElementById('turf_photo');
  const fileFeedback = document.getElementById('file-feedback');
  const preview      = document.getElementById('preview');
  const previewImg   = document.getElementById('preview-img');
  const removeCheck  = document.getElementById('remove_photo');
  const currentImg   = document.getElementById('current-photo-img');
  const currentWrap  = document.querySelector('.current-photo-wrap') || currentImg?.closest('.current-photo-wrap');

  let lastBlobURL = null;

  function clearPreview() {
    if (preview) preview.style.display = 'none';
    if (previewImg) previewImg.removeAttribute('src');
    if (lastBlobURL) { try { URL.revokeObjectURL(lastBlobURL); } catch(_) {} lastBlobURL = null; }
  }

  function showPreview(file) {
    clearPreview();
    if (!file || !file.type || !file.type.startsWith('image/')) return;
    lastBlobURL = URL.createObjectURL(file);
    if (previewImg) previewImg.src = lastBlobURL;
    if (preview) preview.style.display = 'block';
  }

  // File input -> label, preview, and hide/show current image block
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      const file = this.files && this.files[0] ? this.files[0] : null;

      if (fileFeedback) {
        fileFeedback.textContent = file ? file.name : 'Choose a file to replace the current photo (optional).';
      }

      showPreview(file);

      // If a new file is chosen, visually hide current image block and uncheck "remove"
      if (currentWrap) currentWrap.style.display = file ? 'none' : '';
      if (removeCheck && file) {
        removeCheck.checked = false;
      }
    });
  }

  // Remove photo UX
  if (removeCheck) {
    removeCheck.addEventListener('change', () => {
      const removing = removeCheck.checked;
      // Hide current image block when removing
      if (currentWrap) currentWrap.style.display = removing ? 'none' : '';
      if (currentImg) currentImg.style.opacity = removing ? '0.5' : '1';

      // Clear any newly chosen file/preview if removing
      if (removing) {
        if (fileInput) fileInput.value = '';
        clearPreview();
        if (fileFeedback) fileFeedback.textContent = 'Current photo will be removed.';
      } else {
        if (fileFeedback) fileFeedback.textContent = 'Choose a file to replace the current photo (optional).';
      }
    });
  }

  // Format contact number input (digits only, max 10)
  const contactInput = document.querySelector('input[name="turf_contact"]');
  if (contactInput) {
    contactInput.addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 10);
    });
  }

  // Auto-dismiss alerts
  const alerts = document.querySelectorAll('.alert-container .alert');
  alerts.forEach(alert => {
    setTimeout(() => {
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 600);
    }, 5000);
  });
});
