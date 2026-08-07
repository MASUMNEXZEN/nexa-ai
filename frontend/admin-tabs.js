// NexA AI — Admin Tabs Engine v1
// Handles: tab switching, subscriptions, plans, app config, push notifications

/* ── TAB SWITCHING ────────────────────────────────────────── */
function switchTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
  document.getElementById('tab-' + tab).style.display = (tab === 'dashboard') ? 'grid' : 'block';

  const loaders = {
    subscriptions: loadSubscriptions,
    plans:         loadPlans,
    users:         () => {}, // users loaded by admin-engine.js on init
    appconfig:     loadAppConfig,
    push:          () => {},
    telegram:      loadTelegram,
  };
  if (loaders[tab]) loaders[tab]();
}

function refreshAll() {
  if (typeof fetchStats === 'function') fetchStats();
  const activeTab = document.querySelector('.tab-btn.active')?.dataset?.tab;
  if (activeTab && activeTab !== 'dashboard') switchTab(activeTab);
}

/* ── SUBSCRIPTIONS / REVENUE ─────────────────────────────── */
function renderSubscribersTable(tbody, subscribers) {
  tbody.replaceChildren();
  if (!subscribers || subscribers.length === 0) {
    const row = tbody.insertRow();
    const cell = row.insertCell();
    cell.colSpan = 6;
    cell.className = 'empty-state-cell';
    cell.textContent = 'No subscribers yet';
    return;
  }

  subscribers.forEach((subscriber) => {
    const row = tbody.insertRow();
    const email = row.insertCell();
    email.className = 'mono-cell';
    email.textContent = subscriber.user_email || '—';

    const name = row.insertCell();
    name.textContent = subscriber.name || '—';

    const plan = row.insertCell();
    const planBadge = document.createElement('span');
    const planName = ['free', 'pro', 'premium'].includes(subscriber.plan_name)
      ? subscriber.plan_name
      : 'free';
    planBadge.className = 'badge badge-' + planName;
    planBadge.textContent = planName.toUpperCase();
    plan.appendChild(planBadge);

    const status = row.insertCell();
    const statusBadge = document.createElement('span');
    statusBadge.className = 'badge ' + (subscriber.status === 'active' ? 'badge-success' : 'badge-danger');
    statusBadge.textContent = subscriber.status || 'unknown';
    status.appendChild(statusBadge);

    const expiry = row.insertCell();
    expiry.className = 'muted-cell';
    expiry.textContent = subscriber.end_date
      ? new Date(subscriber.end_date).toLocaleDateString()
      : '—';

    const actions = row.insertCell();
    const manage = document.createElement('button');
    manage.type = 'button';
    manage.className = 'btn btn-mini';
    manage.textContent = 'Manage';
    manage.addEventListener('click', () => {
      openUserModal(subscriber.user_email || '', subscriber.name || '', planName);
    });
    actions.appendChild(manage);
  });
}

function renderPaymentsTable(tbody, payments) {
  tbody.replaceChildren();
  if (!payments || payments.length === 0) {
    const row = tbody.insertRow();
    const cell = row.insertCell();
    cell.colSpan = 6;
    cell.className = 'empty-state-cell';
    cell.textContent = 'No payments yet';
    return;
  }

  payments.forEach((payment) => {
    const row = tbody.insertRow();
    const email = row.insertCell();
    email.className = 'mono-cell';
    email.textContent = payment.user_email || '—';

    const plan = row.insertCell();
    const planBadge = document.createElement('span');
    const planName = ['free', 'pro', 'premium'].includes(payment.plan_name)
      ? payment.plan_name
      : 'free';
    planBadge.className = 'badge badge-' + planName;
    planBadge.textContent = planName.toUpperCase();
    plan.appendChild(planBadge);

    const amount = row.insertCell();
    amount.className = 'amount-cell';
    amount.textContent = '₹' + Number(payment.amount_inr || 0).toLocaleString('en-IN');

    const status = row.insertCell();
    const statusBadge = document.createElement('span');
    statusBadge.className = 'badge ' + (payment.status === 'success' ? 'badge-success' : 'badge-danger');
    statusBadge.textContent = payment.status || 'unknown';
    status.appendChild(statusBadge);

    const created = row.insertCell();
    created.className = 'muted-cell';
    created.textContent = payment.created_at
      ? new Date(payment.created_at).toLocaleString()
      : '—';

    const paymentId = row.insertCell();
    paymentId.className = 'payment-id-cell';
    paymentId.textContent = payment.razorpay_payment_id || '—';
  });
}
async function loadSubscriptions() {
  try {
    const data = await apiFetch('/api/admin-subscriptions.php');
    el('revTotal').textContent  = '₹' + data.revenue_total_inr.toLocaleString('en-IN');
    el('revMonth').textContent  = '₹' + data.revenue_this_month_inr.toLocaleString('en-IN');
    el('proCount').textContent  = data.plan_counts.pro || 0;
    el('premiumCount').textContent = data.plan_counts.premium || 0;

    // Update dashboard cards too
    if (el('revenueMonth')) el('revenueMonth').textContent = '₹' + data.revenue_this_month_inr.toLocaleString('en-IN');
    const paid = (data.plan_counts.pro || 0) + (data.plan_counts.premium || 0);
    if (el('paidUsersCount')) el('paidUsersCount').textContent = paid;

    // Subscriber table
    const tbody = el('subscribersBody');
    renderSubscribersTable(tbody, data.subscribers);
    // Payments table
    const pbody = el('paymentsBody');
    renderPaymentsTable(pbody, data.payments);
  } catch(e) { console.error('loadSubscriptions:', e); }
}

