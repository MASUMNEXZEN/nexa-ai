
    (function() {
      var ua = navigator.userAgent || '';
      // Detect: Telegram, Instagram, Facebook, Line, Snapchat, and generic Android WebView
      var isInApp = /Telegram|TelegramBot|Instagram|FBAN|FBAV|Line\/|Snapchat|; wv\)/i.test(ua);
      // Don't trigger if it's a standalone PWA or desktop
      var isDesktop = !/Android|iPhone|iPad|iPod/i.test(ua);
      var isPWA = window.matchMedia('(display-mode: standalone)').matches;
      if (!isInApp || isDesktop || isPWA) return;

      var url = window.location.href;
      // Android: intent to open directly in Chrome
      var intentUrl = 'intent://' + url.replace(/^https?:\/\//, '') + '#Intent;scheme=https;package=com.android.chrome;end';
      // iOS: can't force Chrome, but show copy option
      var isIOS = /iPhone|iPad|iPod/i.test(ua);

      document.addEventListener('DOMContentLoaded', function() {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#11152A;display:flex;align-items:center;justify-content:center;flex-direction:column;padding:32px;text-align:center;font-family:system-ui,sans-serif;';
        overlay.innerHTML = '<img src="/logo-icon.png?v=4" style="width:64px;height:64px;border-radius:14px;margin-bottom:20px;">'
          + '<h2 style="color:#F1F3FF;font-size:22px;margin:0 0 8px;">Open in Browser</h2>'
          + '<p style="color:#AEB6CA;font-size:14px;line-height:1.6;max-width:320px;margin:0 0 24px;">For the best experience and Google Sign-In, please open this in your browser.</p>'
          + (isIOS
              ? '<button id="copyBtn" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#625BEE,#4F46E5);color:#fff;font-size:15px;font-weight:600;border:none;border-radius:14px;cursor:pointer;margin-bottom:12px;box-shadow:0 4px 20px rgba(98,91,238,0.3);">Copy Link & Open Safari</button>'
              : '<a href="' + intentUrl + '" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#625BEE,#4F46E5);color:#fff;font-size:15px;font-weight:600;border-radius:14px;text-decoration:none;margin-bottom:12px;box-shadow:0 4px 20px rgba(98,91,238,0.3);">Open in Chrome</a>')
          + '<button data-login-action="copy-link" style="background:none;border:1px solid rgba(255,255,255,0.12);color:#AEB6CA;font-size:13px;padding:10px 24px;border-radius:12px;cursor:pointer;">Copy Link</button>'
          + '<p style="color:#454e68;font-size:11px;margin-top:20px;">Paste in Chrome or Safari for Google Sign-In</p>';
        document.body.appendChild(overlay);

        // iOS copy button
        var copyBtn = document.getElementById('copyBtn');
        if (copyBtn) {
          copyBtn.addEventListener('click', function() {
            navigator.clipboard.writeText(url);
            copyBtn.textContent = 'Copied! Now open Safari';
            copyBtn.style.background = '#22c55e';
          });
        }
      });
    })();


    function toggleView(id) {
      document.querySelectorAll('.view').forEach(el => el.classList.remove('active'));
      document.getElementById(id).classList.add('active');
    }
    function showToast(msg, type = 'error') {
      const t = document.getElementById('toast');
      t.textContent = msg; t.className = `show ${type}`;
      setTimeout(() => t.className = '', 4000);
    }
    function goToApp() {
      document.body.classList.add('page-exit');
      setTimeout(() => window.location.href = '/', 200);
    }

    // Password strength indicator
    function updateStrength(pw) {
      const bar = document.getElementById('strengthBar');
      const label = document.getElementById('strengthLabel');
      if (!pw) { bar.style.width = '0%'; label.textContent = ''; return; }
      let score = 0;
      if (pw.length >= 6) score++;
      if (pw.length >= 10) score++;
      if (/[A-Z]/.test(pw)) score++;
      if (/[0-9]/.test(pw)) score++;
      if (/[^A-Za-z0-9]/.test(pw)) score++;
      const levels = [
        { w: '20%', c: '#7A78F5', t: 'Weak' },
        { w: '40%', c: '#f97316', t: 'Fair' },
        { w: '60%', c: '#eab308', t: 'Fair' },
        { w: '80%', c: '#22c55e', t: 'Good' },
        { w: '100%', c: '#10b981', t: 'Strong' }
      ];
      const l = levels[Math.min(score, 4)];
      bar.style.width = l.w; bar.style.background = l.c;
      label.textContent = l.t; label.style.color = l.c;
    }

    // Google OAuth
    async function handleGoogleCredential(resp) {
      showToast('Signing in with Google...', 'info');
      try {
        const res = await fetch('/api/auth-google.php', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ credential: resp.credential })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Google login failed.');
        showToast('Welcome! Redirecting...', 'success');
        setTimeout(goToApp, 800);
      } catch (err) { showToast(err.message); }
    }

    // Sign In
    document.getElementById('loginForm').addEventListener('submit', async e => {
      e.preventDefault();
      const btn = document.getElementById('loginBtn');
      btn.textContent = 'Signing in...'; btn.disabled = true;
      try {
        const res = await fetch('/api/auth-login.php', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            email: document.getElementById('loginEmail').value,
            password: document.getElementById('loginPassword').value
          })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Login failed.');
        goToApp();
      } catch (err) {
        showToast(err.message);
        btn.textContent = 'Sign In'; btn.disabled = false;
      }
    });

    // Sign Up -> Send OTP
    let pendingEmail = '';
    document.getElementById('signupForm').addEventListener('submit', async e => {
      e.preventDefault();
      const btn = document.getElementById('signupBtn');
      btn.textContent = 'Sending code...'; btn.disabled = true;
      try {
        const email    = document.getElementById('signupEmail').value;
        const password = document.getElementById('signupPassword').value;
        const name     = document.getElementById('signupName').value;
        const country  = document.getElementById('signupCountry').value;
        const state    = document.getElementById('signupState').value;
        const district = document.getElementById('signupDistrict').value;
        const pin      = document.getElementById('signupPin').value;
        const address  = document.getElementById('signupAddress').value;

        const payload = { email, password, name, country, state, district, pin, address };

        const res = await fetch('/api/auth-register.php', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Registration failed.');
        pendingEmail = email;
        document.getElementById('otpEmailLabel').textContent = email;
        toggleView('otpView');
        document.getElementById('otp0').focus();
        startResendTimer(60);
        showToast(data.dev_otp ? ('Local verification code: ' + data.dev_otp) : 'Code sent! Check your inbox.', 'success');
      } catch (err) {
        showToast(err.message);
        btn.textContent = 'Send Verification Code ->'; btn.disabled = false;
      }
    });

    // OTP boxes
    document.querySelectorAll('.otp-box').forEach((box, idx, all) => {
      box.addEventListener('input', () => {
        box.value = box.value.replace(/\D/g, '').slice(-1);
        box.classList.toggle('filled', !!box.value);
        if (box.value && idx < all.length - 1) all[idx + 1].focus();
        if (idx === all.length - 1 && box.value) verifyOtp();
      });
      box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && idx > 0) all[idx - 1].focus();
      });
      box.addEventListener('paste', e => {
        e.preventDefault();
        const digits = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
        all.forEach((b, i) => { b.value = digits[i] || ''; b.classList.toggle('filled', !!b.value); });
        if (digits.length === 6) verifyOtp();
        else all[Math.min(digits.length, 5)].focus();
      });
    });

    // Verify OTP
    async function verifyOtp() {
      const otp = Array.from(document.querySelectorAll('.otp-box')).map(b => b.value).join('');
      if (otp.length < 6) { showToast('Enter all 6 digits.'); return; }
      const btn = document.getElementById('otpBtn');
      btn.textContent = 'Verifying...'; btn.disabled = true;
      try {
        const payload = {
            email: pendingEmail,
            otp: otp,
            password: document.getElementById('signupPassword').value,
            name: document.getElementById('signupName').value,
            country: document.getElementById('signupCountry').value,
            state: document.getElementById('signupState').value,
            district: document.getElementById('signupDistrict').value,
            pin: document.getElementById('signupPin').value,
            address: document.getElementById('signupAddress').value
        };
        const res = await fetch('/api/auth-verify-otp.php', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Verification failed.');
        showToast('Account created! Welcome!', 'success');
        setTimeout(goToApp, 900);
      } catch (err) {
        showToast(err.message);
        document.querySelectorAll('.otp-box').forEach(b => { b.value = ''; b.classList.remove('filled'); });
        document.getElementById('otp0').focus();
        btn.textContent = 'Verify & Create Account'; btn.disabled = false;
      }
    }

    // Resend timer
    let resendInterval;
    function startResendTimer(sec) {
      const link = document.getElementById('resendLink');
      const timer = document.getElementById('resendTimer');
      link.style.display = 'none'; timer.textContent = `Resend in ${sec}s`;
      clearInterval(resendInterval);
      let s = sec;
      resendInterval = setInterval(() => {
        s--;
        if (s <= 0) { clearInterval(resendInterval); timer.textContent = ''; link.style.display = 'inline'; }
        else timer.textContent = `Resend in ${s}s`;
      }, 1000);
    }

    async function resendOtp() {
      const passEl = document.getElementById('signupPassword');
      showToast('Resending...', 'info');
      try {
        const res = await fetch('/api/auth-register.php', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
              email: pendingEmail,
              password: passEl.value,
              name: document.getElementById('signupName').value,
              country: document.getElementById('signupCountry').value,
              state: document.getElementById('signupState').value,
              district: document.getElementById('signupDistrict').value,
              pin: document.getElementById('signupPin').value,
              address: document.getElementById('signupAddress').value
          })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed.');
        showToast(data.dev_otp ? ('Local verification code: ' + data.dev_otp) : 'New code sent!', 'success');
        document.querySelectorAll('.otp-box').forEach(b => { b.value = ''; b.classList.remove('filled'); });
        document.getElementById('otp0').focus();
        startResendTimer(60);
      } catch (err) { showToast(err.message); }
    }

    // -- Forgot Password --
    let resetEmail = '';

    document.getElementById('forgotForm').addEventListener('submit', async e => {
      e.preventDefault();
      const btn = document.getElementById('forgotBtn');
      btn.textContent = 'Sending...'; btn.disabled = true;
      try {
        const email = document.getElementById('forgotEmail').value;
        const res = await fetch('/api/auth-forgot-password.php', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed.');
        resetEmail = email;
        document.getElementById('resetEmailLabel').textContent = email;
        toggleView('resetView');
        document.getElementById('rotp0').focus();
        showToast('Reset code sent! Check your inbox.', 'success');
      } catch (err) {
        showToast(err.message);
      }
      btn.textContent = 'Send Reset Code'; btn.disabled = false;
    });

    // Reset OTP box auto-advance
    document.querySelectorAll('.reset-otp').forEach((box, idx, all) => {
      box.addEventListener('input', () => {
        box.value = box.value.replace(/\D/g, '').slice(-1);
        box.classList.toggle('filled', !!box.value);
        if (box.value && idx < all.length - 1) all[idx + 1].focus();
      });
      box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && idx > 0) all[idx - 1].focus();
      });
      box.addEventListener('paste', e => {
        e.preventDefault();
        const digits = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
        all.forEach((b, i) => { b.value = digits[i] || ''; b.classList.toggle('filled', !!b.value); });
        if (digits.length === 6) resetPassword();
        else all[Math.min(digits.length, 5)].focus();
      });
    });

    async function resetPassword() {
      const otp = Array.from(document.querySelectorAll('.reset-otp')).map(b => b.value).join('');
      if (otp.length < 6) { showToast('Enter all 6 digits.'); return; }
      const newPw = document.getElementById('resetNewPassword').value;
      if (newPw.length < 8 || !/[A-Z]/.test(newPw) || !/[0-9]/.test(newPw)) { showToast('Password must be at least 8 characters with one uppercase letter and one number.'); return; }
      const btn = document.getElementById('resetBtn');
      btn.textContent = 'Resetting...'; btn.disabled = true;
      try {
        const res = await fetch('/api/auth-reset-password.php', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: resetEmail, otp, new_password: newPw })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Reset failed.');
        showToast('Password reset! Redirecting...', 'success');
        setTimeout(goToApp, 900);
      } catch (err) {
        showToast(err.message);
        document.querySelectorAll('.reset-otp').forEach(b => { b.value = ''; b.classList.remove('filled'); });
        document.getElementById('rotp0').focus();
        btn.textContent = 'Reset Password'; btn.disabled = false;
      }
    }

document.addEventListener('DOMContentLoaded', () => {
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-login-action]');
    if (!trigger) return;
    const action = trigger.dataset.loginAction;
    if (action === 'toggle-view') toggleView(trigger.dataset.view || 'loginView');
    else if (action === 'reset-password') resetPassword();
    else if (action === 'verify-otp') verifyOtp();
    else if (action === 'resend-otp') resendOtp();
    else if (action === 'copy-link') {
      event.preventDefault();
      navigator.clipboard.writeText(window.location.href)
        .then(() => { trigger.textContent = '? Link Copied!'; })
        .catch((error) => console.info('Copy link unavailable:', error.message));
    }
  });

  document.querySelectorAll('[data-login-action="strength"]').forEach((field) => {
    field.addEventListener('input', () => updateStrength(field.value));
  });
});
