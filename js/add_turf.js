document.addEventListener('DOMContentLoaded', function () {
    // -------- Client-side form validation --------
    const form = document.querySelector('.needs-validation');
    if (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    }

    // -------- File input label update --------
    const fileInput = document.getElementById('turf_photo');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            const fileFeedback = document.getElementById('file-feedback');
            fileFeedback.textContent = this.files[0] ? this.files[0].name : 'Choose a file...';
        });
    }

    // -------- Contact number masking (10 digits) --------
    const contactInput = document.querySelector('input[name="turf_contact"]');
    if (contactInput) {
        contactInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length > 10) this.value = this.value.slice(0, 10);
        });
    }

    // -------- Auto-dismiss alerts --------
    const alerts = document.querySelectorAll('.alert-container .alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 600);
        }, 5000);
    });

    // -------- Confirmation modal + FULL details preview --------
    const openConfirm = document.getElementById('openConfirmModal');
    const confirmModalEl = document.getElementById('confirmAddModal');
    const confirmBtn = document.getElementById('confirmSubmitBtn');
    const previewWrap = document.getElementById('confirmPreview');

    // Keep reference to last used blob URL to avoid memory leaks
    let lastPreviewURL = null;

    function esc(str) {
        return (str ?? '')
            .toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function nl2br(str) {
        return esc(str).replace(/\n/g, '<br>');
    }

    function ownerText() {
        const sel = document.getElementById('owner_id');
        if (!sel) return '';
        const opt = sel.options[sel.selectedIndex];
        return opt && opt.value ? opt.text : '';
    }

    function revokePreviewURL() {
        if (lastPreviewURL) {
            try { URL.revokeObjectURL(lastPreviewURL); } catch (_) {}
            lastPreviewURL = null;
        }
    }

    function buildPreview() {
        const name      = document.getElementById('turf_name').value.trim();
        const contact   = document.getElementById('turf_contact').value.trim();
        const email     = document.getElementById('turf_email').value.trim();
        const price     = document.getElementById('booking_cost').value.trim();
        const capacity  = document.getElementById('turf_capacity').value.trim();
        const area      = document.getElementById('turf_area').value.trim();
        const address   = document.getElementById('turf_address').value.trim();
        const city      = document.getElementById('city').value.trim();
        const facility  = document.getElementById('turf_facility').value.trim();
        const desc      = document.getElementById('turf_description').value.trim();
        const owner     = ownerText();

        let photoName = '—';
        let photoPreview = '';

        // If a file is selected, show its name and a small thumbnail
        if (fileInput && fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0];
            photoName = file.name;

            if (file.type && file.type.startsWith('image/')) {
                // Revoke previous URL before creating a new one
                revokePreviewURL();
                lastPreviewURL = URL.createObjectURL(file);
                photoPreview = `<br><img src="${lastPreviewURL}" alt="Preview" class="img-thumbnail mt-2" style="max-height: 120px;">`;
            }
        } else {
            // No file selected; ensure any previous URL is released
            revokePreviewURL();
        }

        previewWrap.innerHTML = `
          <div class="table-responsive">
            <table class="table table-bordered align-middle">
              <tbody>
                ${owner ? `<tr><th scope="row" style="width:28%">Turf Owner</th><td>${esc(owner)}</td></tr>` : ''}
                <tr><th scope="row">Turf Name</th><td>${esc(name)}</td></tr>
                <tr><th scope="row">Contact Number</th><td>${esc(contact)}</td></tr>
                <tr><th scope="row">Turf Email</th><td>${esc(email)}</td></tr>
                <tr><th scope="row">Price per Hour</th><td>₹ ${esc(price)}</td></tr>
                <tr><th scope="row">Capacity</th><td>${esc(capacity)}</td></tr>
                <tr><th scope="row">Area</th><td>${esc(area)}</td></tr>
                <tr><th scope="row">City</th><td>${esc(city)}</td></tr>
                <tr><th scope="row">Address</th><td>${nl2br(address)}</td></tr>
                <tr><th scope="row">Facilities</th><td>${nl2br(facility) || '—'}</td></tr>
                <tr><th scope="row">Description</th><td>${nl2br(desc) || '—'}</td></tr>
                <tr><th scope="row">Photo</th><td>${esc(photoName)} ${photoPreview}</td></tr>
              </tbody>
            </table>
          </div>
        `;
    }

    if (openConfirm && confirmModalEl && confirmBtn && form) {
        const bsModal = new bootstrap.Modal(confirmModalEl);

        // Open modal after validating; populate preview
        openConfirm.addEventListener('click', function () {
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }
            buildPreview();
            bsModal.show();
        });

        // Submit form once confirmed
        confirmBtn.addEventListener('click', function () {
            form.submit();
        });

        // Revoke blob URL when the modal is hidden
        confirmModalEl.addEventListener('hidden.bs.modal', revokePreviewURL);

        // If a new file is selected while modal is open, rebuild & revoke old URL
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (confirmModalEl.classList.contains('show')) {
                    buildPreview();
                }
            });
        }

        // Keep preview live-updating while the modal is open
        [
          'turf_name','turf_contact','turf_email','booking_cost','turf_capacity',
          'turf_area','turf_address','city','turf_facility','turf_description','owner_id'
        ].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', () => {
                    if (confirmModalEl.classList.contains('show')) buildPreview();
                });
                el.addEventListener('change', () => {
                    if (confirmModalEl.classList.contains('show')) buildPreview();
                });
            }
        });
    }
});