/* ── PLANS EDITOR ─────────────────────────────────────────── */
function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function normalizePlanName(value) {
  return ['free', 'pro', 'premium'].includes(value) ? value : 'free';
}
async function loadPlans() {
  const grid = el('plansGrid');
  try {
    const data = await apiFetch('/api/subscription-plans.php');
    const planIcons = { free: '🆓', pro: '🔥', premium: '👑' };
    grid.innerHTML = data.plans.map(p => `
      <div class="plan-editor glass-panel">
        <h3>${planIcons[p.name] || '📦'} ${escapeHtml(p.display_name)}</h3>
        <div class="plan-field">
          <label>Display Name</label>
          <input class="input-control" id="plan_display_${normalizePlanName(p.name)}" value="${escapeHtml(p.display_name)}">
        </div>
        <div class="plan-field">
          <label>Price (₹/month) — 0 for Free</label>
          <input type="number" class="input-control" id="plan_price_${normalizePlanName(p.name)}" value="${Math.round(p.price_paise/100)}">
        </div>
        <div class="plan-field">
          <label>Daily Question Limit (9999 = Unlimited)</label>
          <input type="number" class="input-control" id="plan_limit_${normalizePlanName(p.name)}" value="${p.daily_limit}">
        </div>
        <div class="plan-field">
          <label>Features</label>
          <div class="features-list" id="plan_feat_${normalizePlanName(p.name)}">
            ${(p.features||[]).map((f,i) => `<span class="feature-tag">${escapeHtml(f)}<button onclick="removeFeat('${normalizePlanName(p.name)}',${i})">×</button></span>`).join('')}
          </div>
          <div style="display:flex;gap:8px;margin-top:10px;">
            <input class="input-control" id="plan_newfeat_${normalizePlanName(p.name)}" placeholder="Add feature..." style="font-size:13px;">
            <button class="btn" onclick="addFeat('${normalizePlanName(p.name)}')">+</button>
          </div>
        </div>
        <div class="plan-field">
          <label>Razorpay Plan ID (optional)</label>
          <input class="input-control" id="plan_rpid_${normalizePlanName(p.name)}" value="${escapeHtml(p.razorpay_plan_id||'')}" placeholder="plan_xxxxx">
        </div>
        <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:8px;" onclick="savePlan('${normalizePlanName(p.name)}')">💾 Save ${escapeHtml(p.display_name)} Plan</button>
      </div>`).join('');
  } catch(e) { grid.innerHTML = '<div style="color:var(--accent);padding:20px;">Error loading plans</div>'; }
}

const _planFeatures = {};
function addFeat(planName) {
  const inp = el('plan_newfeat_' + planName);
  if (!inp.value.trim()) return;
  if (!_planFeatures[planName]) {
    // collect from DOM
    _planFeatures[planName] = [];
    document.querySelectorAll(`#plan_feat_${planName} .feature-tag`).forEach(el => {
      _planFeatures[planName].push(el.textContent.trim().slice(0,-1));
    });
  }
  _planFeatures[planName].push(inp.value.trim());
  inp.value = '';
  renderFeats(planName);
}
function removeFeat(planName, idx) {
  if (!_planFeatures[planName]) {
    _planFeatures[planName] = [];
    document.querySelectorAll(`#plan_feat_${planName} .feature-tag`).forEach(el2 => {
      _planFeatures[planName].push(el2.textContent.trim().slice(0,-1));
    });
  }
  _planFeatures[planName].splice(idx, 1);
  renderFeats(planName);
}
function renderFeats(planName) {
  const container = el('plan_feat_' + planName);
  container.innerHTML = _planFeatures[planName].map((f,i) =>
    `<span class="feature-tag">${escapeHtml(f)}<button onclick="removeFeat('${planName}',${i})">×</button></span>`).join('');
}

async function savePlan(planName) {
  const feats = _planFeatures[planName] || [];
  if (!feats.length) {
    // fallback: collect from DOM
    document.querySelectorAll(`#plan_feat_${planName} .feature-tag`).forEach(el2 => {
      feats.push(el2.textContent.trim().slice(0,-1));
    });
  }
  const payload = {
    name:             planName,
    display_name:     el('plan_display_' + planName).value,
    price_paise:      parseInt(el('plan_price_' + planName).value) * 100,
    daily_limit:      parseInt(el('plan_limit_' + planName).value),
    features:         feats,
    razorpay_plan_id: el('plan_rpid_' + planName).value,
    active: 1,
  };
  try {
    const res = await apiFetch('/api/subscription-plans.php', 'POST', payload);
    showToast(res.message || 'Plan saved!', 'success');
  } catch(e) { showToast('Error saving plan', 'error'); }
}

