function togglePass() {
  const field = document.getElementById('loginPass');
  const button = document.querySelector('[data-admin-login-action="toggle-password"]');
  if (!field) return;
  field.type = field.type === 'password' ? 'text' : 'password';
  if (button) button.setAttribute('aria-label', field.type === 'password' ? 'Show password' : 'Hide password');
}

function showToast(message, type = 'success') {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.textContent = message;
  toast.className = 'show ' + type;
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => { toast.className = ''; }, 3500);
}

async function doLogin() {
  const emailField = document.getElementById('loginEmail');
  const passwordField = document.getElementById('loginPass');
  const button = document.getElementById('loginBtn');
  const email = emailField?.value.trim() || '';
  const password = passwordField?.value || '';
  if (!email || !password) {
    showToast('Identity and passphrase required.', 'error');
    return;
  }

  button.textContent = 'Verifying...';
  button.disabled = true;
  try {
    const response = await fetch('/api/admin-login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email, password }),
    });
    const data = await response.json();
    if (response.ok && data.success) {
      window.location.href = '/admin';
      return;
    }
    showToast(data.error || 'Authentication failed', 'error');
  } catch (error) {
    console.error('Admin login request failed:', error);
    showToast('Network timeout. Secure channel offline.', 'error');
  } finally {
    button.textContent = 'Authenticate Session';
    button.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelector('[data-admin-login-action="toggle-password"]')?.addEventListener('click', togglePass);
  document.querySelector('[data-admin-login-action="submit"]')?.addEventListener('click', doLogin);
  document.getElementById('loginPass')?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') doLogin();
  });

  fetch('/api/admin-check.php', { credentials: 'include' })
    .then((response) => response.json())
    .then((data) => {
      if (data.loggedIn) window.location.href = '/admin';
    })
    .catch((error) => console.info('Admin session check unavailable:', error.message));
});