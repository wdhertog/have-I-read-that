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
document.querySelectorAll('[data-height]').forEach((el) => {
  el.style.height = el.dataset.height + '%';
});

// QR code for setting up two-step verification (library loaded only on that page).
const qrTarget = document.getElementById('totp-qr');
if (qrTarget && window.qrcode) {
  const qr = window.qrcode(0, 'M');
  qr.addData(qrTarget.dataset.uri);
  qr.make();
  qrTarget.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
}

// Sharing a book: the phone's share sheet (Signal, WhatsApp, mail…) where there is one,
// else copy the text. WhatsApp's link follows what you typed.
const shareText = (id) => (document.getElementById(id) || {}).value || '';
const copyText = async (button, text) => {
  try {
    await navigator.clipboard.writeText(text);
  } catch (e) {
    const area = document.getElementById(button.dataset.share || button.dataset.copy);
    area.select();
    document.execCommand('copy');
  }
  const label = button.textContent;
  button.textContent = 'Gekopieerd ✓';
  setTimeout(() => { button.textContent = label; }, 2000);
};
document.querySelectorAll('[data-share]').forEach((button) => {
  button.addEventListener('click', async () => {
    const text = shareText(button.dataset.share);
    if (navigator.share) {
      try {
        await navigator.share({ text });
      } catch (e) {
        // Closed without choosing: nothing to do.
      }
    } else {
      copyText(button, text);
    }
  });
});
document.querySelectorAll('[data-copy]').forEach((button) => {
  button.addEventListener('click', () => copyText(button, shareText(button.dataset.copy)));
});
document.querySelectorAll('[data-whatsapp]').forEach((link) => {
  link.addEventListener('click', () => {
    link.href = 'https://wa.me/?text=' + encodeURIComponent(shareText(link.dataset.whatsapp));
  });
});

// The account menu in the header: open and close, also with Escape or a click elsewhere.
document.querySelectorAll('[data-menu]').forEach((button) => {
  const menu = document.getElementById(button.dataset.menu);
  if (!menu) return;
  const setOpen = (open) => {
    menu.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
  };
  button.addEventListener('click', (event) => {
    event.stopPropagation();
    setOpen(menu.hidden);
    if (!menu.hidden) {
      const first = menu.querySelector('a, button');
      if (first) first.focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (!menu.hidden && !menu.contains(event.target)) setOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !menu.hidden) {
      setOpen(false);
      button.focus();
    }
  });
});

// Shelf cards: the pencil opens the rename form underneath.
document.querySelectorAll('.shelf-card details').forEach((details) => {
  const form = details.closest('.shelf-card').querySelector('form.rename');
  if (!form) return;
  details.addEventListener('toggle', () => {
    form.hidden = !details.open;
    if (details.open) form.querySelector('input[name=to]').focus();
  });
});