/* ── APP CONFIG ───────────────────────────────────────────── */
async function loadAppConfig() {
  try {
    const data = await apiFetch('/api/admin-config.php');
    const c = data.config;
    const setVal = (id, val) => { const el2 = el(id); if (!el2) return; if (el2.type === 'checkbox') el2.checked = val === '1'; else el2.value = val || ''; };
    setVal('cfg_free_daily_limit',    c.free_daily_limit);
    setVal('cfg_pro_daily_limit',     c.pro_daily_limit);
    setVal('cfg_premium_daily_limit', c.premium_daily_limit);
    setVal('cfg_voice_enabled',       c.voice_enabled);
    setVal('cfg_camera_enabled',      c.camera_enabled);
    setVal('cfg_quiz_enabled',        c.quiz_enabled);
    setVal('cfg_subscription_enabled',c.subscription_enabled);
    setVal('cfg_maintenance_mode',    c.maintenance_mode);
    setVal('cfg_maintenance_message', c.maintenance_message);
    setVal('cfg_app_version_required',c.app_version_required);
    setVal('cfg_app_update_url',      c.app_update_url);
    setVal('cfg_welcome_message',     c.welcome_message);
    setVal('cfg_free_trial_days',     c.free_trial_days);
  } catch(e) { console.error('loadAppConfig:', e); }
}

async function saveAppConfig() {
  const getVal = (id) => { const el2 = el(id); if (!el2) return ''; return el2.type === 'checkbox' ? (el2.checked ? '1' : '0') : el2.value; };
  const updates = {
    free_daily_limit:    getVal('cfg_free_daily_limit'),
    pro_daily_limit:     getVal('cfg_pro_daily_limit'),
    premium_daily_limit: getVal('cfg_premium_daily_limit'),
    voice_enabled:       getVal('cfg_voice_enabled'),
    camera_enabled:      getVal('cfg_camera_enabled'),
    quiz_enabled:        getVal('cfg_quiz_enabled'),
    subscription_enabled:getVal('cfg_subscription_enabled'),
    maintenance_mode:    getVal('cfg_maintenance_mode'),
    maintenance_message: getVal('cfg_maintenance_message'),
    app_version_required:getVal('cfg_app_version_required'),
    app_update_url:      getVal('cfg_app_update_url'),
    welcome_message:     getVal('cfg_welcome_message'),
    free_trial_days:     getVal('cfg_free_trial_days'),
  };
  try {
    const res = await apiFetch('/api/admin-config.php', 'POST', { updates });
    showToast('✅ Config saved! App will update on next launch.', 'success');
  } catch(e) { showToast('Error saving config', 'error'); }
}

/* ── PUSH NOTIFICATIONS ───────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('input[name="pushTarget"]').forEach(radio => {
    radio.addEventListener('change', () => {
      el('pushEmail').style.display = radio.value === 'email' ? 'block' : 'none';
    });
  });
});

async function sendPush() {
  const title  = el('pushTitle').value.trim() || 'NexA AI';
  const body   = el('pushBody').value.trim();
  const target = document.querySelector('input[name="pushTarget"]:checked')?.value || 'all';
  const email  = el('pushEmail')?.value?.trim() || '';
  if (!body) { showToast('Message is required', 'error'); return; }
  el('pushResult').textContent = 'Sending...';
  try {
    const res = await apiFetch('/api/push-broadcast.php', 'POST', { title, body, target, email });
    el('pushResult').textContent = `✅ ${res.message}`;
    showToast(res.message, 'success');
  } catch(e) {
    el('pushResult').textContent = '❌ Failed to send. Check Firebase config.';
    showToast('Push failed', 'error');
  }
}

/* ── ADMIN USER ACTIONS ───────────────────────────────────── */
let _currentModalEmail = '';
function openUserModal(email, name, plan) {
  _currentModalEmail = email;
  el('pmName').textContent  = name || email;
  el('pmEmail').textContent = email;
  el('pmType').textContent  = plan.toUpperCase();
  el('pmType').className    = 'modal-value badge badge-' + plan;
  el('pmAvatar').textContent = (name || email)[0].toUpperCase();
  showModal('userProfileModal');
}

async function adminAction(action, plan) {
  if (!_currentModalEmail) return;
  const payload = { action, email: _currentModalEmail, plan: plan || 'free', bonus: 10 };
  try {
    const res = await apiFetch('/api/admin-subscriptions.php', 'POST', payload);
    showToast(res.message, 'success');
    hideModal('userProfileModal');
    loadSubscriptions();
  } catch(e) { showToast('Action failed', 'error'); }
}

/* ── TELEGRAM TAB ─────────────────────────────────────────── */
async function loadTelegram() {
  if (typeof fetchTelegramUsers === 'function') fetchTelegramUsers();
}

/* ── HELPERS ──────────────────────────────────────────────── */
function el(id) { return document.getElementById(id); }

async function apiFetch(url, method = 'GET', body = null) {
  const opts = { method, credentials: 'include', headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(url, opts);
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}




