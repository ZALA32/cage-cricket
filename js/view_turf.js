document.addEventListener("DOMContentLoaded", function () {
  // Auto-dismiss any alert
  const alert = document.querySelector('#form-alert');
  if (alert) {
    setTimeout(() => {
      alert.classList.remove('show');
      alert.classList.add('fade');
      setTimeout(() => alert.remove(), 500);
    }, 5000);
  }

  // Bootstrap tooltips (if any)
  const tooltipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  tooltipEls.forEach(el => new bootstrap.Tooltip(el));

  // Open hero image in new tab for clarity
  const viewBtn = document.querySelector('.view-full');
  if (viewBtn) {
    viewBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const src = viewBtn.getAttribute('data-src');
      if (src) window.open(src, '_blank');
    });
  }
});
