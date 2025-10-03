document.addEventListener('DOMContentLoaded', function () {
  const input    = document.getElementById('confirm_text');
  const ack      = document.getElementById('ack');
  const matchBar = document.getElementById('match-bar');
  const openBtn  = document.getElementById('open-modal');

  if (!input || !ack || !openBtn) return;

  // Turf name comes from the placeholder (server rendered)
  const required = input.getAttribute('placeholder') || '';

  // If we somehow have no required text, keep the button disabled
  if (!required) {
    openBtn.disabled = true;
  }

  function computeMatchPercent(actual, target) {
    if (!actual) return 0;
    const max = target.length;
    let same = 0;
    for (let i = 0; i < Math.min(actual.length, max); i++) {
      if (actual[i] === target[i]) same++; else break;
    }
    return Math.round((same / Math.max(max, 1)) * 100);
  }

  function setBarColor(pct, exact) {
    // strip any previous classes
    matchBar.classList.remove('bg-success', 'bg-warning', 'bg-danger');
    if (exact) {
      matchBar.classList.add('bg-success');
    } else if (pct >= 50) {
      matchBar.classList.add('bg-warning');
    } else {
      matchBar.classList.add('bg-danger');
    }
  }

  function updateState() {
    const value  = input.value || '';
    const okText = (value === required); // exact match (same as PHP)
    const okAck  = ack.checked;

    const pct = computeMatchPercent(value, required);
    if (matchBar) {
      matchBar.style.width = pct + '%';
      matchBar.setAttribute('aria-valuenow', String(pct));
      setBarColor(pct, okText);
    }

    // Visual validity hint on the input (Bootstrap friendly)
    input.classList.toggle('is-valid', okText);
    input.classList.toggle('is-invalid', !okText && value.length > 0);

    openBtn.disabled = !(okText && okAck && !!required);
  }

  input.addEventListener('input', updateState);
  ack.addEventListener('change', updateState);
  updateState();

  // Optional: press Enter to open modal if enabled
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !openBtn.disabled) {
      e.preventDefault();
      openBtn.click();
    }
  });

  // Auto-dismiss alert (matches your other pages)
  const alert = document.querySelector('#form-alert');
  if (alert) {
    setTimeout(() => {
      alert.classList.remove('show');
      alert.classList.add('fade');
      setTimeout(() => alert.remove(), 450);
    }, 5000);
  }
});
