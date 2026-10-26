// Small progressive enhancements, kept out of the HTML so the CSP can forbid inline scripts.

// Ask before destructive actions: <form data-confirm="Zeker?">
document.addEventListener('submit', (event) => {
  const message = event.target.dataset.confirm;
  if (message && !window.confirm(message)) {
    event.preventDefault();
  }
});

// Hide covers Open Library doesn't have, so the title placeholder shows.
document.addEventListener('error', (event) => {
  if (event.target.matches && event.target.matches('.cover img')) {
    event.target.remove();
  }
}, true);

// Bar widths on the stats page: <span data-width="42.5">
document.querySelectorAll('[data-width]').forEach((el) => {
  el.style.width = el.dataset.width + '%';
});

// QR code for setting up two-step verification (library loaded only on that page).
const qrTarget = document.getElementById('totp-qr');
if (qrTarget && window.qrcode) {
  const qr = window.qrcode(0, 'M');
  qr.addData(qrTarget.dataset.uri);
  qr.make();
  qrTarget.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
}
