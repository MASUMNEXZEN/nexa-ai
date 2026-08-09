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
    content:       () => {},
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
function normalizePlanName(value) {
  return ['free', 'pro', 'premium'].includes(value) ? value : 'free';
}

function createPlanField(labelText, inputId, value, type = 'text') {
  const field = document.createElement('div');
  field.className = 'plan-field';
  const label = document.createElement('label');
  label.textContent = labelText;
  label.htmlFor = inputId;
  const input = document.createElement('input');
  input.className = 'input-control';
  input.id = inputId;
  input.type = type;
  input.value = value == null ? '' : String(value);
  field.append(label, input);
  return field;
}

function bindPlanEditorActions() {
  const grid = el('plansGrid');
  if (!grid || grid.dataset.actionsBound === 'true') return;
  grid.dataset.actionsBound = 'true';
  grid.addEventListener('click', (event) => {
    const button = event.target.closest('[data-plan-action]');
    if (!button || !grid.contains(button)) return;
    const planName = normalizePlanName(button.dataset.planName);
    const action = button.dataset.planAction;
    if (action === 'add-feature') addFeat(planName);
    else if (action === 'remove-feature') removeFeat(planName, Number(button.dataset.featureIndex));
    else if (action === 'save-plan') savePlan(planName);
  });
}

async function loadPlans() {
  const grid = el('plansGrid');
  if (!grid) return;
  try {
    const data = await apiFetch('/api/subscription-plans.php');
    if (!Array.isArray(data.plans)) throw new Error('Invalid plans response');
    grid.replaceChildren();
    data.plans.forEach((plan) => {
      const planName = normalizePlanName(plan.name);
      _planFeatures[planName] = Array.isArray(plan.features)
        ? plan.features.filter((feature) => typeof feature === 'string').map((feature) => feature.trim()).filter(Boolean)
        : [];

      const card = document.createElement('div');
      card.className = 'plan-editor glass-panel';
      card.dataset.planName = planName;

      const heading = document.createElement('h3');
      heading.textContent = `${String(plan.display_name || planName)} Plan`;
      card.appendChild(heading);
      card.appendChild(createPlanField('Display Name', `plan_display_${planName}`, plan.display_name));
      card.appendChild(createPlanField('Price (INR/month) - 0 for Free', `plan_price_${planName}`, Math.round(Number(plan.price_paise || 0) / 100), 'number'));
      card.appendChild(createPlanField('Daily Question Limit (9999 = Unlimited)', `plan_limit_${planName}`, Number(plan.daily_limit || 1), 'number'));

      const featureField = document.createElement('div');
      featureField.className = 'plan-field';
      const featureLabel = document.createElement('label');
      featureLabel.textContent = 'Features';
      featureField.appendChild(featureLabel);
      const featureList = document.createElement('div');
      featureList.className = 'features-list';
      featureList.id = `plan_feat_${planName}`;
      featureField.appendChild(featureList);

      const addRow = document.createElement('div');
      addRow.style.cssText = 'display:flex;gap:8px;margin-top:10px;';
      const newFeature = document.createElement('input');
      newFeature.className = 'input-control';
      newFeature.id = `plan_newfeat_${planName}`;
      newFeature.placeholder = 'Add feature...';
      newFeature.style.fontSize = '13px';
      const addButton = document.createElement('button');
      addButton.type = 'button';
      addButton.className = 'btn';
      addButton.textContent = '+';
      addButton.dataset.planAction = 'add-feature';
      addButton.dataset.planName = planName;
      addRow.append(newFeature, addButton);
      featureField.appendChild(addRow);
      card.appendChild(featureField);

      card.appendChild(createPlanField('Razorpay Plan ID (optional)', `plan_rpid_${planName}`, plan.razorpay_plan_id || ''));
      const saveButton = document.createElement('button');
      saveButton.type = 'button';
      saveButton.className = 'btn btn-primary';
      saveButton.style.cssText = 'width:100%;justify-content:center;margin-top:8px;';
      saveButton.textContent = `Save ${String(plan.display_name || planName)} Plan`;
      saveButton.dataset.planAction = 'save-plan';
      saveButton.dataset.planName = planName;
      card.appendChild(saveButton);
      grid.appendChild(card);
      renderFeats(planName);
    });
    bindPlanEditorActions();
  } catch (error) {
    console.error('loadPlans:', error);
    grid.replaceChildren();
    const message = document.createElement('div');
    message.className = 'plan-load-error';
    message.textContent = 'Error loading plans';
    grid.appendChild(message);
  }
}

const _planFeatures = {};
function addFeat(planName) {
  const input = el('plan_newfeat_' + planName);
  const value = input?.value.trim() || '';
  if (!value) return;
  if (value.length > 120) {
    showToast('Feature text is too long.', 'error');
    return;
  }
  _planFeatures[planName] = _planFeatures[planName] || [];
  if (_planFeatures[planName].length >= 20) {
    showToast('A plan can have at most 20 features.', 'error');
    return;
  }
  _planFeatures[planName].push(value);
  input.value = '';
  renderFeats(planName);
}

function removeFeat(planName, index) {
  if (!Number.isInteger(index) || index < 0 || index >= (_planFeatures[planName] || []).length) return;
  _planFeatures[planName].splice(index, 1);
  renderFeats(planName);
}

function renderFeats(planName) {
  const container = el('plan_feat_' + planName);
  if (!container) return;
  container.replaceChildren();
  (_planFeatures[planName] || []).forEach((feature, index) => {
    const tag = document.createElement('span');
    tag.className = 'feature-tag';
    const text = document.createTextNode(feature);
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.title = 'Remove feature';
    remove.textContent = 'x';
    remove.dataset.planAction = 'remove-feature';
    remove.dataset.planName = planName;
    remove.dataset.featureIndex = String(index);
    tag.append(text, remove);
    container.appendChild(tag);
  });
}

async function savePlan(planName) {
  const features = Array.isArray(_planFeatures[planName]) ? [..._planFeatures[planName]] : [];
  if (!features.length) {
    showToast('Add at least one plan feature.', 'error');
    return;
  }
  const displayName = el('plan_display_' + planName)?.value.trim() || '';
  const priceInr = Number(el('plan_price_' + planName)?.value);
  const dailyLimit = Number(el('plan_limit_' + planName)?.value);
  const razorpayPlanId = el('plan_rpid_' + planName)?.value.trim() || '';
  if (!displayName || !Number.isInteger(priceInr) || priceInr < 0 || !Number.isInteger(dailyLimit) || dailyLimit < 1) {
    showToast('Enter valid plan values.', 'error');
    return;
  }
  try {
    const res = await apiFetch('/api/subscription-plans.php', 'POST', {
      name: planName,
      display_name: displayName,
      price_paise: priceInr * 100,
      daily_limit: dailyLimit,
      features,
      razorpay_plan_id: razorpayPlanId,
      active: 1,
    });
    showToast(res.message || 'Plan saved!', 'success');
  } catch (error) {
    console.error('savePlan:', error);
    showToast('Error saving plan', 'error');
  }
}
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
    showToast('Config saved! App will update on next launch.', 'success');
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
    el('pushResult').textContent = res.message;
    showToast(res.message, 'success');
  } catch(e) {
    el('pushResult').textContent = 'Failed to send. Check Firebase config.';
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




