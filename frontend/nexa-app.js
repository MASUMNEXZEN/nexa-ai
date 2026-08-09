/* ════════════════════════════════════════════════════════════
   NexA AI — app.js v3
   Complete engine: auth, chat SSE, quiz, limits, profile, admin
   Null-safe: missing DOM elements NEVER crash the app
════════════════════════════════════════════════════════════ */

'use strict';

/* ── SAFE DOM HELPERS ──────────────────────────────────── */
const el  = (id)           => document.getElementById(id);
const qs  = (sel, ctx)     => (ctx || document).querySelector(sel);
const qsa = (sel, ctx)     => (ctx || document).querySelectorAll(sel);
const set = (id, val)      => { const e = el(id); if (e) e.textContent = val; };
const html= (id, val)      => { const e = el(id); if (e) e.innerHTML = val; };
const show= (id)           => { const e = el(id); if (e) e.classList.remove('hidden'); };
const hide= (id)           => { const e = el(id); if (e) e.classList.add('hidden'); };
const tog = (id, on)       => { const e = el(id); if (e) e.classList.toggle('hidden', !on); };
const attr= (id, a, v)     => { const e = el(id); if (e) e.setAttribute(a, v); };
const uiIcon = (name, label, className) => window.NexaIcons?.svg(name, label, className) || "";

/* Accessible surface state: one active drawer/dialog, predictable focus return. */
const surfaceState = { id: null, returnTo: null, close: null, bodyOverflow: '' };
const focusableSelector = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function getFocusable(container) {
  return Array.from(container?.querySelectorAll(focusableSelector) || [])
    .filter(node => !node.closest('.hidden') && node.getClientRects().length > 0);
}

function activateSurface(id, returnTo, close) {
  const surface = el(id);
  if (!surface) return;
  surfaceState.id = id;
  surfaceState.returnTo = returnTo instanceof HTMLElement ? returnTo : null;
  surfaceState.close = close;
  surfaceState.bodyOverflow = document.body.style.overflow;
  surface.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  requestAnimationFrame(() => {
    const first = getFocusable(surface)[0];
    (first || surface).focus();
  });
}

function releaseSurface(id, restoreFocus = true) {
  const surface = el(id);
  if (surface) {
    surface.setAttribute('aria-hidden', 'true');
    surface.classList.add('hidden');
  }
  if (surfaceState.id !== id) return;
  const returnTo = surfaceState.returnTo;
  const previousOverflow = surfaceState.bodyOverflow;
  surfaceState.id = null;
  surfaceState.returnTo = null;
  surfaceState.close = null;
  surfaceState.bodyOverflow = '';
  document.body.style.overflow = previousOverflow;
  if (restoreFocus && returnTo && document.contains(returnTo) && !returnTo.closest('.hidden')) {
    returnTo.focus();
  }
}

function handleSurfaceKeydown(event) {
  const surface = surfaceState.id ? el(surfaceState.id) : null;
  if (!surface || surface.classList.contains('hidden')) return;
  if (event.key === 'Escape') {
    event.preventDefault();
    surfaceState.close?.();
    return;
  }
  if (event.key !== 'Tab') return;
  const focusable = getFocusable(surface);
  if (!focusable.length) {
    event.preventDefault();
    surface.focus();
    return;
  }
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

document.addEventListener('keydown', handleSurfaceKeydown);

function answerTextForAction(button) {
  return button.closest('.bubble')?.querySelector('.ai-text')?.textContent || '';
}

function handleAppAction(event) {
  const button = event.target.closest('[data-action]');
  if (!button) return;
  const action = button.dataset.action;
  if (action === 'remove-attachment') removeAttach(Number(button.dataset.index));
  else if (action === 'select-exam') selectExam(button.dataset.exam || '');
  else if (action === 'select-other-exam') selectOtherExam();
  else if (action === 'back-to-welcome') backToWelcome();
  else if (action === 'copy-answer') copyText(button, answerTextForAction(button));
  else if (action === 'save-answer') saveBookmark(answerTextForAction(button), button);
  else if (action === 'quiz-answer') onQuizAnswer(button, Number(button.dataset.idx));
  else if (action === 'pick-quiz') pickQuizOption(button);
  else if (action === 'targeted-quiz') startTargetedQuiz(button.dataset.topic || '');
  else if (action === 'delete-bookmark') deleteBookmark(Number(button.dataset.id));
  else if (action === 'close-leaderboard') closeLeaderboard();
}

document.addEventListener('click', handleAppAction);
document.addEventListener('error', (event) => {
  const image = event.target;
  if (!(image instanceof HTMLImageElement) || image.dataset.fallbackApplied === 'true') return;
  image.dataset.fallbackApplied = 'true';
  image.src = '/icon-512.png';
}, true);
const state = {
  user:        null,   // { email, name, type, profile, referralCode, bonus_limit }
  todayCount:  0,
  dailyLimit:  60,
  streaming:   false,
  quizActive:  false,
  quizQueue:   [],
  quizCurrent: 0,
  quizScore:   0,
  quizTotal:   0,
  quizCount:   10,
  quizDiff:    'medium',
  attachedFiles: [],   // [{dataUrl, mimeType, name, type}]
  recognition: null,
  recording:   false,
  urlParams:   new URLSearchParams(window.location.search),
  selectedExam: null,
};

/* ── CDN LIB DETECTION ─────────────────────────────────── */
const MARKED  = () => typeof marked  !== 'undefined';
const PURIFY  = () => typeof DOMPurify !== 'undefined';
const KATEX   = () => typeof renderMathInElement !== 'undefined';
const HLJS    = () => typeof hljs !== 'undefined';

/* ═══════════════════════════════════════════════════════
   BOOT
═══════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', boot);

async function boot() {
  // App mode (embed widget guest)
  if (['true', 'embed'].includes(state.urlParams.get('app'))) {
    await guestBoot();
    return;
  }
  // Normal auth flow
  await authBoot();
}

async function guestBoot() {
  const isFresh = state.urlParams.get('fresh') === '1';
  if (isFresh) {
    chatHistory = [];
    try { localStorage.removeItem(HISTORY_KEY); localStorage.removeItem('nexa-exam'); } catch {}
  }  state.user = { email: 'guest', name: 'Guest', type: 'guest', profile: {} };
  state.dailyLimit = 10;
  applySavedTheme();
  hideSplash();
  showApp();
  bindAll();
  if (!isFresh) await loadHistory();
  renderWelcome(); // For guests, always show welcome (they rarely have history)
}

async function authBoot() {
  const isFresh = state.urlParams.get('fresh') === '1';
  applySavedTheme();
  try {
    const res  = await fetch('/api/auth-check.php', { credentials: 'include' });
    const data = await res.json();
    if (!data.logged_in) {
      redirectToLogin();
      return;
    }
    state.user       = {
      email:        data.email,
      name:         data.profile?.name || data.email,
      type:         data.type || 'email',
      profile:      data.profile || {},
      referralCode: data.referralCode || '',
      bonus_limit:  data.bonus_limit || 0,
    };
    state.todayCount = data.today_count || 0;
    window.NexaPlanner?.setCsrfToken(data.csrf_token || '');
    state.dailyLimit = (data.limit || 60) + (data.bonus_limit || 0);

    hideSplash();
    showApp();
    populateProfile();
    updateStatDisplay();
    fetchAnnouncement();
    bindAll();
    if (!isFresh) await loadHistory();
    renderWelcome(); // Show welcome ONLY if loadHistory left chatArea empty
    // ── Game-Changer Features ──
    startCountdown();
    updateStreak();
    renderBookmarks();
    // ── Mobile browser fixes ──
    initMobileKeyboardFix();
    initDoubleTapZoomFix();
  } catch(e) {
    console.error('[NexA] Boot error:', e);
    redirectToLogin();
  }
}

function redirectToLogin() {
  document.body.style.opacity = '0';
  document.body.style.transition = 'opacity 0.3s';
  setTimeout(() => { window.location.href = '/login'; }, 300);
}

function hideSplash() {
  const s = el('splashScreen');
  if (!s) return;
  s.classList.add('fade-out');
  setTimeout(() => s.classList.add('hidden'), 550);
}

function showApp() {
  const a = el('appShell');
  if (a) a.style.display = 'flex';
}

/* ═══════════════════════════════════════════════════════
   THEME
═══════════════════════════════════════════════════════ */
function applySavedTheme() {
  const saved = localStorage.getItem('nexa-theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  const drawerTog = el('themeToggleDrawer');
  if (drawerTog) drawerTog.checked = (saved === 'dark');
}
function toggleTheme() {
  const cur  = document.documentElement.getAttribute('data-theme') || 'dark';
  const next = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('nexa-theme', next);
  const drawerTog = el('themeToggleDrawer');
  if (drawerTog) drawerTog.checked = (next === 'dark');
}

/* ═══════════════════════════════════════════════════════
   PROFILE
═══════════════════════════════════════════════════════ */
function populateProfile() {
  if (!state.user) return;
  const initial = state.user.name ? state.user.name.charAt(0).toUpperCase() : (state.user.email[0] || '?').toUpperCase();

  set('avatarEl',       initial);
  set('drawerAvatarEl', initial);
  set('sidebarAvatarEl', initial);
  set('sidebarUserName', state.user.name || state.user.email);
  set('drawerName',     state.user.name || state.user.email);
  set('drawerEmail',    state.user.email);
  set('drawerTypeBadge', state.user.type === 'google' ? 'Google' : state.user.type === 'app' ? 'App' : 'Free');

  const refCode = state.user.referralCode || 'NX-????';
  set('refCodeDisplay', refCode);

  // Admin panel link
  if (state.user.type === 'admin') {
    const sec = el('adminSection');
    if (sec) sec.classList.remove('hidden');
  }

  // Update logged out btn
  const logoutEl = el('drawerLogoutBtn');
  if (logoutEl) logoutEl.style.display = 'flex';
}

function updateStatDisplay() {
  set('statToday',   state.todayCount);
  set('statLimit',   state.dailyLimit);
  set('usageUsed',   state.todayCount);
  set('usageMax',    state.dailyLimit);

  const pct = Math.min(100, (state.todayCount / state.dailyLimit) * 100);
  const fill = el('usageBarFill');
  if (fill) {
    fill.style.width = pct + '%';
    fill.classList.toggle('warn',   pct > 60 && pct <= 85);
    fill.classList.toggle('danger', pct > 85);
  }
  const pill = el('statPill');
  if (pill) {
    pill.classList.toggle('warning', pct > 60 && pct <= 85);
    pill.classList.toggle('danger',  pct > 85);
  }
}

/* ═══════════════════════════════════════════════════════
   ANNOUNCEMENT
═══════════════════════════════════════════════════════ */
async function fetchAnnouncement() {
  try {
    const d = await (await fetch('/api/admin-announcement.php')).json();
    if (d.active && d.text) {
      const center = el('headerAnnouncement');
      if (center) {
        center.innerHTML = `<div class="announcement-banner">${uiIcon("megaphone")}<span>${escHtml(d.text)}</span></div>`;
      }
    }
  } catch{}
}

/* ═══════════════════════════════════════════════════════
   EVENT BINDINGS
═══════════════════════════════════════════════════════ */
function bindAll() {
  /* Profile drawer */
  on('profileBtn',   'click', openDrawer);
  on('drawerOverlay','click', closeDrawer);
  on('drawerCloseBtn','click', closeDrawer);
  on('drawerLogoutBtn','click', doLogout);
  on('changeExamDrawerBtn', 'click', () => { closeDrawer(); renderExamPicker(); });

  /* Theme */
  on('themeToggle', 'click', toggleTheme);
  on('sidebarToggleBtn', 'click', toggleWorkspaceSidebar);
  on('sidebarScrim', 'click', closeWorkspaceSidebar);
  on('themeToggleDrawer', 'change', toggleTheme);

  /* Clear cache */
  on('clearCacheBtn', 'click', clearAppCache);

  /* Report */
  on('reportProblemBtn', 'click', openReport);
  on('reportCloseBtn',   'click', closeReport);
  on('reportOverlay',    'click', closeReport);
  on('submitReportBtn',  'click', submitReport);

  /* Quiz */
  on('quizBtn',       'click', openQuizSetup);
  on('quizCloseBtn',  'click', closeQuizSetup);
  on('quizOverlay',   'click', () => {
    if (qs('.leaderboard-modal')) closeLeaderboard();
    else closeQuizSetup();
  });
  on('startQuizBtn',  'click', startQuiz);
  
  /* New Chat */
  on('newChatBtn',    'click', startNewChat);
  on('sidebarNewChatBtn', 'click', startNewChat);
  on('sidebarChatBtn', 'click', focusChatWorkspace);
  on('sidebarQuizBtn', 'click', () => { closeWorkspaceSidebar(); openQuizSetup(); });
  on('sidebarSavedBtn', 'click', () => { closeWorkspaceSidebar(); openDrawer(); setTimeout(() => el('bookmarksSection')?.scrollIntoView({ block: 'start' }), 0); });
  on('sidebarProfileBtn', 'click', () => { closeWorkspaceSidebar(); openDrawer(); });

  /* Quiz Pill (dedicated big button) */
  on('quizPillBtn',   'click', openQuizSetup);

  /* Stop Generating */
  on('stopGeneratingBtn', 'click', (e) => {
    e.stopPropagation();
    if (_currentAbort) _currentAbort.abort();
  });

  /* Leaderboard */
  on('leaderboardBtn', 'click', openLeaderboard);

  /* Bookmarks */
  on('clearBookmarksBtn', 'click', clearAllBookmarks);

  // Quiz count/diff toggle buttons
  qsa('.quiz-count-btn').forEach(btn => btn.addEventListener('click', () => {
    qsa('.quiz-count-btn').forEach(b => {
      b.classList.remove('quiz-count-btn--active');
      b.setAttribute('aria-pressed', String(b === btn));
    });
    btn.classList.add('quiz-count-btn--active');
    state.quizCount = parseInt(btn.dataset.val, 10);
  }));
  qsa('.quiz-diff-btn').forEach(btn => btn.addEventListener('click', () => {
    qsa('.quiz-diff-btn').forEach(b => {
      b.classList.remove('quiz-diff-btn--active');
      b.setAttribute('aria-pressed', String(b === btn));
    });
    btn.classList.add('quiz-diff-btn--active');
    state.quizDiff = btn.dataset.val;
  }));

  /* Input — CRITICAL: wrap sendMessage in setTimeout(0) to decouple from touch event cycle */
  const sendBtnEl = el('sendBtn');
  if (sendBtnEl) {
    sendBtnEl.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      setTimeout(() => sendMessage(), 0);
    });
  }
  on('voiceBtn',     'click', toggleVoice);
  on('scrollFab',    'click', scrollToBottom);
  on('refCopyBtn',   'click', copyRefCode);

  const qInput = el('questionInput');
  if (qInput) {
    qInput.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    qInput.addEventListener('input', () => {
      autoResizeTextarea(qInput);
      updateCharCount(qInput.value.length);
    });
  }

  /* File attachment */
  on('imgInput', 'change', handleFileAttach);

  /* Welcome chips */
  qsa('.welcome-chip').forEach(chip => chip.addEventListener('click', () => {
    const qInput = el('questionInput');
    if (qInput) { qInput.value = chip.textContent; qInput.focus(); autoResizeTextarea(qInput); }
  }));

  /* Suggested prompt shortcuts */
  qsa('.workspace-history__item').forEach(item => item.addEventListener('click', () => {
    const qInput = el('questionInput');
    if (qInput) { qInput.value = item.textContent.trim(); qInput.focus(); autoResizeTextarea(qInput); }
  }));

  /* Scroll FAB */
  const chatArea = el('chatArea');
  if (chatArea) {
    chatArea.addEventListener('scroll', () => {
      const atBottom = chatArea.scrollHeight - chatArea.scrollTop - chatArea.clientHeight < 100;
      tog('scrollFab', !atBottom);
    });
  }
}

function on(id, evt, fn) {
  const e = el(id);
  if (e) e.addEventListener(evt, fn);
}

/* ═══════════════════════════════════════════════════════
   DRAWER
═══════════════════════════════════════════════════════ */
function openDrawer() {
  const trigger = document.activeElement;
  show('drawerOverlay');
  show('profileDrawer');
  attr('drawerOverlay', 'aria-hidden', 'false');
  activateSurface('profileDrawer', trigger, closeDrawer);
}
function closeDrawer({ restoreFocus = true } = {}) {
  const drawer = el('profileDrawer');
  drawer?.classList.remove('closing');
  hide('drawerOverlay');
  attr('drawerOverlay', 'aria-hidden', 'true');
  releaseSurface('profileDrawer', restoreFocus);
}

/* ═══════════════════════════════════════════════════════
   AUTH
═══════════════════════════════════════════════════════ */
async function doLogout() {
  try { await fetch('/api/auth-logout.php', { credentials: 'include' }); } catch{}
  document.body.style.opacity = '0';
  document.body.style.transition = 'opacity 0.3s';
  setTimeout(() => window.location.href = '/login', 300);
}

/* ═══════════════════════════════════════════════════════
   CACHE CLEAR
═══════════════════════════════════════════════════════ */
async function clearAppCache() {
  try {
    const keys = await caches.keys();
    await Promise.all(keys.map(k => caches.delete(k)));
    if (navigator.serviceWorker.controller) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map(r => r.unregister()));
    }
    showToast('Cache cleared! Reloading…', 'success');
    setTimeout(() => window.location.reload(true), 1200);
  } catch(e) {
    showToast('Could not clear cache: ' + e.message);
  }
}

/* ═══════════════════════════════════════════════════════
   REPORT A PROBLEM
═══════════════════════════════════════════════════════ */
function openReport() {
  const trigger = surfaceState.id === 'profileDrawer' ? surfaceState.returnTo : document.activeElement;
  closeDrawer({ restoreFocus: false });
  show('reportOverlay');
  show('reportCard');
  attr('reportOverlay', 'aria-hidden', 'false');
  activateSurface('reportCard', trigger, closeReport);
}
function closeReport() {
  hide('reportOverlay');
  attr('reportOverlay', 'aria-hidden', 'true');
  releaseSurface('reportCard');
}
async function submitReport() {
  const text = (el('reportText')?.value || '').trim();
  if (!text) { showToast('Please describe the issue.'); return; }
  const btn = el('submitReportBtn');
  if (btn) { btn.textContent = 'Sending…'; btn.disabled = true; }
  try {
    await fetch('/api/report-problem.php', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: state.user?.email || '', message: text })
    });
    showToast('Report sent! Thank you.', 'success');
    closeReport();
    if (el('reportText')) el('reportText').value = '';
  } catch {
    showToast('Could not send report. Try again.');
  } finally {
    if (btn) { btn.textContent = 'Submit Report'; btn.disabled = false; }
  }
}

/* ═══════════════════════════════════════════════════════
   REFERRAL COPY
═══════════════════════════════════════════════════════ */
function copyRefCode() {
  const code = el('refCodeDisplay')?.textContent?.trim() || '';
  if (!code || code === 'NX-????') return;
  navigator.clipboard.writeText(code).then(() => {
    const btn = el('refCopyBtn');
    if (btn) { btn.innerHTML = uiIcon('check') + '<span>Copied</span>'; setTimeout(() => { btn.innerHTML = uiIcon('copy') + '<span>Copy</span>'; }, 2000); }
  });
}

/* ═══════════════════════════════════════════════════════
   FILE ATTACHMENT
═══════════════════════════════════════════════════════ */
function handleFileAttach(e) {
  const files = Array.from(e.target.files || []);
  if (!files.length) return;
  files.forEach(file => {
    const reader = new FileReader();
    reader.onload = evt => {
      const dataUrl = evt.target.result;
      const mimeType = file.type;
      state.attachedFiles.push({ dataUrl, mimeType, name: file.name, type: mimeType.startsWith('image') ? 'image' : 'doc' });
      renderImagePreview();
    };
    reader.readAsDataURL(file);
  });
  e.target.value = '';
}

function renderImagePreview() {
  const strip = el('imagePreview');
  if (!strip) return;
  strip.classList.toggle('hidden', !state.attachedFiles.length);
  strip.innerHTML = state.attachedFiles.map((f, i) => `
    <div class="img-thumb">
      ${f.type === 'image'
        ? `<img src="${f.dataUrl}" alt="${escHtml(f.name)}">`
        : `<div style="width:60px;height:60px;border-radius:10px;background:var(--surface2);
              border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;
              font-size:10px;color:var(--text2);padding:4px;text-align:center;overflow:hidden;">${escHtml(f.name)}</div>`
      }
      <button class="img-thumb-remove" data-action="remove-attachment" data-index="${i}">×</button>
    </div>`
  ).join('');
}
window.removeAttach = (i) => {
  state.attachedFiles.splice(i, 1);
  renderImagePreview();
};

/* ═══════════════════════════════════════════════════════
   CHAT HISTORY
═══════════════════════════════════════════════════════ */
const HISTORY_KEY = 'nexa-chat-history';
let chatHistory = []; // [{role:'user'|'model', parts:[{text}]}]

async function loadHistory() {
  // Load from localStorage first (instant)
  try {
    const local = localStorage.getItem(HISTORY_KEY);
    if (local) {
      const parsed = JSON.parse(local);
      if (parsed?.length) {
        const welcome = el('chatArea')?.querySelector('.welcome-state');
        if (welcome) welcome.remove();
        chatHistory = parsed;
        chatHistory.forEach(m => {
          if (m.role === 'user') appendUserBubble(m.parts[0]?.text || '');
          else appendAIBubble(m.parts[0]?.text || '', false);
        });
        scrollToBottom();
        return;
      }
    }
  } catch{}
  // Try server sync
  try {
    const res = await fetch('/api/sync-history.php', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'load' })
    });
    const data = await res.json();
    if (data.history?.length) {
      const welcome = el('chatArea')?.querySelector('.welcome-state');
      if (welcome) welcome.remove();
      chatHistory = data.history;
      chatHistory.forEach(m => {
        if (m.role === 'user') appendUserBubble(m.parts[0]?.text || '');
        else appendAIBubble(m.parts[0]?.text || '', false);
      });
      scrollToBottom();
    }
  } catch{}
}

function saveHistoryLocal() {
  try {
    const slice = chatHistory.slice(-40); // last 40 turns
    localStorage.setItem(HISTORY_KEY, JSON.stringify(slice));
  } catch{}
}

async function saveHistoryServer() {
  try {
    await fetch('/api/sync-history.php', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save', history: chatHistory.slice(-40) })
    });
  } catch{}
}

/* ═══════════════════════════════════════════════════════
   NEW CHAT ENTRY
═══════════════════════════════════════════════════════ */
function startNewChat() {
  chatHistory = [];
  saveHistoryLocal();
  saveHistoryServer();
  const area = el('chatArea');
  if (area) area.innerHTML = '';
  // End any active quiz
  state.quizActive = false;
  // Restore welcome bubble
  renderWelcome();
  el('questionInput')?.focus();
}

function focusChatWorkspace() {
  closeWorkspaceSidebar();
  closeDrawer();
  closeQuizSetup();
  el('questionInput')?.focus();
}

function toggleWorkspaceSidebar() {
  const app = el('appShell');
  if (!app) return;
  const isMobile = window.matchMedia('(max-width: 820px)').matches;
  app.classList.toggle(isMobile ? 'sidebar-open' : 'sidebar-collapsed');
  updateSidebarToggleState();
}

function closeWorkspaceSidebar() {
  const app = el('appShell');
  if (!app) return;
  app.classList.remove('sidebar-open');
  updateSidebarToggleState();
}

function updateSidebarToggleState() {
  const app = el('appShell');
  const button = el('sidebarToggleBtn');
  if (!app || !button) return;
  const isMobile = window.matchMedia('(max-width: 820px)').matches;
  const isOpen = isMobile ? app.classList.contains('sidebar-open') : !app.classList.contains('sidebar-collapsed');
  button.setAttribute('aria-expanded', String(isOpen));
  button.setAttribute('aria-label', isOpen ? 'Hide study navigation' : 'Show study navigation');
  button.title = isOpen ? 'Hide study navigation' : 'Show study navigation';
}

/* ═══════════════════════════════════════════════════════
   WELCOME STATE
═══════════════════════════════════════════════════════ */
function renderWelcome() {
  const area = el('chatArea');
  if (!area || area.children.length > 0) return;

  const savedExamValue = localStorage.getItem('nexa-exam');
  const savedExam = savedExamValue && savedExamValue.toLowerCase() !== 'hello' ? savedExamValue : null;
  if (savedExamValue && !savedExam) { try { localStorage.removeItem('nexa-exam'); } catch {} }
  const firstName = escHtml(state.user?.name?.split(' ')[0] || 'Student');

  if (!savedExam) {
    area.innerHTML = `
      <div class="welcome-state general-welcome">
        <div class="welcome-logo"><img src="/logo.png?v=4" alt="NexA AI"></div>
        <div class="welcome-kicker">NEXA AI STUDY COMPANION</div>
        <div class="welcome-title">Welcome, <em>${firstName}</em></div>
        <div class="welcome-sub">Ask questions, practise concepts, and build a study plan in English or Bengali.</div>
        <div class="welcome-context">Start chatting now. You can choose a target exam whenever you are ready.</div>
        <div class="welcome-chips">
          <button class="welcome-chip" type="button">Explain this topic simply</button>
          <button class="welcome-chip" type="button">Create a 10-question quiz</button>
          <button class="welcome-chip" type="button">Build my study plan</button>
        </div>
        <button class="welcome-exam-link" id="chooseExamBtn" type="button">Choose a target exam <span aria-hidden="true">&rarr;</span></button>
      </div>`;
    bindWelcomePromptChips();
    el('chooseExamBtn')?.addEventListener('click', renderExamPicker);
    return;
  }

  state.selectedExam = savedExam;
  const chipsByExam = savedExam.includes('WBJEE')
    ? ['Calculus practice questions', 'Physics and optics concepts', 'Organic chemistry reactions']
    : savedExam.includes('WBP') || savedExam.includes('SSC') || savedExam.includes('WBCS')
      ? ['Indian history MCQs', 'Geography of West Bengal', 'Logical reasoning practice', 'Current affairs review']
      : savedExam.includes('ANM') || savedExam.includes('JENPAS') || savedExam.includes('NEET')
        ? ['Biology and human anatomy', 'MCQ practice set', `${savedExam} previous-year questions`, 'Create a focused study plan']
        : ['English grammar practice', 'Important chapters review', 'Give me a mock test'];

  area.innerHTML = `
    <div class="welcome-state">
      <div class="welcome-logo"><img src="/logo.png?v=4" alt="NexA AI"></div>
      <div class="welcome-kicker">NEXA AI STUDY COMPANION</div>
      <div class="welcome-title">Welcome back, <em>${firstName}</em></div>
      <div class="welcome-sub">Target exam: <strong>${escHtml(savedExam)}</strong></div>
      <div class="welcome-context">Ask a question, choose a study prompt, or start a mock quiz.</div>
      <div class="welcome-chips">${chipsByExam.map(label => `<button class="welcome-chip" type="button">${escHtml(label)}</button>`).join('')}</div>
      <button class="welcome-exam-link" id="changeExamBtn" type="button">Change target exam <span aria-hidden="true">&rarr;</span></button>
    </div>`;

  bindWelcomePromptChips();
  el('changeExamBtn')?.addEventListener('click', renderExamPicker);
}

function bindWelcomePromptChips() {
  qsa('.welcome-chip').forEach(chip => chip.addEventListener('click', () => {
    const qInput = el('questionInput');
    if (qInput) { qInput.value = chip.textContent.trim(); qInput.focus(); autoResizeTextarea(qInput); }
  }));
}

function renderExamPicker() {
  const area = el('chatArea');
  if (!area) return;
  area.innerHTML = `
    <div class="welcome-state exam-picker">
      <div class="welcome-logo"><img src="/logo.png?v=4" alt="NexA AI"></div>
      <div class="welcome-kicker">PERSONALIZED STUDY SPACE</div>
      <div class="welcome-title">Choose your <em>target</em></div>
      <div class="welcome-sub">Nexa will tailor prompts, quizzes, and explanations to your exam.</div>
      <div class="exam-grid">
        <button class="quiz-count-btn" data-action="select-exam" data-exam="ANM/GNM">ANM / GNM</button>
        <button class="quiz-count-btn" data-action="select-exam" data-exam="JENPAS UG">JENPAS UG</button>
        <button class="quiz-count-btn" data-action="select-exam" data-exam="WBJEE">WBJEE</button>
        <button class="quiz-count-btn" data-action="select-exam" data-exam="WBP / KP">WBP / KP Police</button>
        <button class="quiz-count-btn" data-action="select-exam" data-exam="WBCS">WBCS</button>
        <button class="quiz-count-btn" data-action="select-exam" data-exam="RRB / SSC">RRB / SSC</button>
        <button class="quiz-count-btn" data-action="select-exam" data-exam="Class 10 (Madhyamik)">Madhyamik</button>
        <button class="quiz-count-btn" data-action="select-exam" data-exam="Class 12 (HS)">Higher Secondary</button>
      </div>
      <div class="exam-other-box">
        <input type="text" id="otherExamInput" class="question-input exam-other-input" placeholder="Or enter another exam, e.g. NEET">
        <button class="send-btn exam-other-submit" type="button" data-action="select-other-exam" aria-label="Choose exam">
          <svg viewBox="0 0 24 24" fill="none" class="send-icon" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        </button>
      </div>
      <button class="welcome-exam-link" type="button" data-action="back-to-welcome">Back to chat</button>
    </div>`;
}

function backToWelcome() {
  const area = el('chatArea');
  if (area) area.innerHTML = '';
  renderWelcome();
}

function selectExam(exam) {
  const value = String(exam || '').trim();
  if (!value) return;
  localStorage.setItem('nexa-exam', value);
  state.selectedExam = value;
  const area = el('chatArea');
  if (area) area.innerHTML = '';
  renderWelcome();
}

function selectOtherExam() {
  const input = el('otherExamInput');
  const value = (input?.value || '').trim();
  if (!value) {
    showToast('Enter an exam name first.');
    input?.focus();
    return;
  }
  selectExam(value.substring(0, 80));
}

async function sendMessage() {
  if (state.streaming) return; // quizActive does NOT block — user needs to type answers

  // Rate limit check
  if (state.todayCount >= state.dailyLimit && state.user?.type !== 'admin') {
    showLimitBanner();
    showToast('Daily limit reached! Share your referral code to earn more.', 'warning');
    return;
  }

  const qInput = el('questionInput');
  const text = (qInput?.value || '').trim();
  const files = [...state.attachedFiles];

  if (!text && !files.length) return;

  // Clear input
  if (qInput) { qInput.value = ''; autoResizeTextarea(qInput); }
  state.attachedFiles = [];
  renderImagePreview();
  updateCharCount(0);

  // Clear welcome state
  const area = el('chatArea');
  const welcome = area?.querySelector('.welcome-state');
  if (welcome) welcome.remove();

  // Show user bubble
  appendUserBubble(text, files);

  // Push to history
  const userPart = { role: 'user', parts: [{ text: text || '[Image attached]' }] };
  chatHistory.push(userPart);

  // Disable send & show stop button (DISABLED for first 800ms to block ghost clicks)
  state.streaming = true;
  setSendState(true);
  const _stopBtnRef = el('stopGeneratingBtn');
  if (_stopBtnRef) {
    _stopBtnRef.disabled = true;  // Physically disabled = ignores ALL clicks
    _stopBtnRef.classList.remove('hidden');
    setTimeout(() => { if (state.streaming) _stopBtnRef.disabled = false; }, 800);
  }
  const pillBtn = el('quizBtn');
  if (pillBtn) pillBtn.style.opacity = '0.4';
  const mainPillBtn = el('quizPillBtn');
  if (mainPillBtn) mainPillBtn.style.opacity = '0.4';
  scrollToBottom();

  // Show typing
  const typingId = addTypingIndicator();

  try {
    // Cancel any stale abort controller from previous request
    if (_currentAbort) { _currentAbort = null; }
    await streamAIResponse(text, files, typingId);
  } catch(e) {
    removeTypingIndicator(typingId);
    if (e.name === 'AbortError') {
      appendAIBubble('_Generation stopped by you._', true);
    } else {
      appendAIBubble('Sorry, NexA had trouble connecting. Please check your internet and try again.', true);
    }
    console.error('[NexA] Stream error:', e);
  } finally {
    state.streaming = false;
    _currentAbort = null;  // Always clean up
    setSendState(false);
    const stopBtn = el('stopGeneratingBtn');
    if (stopBtn) stopBtn.classList.add('hidden');
    const pillBtn = el('quizBtn');
    if (pillBtn) pillBtn.style.opacity = '';
    const mainPillBtn = el('quizPillBtn');
    if (mainPillBtn) mainPillBtn.style.opacity = '';
  }
}

/* State for aborting streams */
let _currentAbort = null;

async function streamAIResponse(text, files, typingId) {
  // System instruction (matches app.bak.js format)
  const quizInstruction = state.quizActive ? `[SYSTEM_COMMAND: QUIZ_MODE]
You are now a dedicated quiz master. Ask EXACTLY ONE MCQ at a time. Label options A, B, C, D. After the student answers, reveal the correct answer with a short explanation, then ask the NEXT question. Never repeat questions.` : '';

  const langPolicy = "Reply in intact Bengali. Use exam-oriented concise bullets. Use English ONLY for English grammar/language questions or if explicitly requested.";

  const ex = state.selectedExam || '';

  // ── GREETING DETECTION ──────────────────────────────────────
  // When a greeting is detected and an exam is selected, NexA should acknowledge it.
  const greetingRegex = /^(হ্যালো|হেলো|hello|hi|hey|নমস্কার|নমস্কর|আদাব|সালাম|good\s?(morning|afternoon|evening|night)|শুভ|কেমন আছ|কি খবর|ভালো আছ|ভালো আছেন|কেমন আছেন|start|শুরু|begin|what can you|কী করতে পার|আপনি কে|who are you|tumi ke|tumi ki|apni ki|apni ke|আমাকে সাহায্য|help me|সাহায্য কর)/i;
  const isGreeting = greetingRegex.test((text || '').trim()) && files.length === 0;

  let greetingEnhancer = '';
  if (isGreeting && ex) {
    greetingEnhancer = ` IMPORTANT: The user has just greeted you. Warmly introduce yourself in Bengali as NexA — the AI tutor specialised for ${ex} preparation from NexZen Institute. Mention that you are here to help them crack ${ex}. Ask them what topic or subject they want to study today. Be encouraging and friendly.`;
  } else if (isGreeting && !ex) {
    greetingEnhancer = ` IMPORTANT: The user has just greeted you. Warmly introduce yourself in Bengali as NexA — the AI tutor of NexZen Institute. Tell them you can help with WBJEE, JENPAS UG, ANM/GNM, WBP, SSC, WBCS, and Board Exams. Ask which exam they are preparing for.`;
  }

  let systemText = `You are NexA, AI tutor. ${langPolicy} Sign off: "— NexA"${greetingEnhancer}`;

  if (ex.includes('ANM') || ex.includes('GNM') || ex.includes('JENPAS') || ex.includes('NEET')) {
    systemText = `You are NexA, Medical/Nursing tutor exclusively for ${ex} syllabus. Focus strictly on ${ex} topics: Anatomy, Physiology, Microbiology, Nutrition, Community Health, Pharmacology, and related nursing sciences. ${langPolicy} Sign off: "— NexA"${greetingEnhancer}${quizInstruction ? '\n' + quizInstruction : ''}`;
  } else if (ex.includes('WBJEE')) {
    systemText = `You are NexA, Engineering tutor for WBJEE syllabus. Focus strictly on WBJEE topics: Mathematics (Algebra, Calculus, Coordinate Geometry), Physics (Mechanics, Optics, Electrodynamics), Chemistry (Physical, Organic, Inorganic). ${langPolicy} Sign off: "— NexA"${greetingEnhancer}${quizInstruction ? '\n' + quizInstruction : ''}`;
  } else if (ex.includes('WBP') || ex.includes('KP')) {
    systemText = `You are NexA, Police Exam tutor for WBP/KP syllabus. Focus on GK, Indian Polity, History, Geography, Arithmetic, and Reasoning. ${langPolicy} Sign off: "— NexA"${greetingEnhancer}${quizInstruction ? '\n' + quizInstruction : ''}`;
  } else if (ex.includes('WBCS') || ex.includes('RRB') || ex.includes('SSC')) {
    systemText = `You are NexA, Govt Exam tutor for ${ex} syllabus. Focus on topics relevant to ${ex}: General Studies, Reasoning, English, Quantitative Aptitude, Current Affairs. ${langPolicy} Sign off: "— NexA"${greetingEnhancer}${quizInstruction ? '\n' + quizInstruction : ''}`;
  } else if (ex.includes('Madhyamik') || ex.includes('HS') || ex.includes('Class')) {
    systemText = `You are NexA, WB Board tutor for WBBSE/WBCHSE syllabus. Help with Madhyamik/HS subjects strictly as per West Bengal board curriculum. ${langPolicy} Sign off: "— NexA"${greetingEnhancer}${quizInstruction ? '\n' + quizInstruction : ''}`;
  } else if (ex) {
    systemText = `You are NexA, tutor for ${ex}. Focus on topics relevant to ${ex} syllabus. ${langPolicy} Sign off: "— NexA"${greetingEnhancer}${quizInstruction ? '\n' + quizInstruction : ''}`;
  } else {
    if (quizInstruction) systemText += '\n' + quizInstruction;
    if (greetingEnhancer) systemText += greetingEnhancer;
  }

  // Build contents array (keep only last 2 turns = 50% fewer input tokens vs old 4)
  const ctx = [];
  chatHistory.slice(-3, -1).forEach(m => {
    if (m.role === 'user') ctx.push({ role: 'user', parts: [{ text: m.parts[0]?.text || ' ' }] });
    else ctx.push({ role: 'model', parts: [{ text: m.parts[0]?.text || ' ' }] });
  });

  const userParts = [];
  if (files.length) {
    files.forEach(f => userParts.push({ inline_data: { mime_type: f.mimeType, data: f.dataUrl.split(',')[1] } }));
  }
  userParts.push({ text: text || ' ' });
  ctx.push({ role: 'user', parts: userParts });

  const requestBody = {
    system_instruction: { parts: [{ text: systemText }] },
    contents: ctx,
  };
  if (['true', 'embed'].includes(state.urlParams.get('app'))) requestBody.app_mode = 'true';

  // Use a stable device id for X-Device-Id header (needed by ask.php auth fallback)
  const deviceId = localStorage.getItem('nexa-device-id') || (() => {
    const id = 'web-' + Math.random().toString(36).slice(2, 10);
    localStorage.setItem('nexa-device-id', id); return id;
  })();

  const apiEndpoint = ['true', 'embed'].includes(state.urlParams.get('app')) ? '/api/app-ask.php' : '/api/ask.php';

  // AbortController so stop button can cancel
  _currentAbort = new AbortController();
  const signal = _currentAbort.signal;

  const res = await fetch(apiEndpoint, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-Device-Id': deviceId },
    body: JSON.stringify(requestBody),
    cache: 'no-store',
    signal,
  });

  if (!res.ok) {
    const errData = await res.json().catch(() => ({}));
    throw new Error(errData.error || `HTTP ${res.status}`);
  }

  // Remove typing indicator & create streaming bubble
  removeTypingIndicator(typingId);
  const { bubble, textEl } = createStreamBubble();

  const reader   = res.body.getReader();
  const decoder  = new TextDecoder();
  let   buffer   = '';
  let   fullText = '';
  let   renderTimer = null;

  const flushRender = () => {
    if (textEl) textEl.innerHTML = renderMarkdown(fullText + ' ▌');
    applyCodeCopyButtons(textEl);
    applyMath(textEl);
    scrollToBottom();
  };

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });

    // Split on SSE double newlines (handles \r\n\r\n and \n\n)
    const parts = buffer.split(/\r?\n\r?\n/);
    buffer = parts.pop() ?? '';

    for (const part of parts) {
      const line = part.replace(/^data:\s*/m, '').trim();
      if (!line || line === '[DONE]') continue;
      try {
        const json = JSON.parse(line);
        const chunk = json?.candidates?.[0]?.content?.parts?.[0]?.text ?? '';
        if (chunk) {
          fullText += chunk;
          clearTimeout(renderTimer);
          renderTimer = setTimeout(flushRender, 30);
        }
        // Rate limit from server response
        if (json?.usage?.today_count !== undefined) {
          state.todayCount = json.usage.today_count;
          updateStatDisplay();
        }
      } catch{}
    }
  }

  clearTimeout(renderTimer);

  // Final render (no cursor)
  if (textEl) {
    textEl.innerHTML = renderMarkdown(fullText || '');
    applyCodeCopyButtons(textEl);
    applyMath(textEl);
  }
  if (bubble) addReactionBar(bubble, fullText);

  // If quiz is active, try to render MCQ options as clickable cards
  if (state.quizActive && bubble) {
    renderQuizCards(bubble, fullText);
  }

  // Update count (assume +1 if server doesn't tell us)
  state.todayCount = Math.min(state.todayCount + 1, state.dailyLimit);
  updateStatDisplay();

  // Save to history
  chatHistory.push({ role: 'model', parts: [{ text: fullText }] });
  saveHistoryLocal();
  saveHistoryServer();
  scrollToBottom();

  // Clean up abort controller so next message starts fresh
  _currentAbort = null;
}

/* ── STREAMING BUBBLE ─────────────────────────────────── */
function createStreamBubble() {
  const area = el('chatArea');
  if (!area) return { bubble: null, textEl: null };
  const row = document.createElement('div');
  row.className = 'msg ai';
  row.innerHTML = `
    <div class="msg-avatar"><img src="/logo-icon.png?v=4" alt="NexA"></div>
    <div class="bubble"><div class="ai-text" lang="bn"></div></div>`;
  area.appendChild(row);
  return { bubble: row.querySelector('.bubble'), textEl: row.querySelector('.ai-text') };
}

function appendUserBubble(text, files) {
  const area = el('chatArea');
  if (!area) return;
  const initial = state.user?.name ? state.user.name.charAt(0).toUpperCase() : 'U';
  const lang = contentLanguage(text);
  const row = document.createElement('div');
  row.className = 'msg user';
  let imgs = '';
  if (files?.length) {
    imgs = files.filter(f => f.type === 'image').map(f => `<img class="msg-image" src="${f.dataUrl}" alt="attached">`).join('');
  }
  row.innerHTML = `
    <div class="msg-avatar">${escHtml(initial)}</div>
    <div class="bubble" lang="${lang}">${imgs}${text ? `<span>${escHtml(text)}</span>` : ''}</div>`;
  area.appendChild(row);
  scrollToBottom();
}

function appendAIBubble(text, withReaction = true) {
  const area = el('chatArea');
  if (!area) return;
  const now = new Date();
  const timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  const lang = contentLanguage(text);
  const row = document.createElement('div');
  row.className = 'msg ai';
  row.innerHTML = `
    <div class="msg-avatar"><img src="/logo-icon.png?v=4" alt="NexA"></div>
    <div class="bubble" lang="${lang}"><div class="ai-text" lang="${lang}">${renderMarkdown(text)}</div><span class="chat-timestamp">${timeStr}</span></div>`;
  area.appendChild(row);
  const bubble = row.querySelector('.bubble');
  const textEl = row.querySelector('.ai-text');
  if (textEl) { applyCodeCopyButtons(textEl); applyMath(textEl); }
  if (withReaction && bubble) addReactionBar(bubble, text);
  scrollToBottom();
}

/* ── TYPING INDICATOR ──────────────────────────────────── */
let _typingCounter = 0;
function addTypingIndicator() {
  const id = 'typing-' + (++_typingCounter);
  const area = el('chatArea');
  if (!area) return id;
  const row = document.createElement('div');
  row.id = id;
  row.className = 'msg ai typing-indicator';
  row.innerHTML = `
    <div class="msg-avatar"><img src="/logo-icon.png?v=4" alt="NexA"></div>
    <div class="bubble"><div class="typing-dots"><span></span><span></span><span></span></div></div>`;
  area.appendChild(row);
  scrollToBottom();
  return id;
}
function removeTypingIndicator(id) {
  const e = el(id);
  if (e) e.remove();
}

/* ── REACTION BAR ──────────────────────────────────────── */
function addReactionBar(bubble, text) {
  if (!bubble) return;
  const bar = document.createElement('div');
  bar.className = 'reaction-bar';
  bar.innerHTML = `
    <button title="Copy" data-action="copy-answer">${uiIcon("copy")}<span>Copy</span></button>`;
  bubble.appendChild(bar);
}

window.copyText = async (btn, text) => {
  try {
    await navigator.clipboard.writeText(text);
    btn.innerHTML = uiIcon("check") + '<span>Copied</span>';
    setTimeout(() => { btn.innerHTML = uiIcon("copy") + '<span>Copy</span>'; }, 2000);
  } catch {}
};

let _tts = null;
window.speakText = (btn, text) => {
  if (_tts && !_tts.paused) {
    speechSynthesis.cancel();
    btn.innerHTML = uiIcon("volume") + '<span>Listen</span>';
    _tts = null;
    return;
  }
  _tts = new SpeechSynthesisUtterance(text.replace(/[*_#>]/g, '').substring(0, 3000));
  _tts.lang = 'en-IN';
  _tts.onend = () => { btn.innerHTML = uiIcon("volume") + '<span>Listen</span>'; };
  speechSynthesis.speak(_tts);
  btn.innerHTML = uiIcon("square") + '<span>Stop</span>';
};

/* ── SET SEND STATE ────────────────────────────────────── */
function setSendState(streaming) {
  const btn  = el('sendBtn');
  const qIn  = el('questionInput');
  if (btn) {
    btn.classList.toggle('streaming', streaming);
    btn.disabled = false; // always keep clickable
    if (streaming) {
      // Stop icon — clicking cancels the stream
      btn.onclick = () => {
        if (_currentAbort) { _currentAbort.abort(); _currentAbort = null; }
        state.streaming = false;
        setSendState(false);
      };
      btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="white"><rect x="5" y="5" width="14" height="14" rx="3"/></svg>`;
      btn.title = 'Stop';
    } else {
      btn.onclick = sendMessage;
      btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>`;
      btn.title = 'Send';
    }
  }
  if (qIn) qIn.disabled = false; // never lock the input field
}

/* ── LIMIT BANNER ──────────────────────────────────────── */
function showLimitBanner() {
  const existing = qs('.limit-banner');
  if (existing) return;
  const island = el('inputIsland');
  if (!island) return;
  const banner = document.createElement('div');
  banner.className = 'limit-banner';
  banner.innerHTML = `${uiIcon("zap")} Daily limit reached! Share <strong>${escHtml(state.user?.referralCode || '')}</strong> to earn +10 bonus questions. <a href="/login" style="color:var(--accent2)">Upgrade</a>`;
  island.before(banner);
}

/* ═══════════════════════════════════════════════════════
   QUIZ MODE
═══════════════════════════════════════════════════════ */
function openQuizSetup() {
  const trigger = document.activeElement;
  show('quizOverlay');
  show('quizSetupCard');
  attr('quizOverlay', 'aria-hidden', 'false');
  activateSurface('quizSetupCard', trigger, closeQuizSetup);
  const ti = el('quizTopicInput');
  if (ti) {
    const ex = state.selectedExam || '';
    if (ex.includes('WBJEE')) ti.placeholder = 'e.g. Calculus, Optics, Organic Chem...';
    else if (ex.includes('ANM') || ex.includes('JENPAS')) ti.placeholder = 'e.g. Cell Biology, Anatomy, Logic...';
    else if (ex.includes('WBP') || ex.includes('SSC') || ex.includes('WBCS')) ti.placeholder = 'e.g. Indian History, Geography, Polity...';
    else ti.placeholder = 'e.g. Cell Biology, Algebra, English Grammar...';
  }
}
function closeQuizSetup() {
  hide('quizOverlay');
  attr('quizOverlay', 'aria-hidden', 'true');
  releaseSurface('quizSetupCard');
}

async function startQuiz() {
  const topic = (el('quizTopicInput')?.value || '').trim();
  if (!topic) { showToast('Please enter a topic!'); return; }

  const startBtn = el('startQuizBtn');
  if (startBtn) { startBtn.textContent = 'Generating…'; startBtn.disabled = true; }

  closeQuizSetup();

  const area = el('chatArea');
  const welcome = area?.querySelector('.welcome-state');
  if (welcome) welcome.remove();

  // Reset quiz state
  state.quizActive   = true;
  state.quizTopic    = topic;
  state.quizScore    = 0;
  state.quizCurrent  = 0;
  state.quizTotal    = state.quizCount;
  
  // ADAPTIVE STATE
  state.quizHistory  = []; // Array of { question, correct, sub_topic }
  state.quizConsecutiveCorrect = 0;
  state.quizConsecutiveWrong   = 0;

  // Quiz header bubble
  let diffLabel = { easy: 'Easy', medium: 'Moderate', hard: 'Hard' }[state.quizDiff];
  if (!diffLabel) {
      const ex = state.selectedExam || '';
      if (ex.includes('10') || ex.includes('Madhyamik') || ex.includes('9')) diffLabel = 'Easy';
      else if (ex.includes('WBJEE') || ex.includes('JENPAS') || ex.includes('UPSC') || ex.includes('WBCS') || ex.includes('NEET')) diffLabel = 'Advanced/Hard';
      else diffLabel = 'Moderate';
  }
  const headerRow = document.createElement('div');
  headerRow.className = 'msg ai';
  headerRow.innerHTML = `
    <div class="msg-avatar"><img src="/logo-icon.png?v=4" alt="NexA"></div>
    <div class="bubble">
      <div style="font-size:15px;font-weight:700;color:white;margin-bottom:4px">${uiIcon("brain")} ${escHtml(topic)} Quiz</div>
      <div style="font-size:13px;color:var(--text2)">${state.quizCount} questions · ${diffLabel} · Tap an option to answer</div>
    </div>`;
  area.appendChild(headerRow);
  scrollToBottom();

  if (startBtn) { startBtn.textContent = 'Generate Quiz'; startBtn.disabled = false; }

  await loadNextQuizQuestion();
}

async function loadNextQuizQuestion() {
  if (state.quizCurrent >= state.quizTotal) {
    renderQuizScore();
    return;
  }

  const typingId = addTypingIndicator();
  scrollToBottom();

  try {
    let diffLabel = { easy: 'Easy', medium: 'Moderate', hard: 'Hard' }[state.quizDiff];
    if (!diffLabel) {
        const ex = state.selectedExam || '';
        if (ex.includes('10') || ex.includes('Madhyamik') || ex.includes('9')) diffLabel = 'Easy';
        else if (ex.includes('WBJEE') || ex.includes('JENPAS') || ex.includes('UPSC') || ex.includes('WBCS') || ex.includes('NEET')) diffLabel = 'Advanced/Hard';
        else diffLabel = 'Moderate';
    }
    const res = await fetch('/api/quiz-api.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({
        action:     'get_quiz',
        topic:      state.quizTopic,
        difficulty: diffLabel,
        lang:       'Bengali',
        exam_type:  state.selectedExam || '',
      }),
    });
    const data = await res.json();
    removeTypingIndicator(typingId);

    if (data.error) throw new Error(data.error);
    if (!data.quiz) throw new Error('No question returned.');

    const q = data.quiz;
    const opts = [q.opt_a, q.opt_b, q.opt_c, q.opt_d];
    const ca = (q.correct_answer || '').toUpperCase().trim();
    // correct_answer can be "A"/"B"/"C"/"D" or the full text like "SYPHILIS"
    let answerIdx = ['A','B','C','D'].indexOf(ca);
    if (answerIdx === -1) {
      answerIdx = opts.findIndex(o => o.toUpperCase().trim() === ca);
    }

    renderQuizCard({
      question:    q.question,
      options:     opts,
      answer:      answerIdx,
      explanation: q.explanation || '',
      sub_topic:   q.sub_topic || ''
    }, state.quizCurrent);

  } catch(e) {
    removeTypingIndicator(typingId);
    appendAIBubble(`Error: ${e.message}`);
    state.quizActive = false;
  }
}

function renderQuizCard(q, idx) {
  const area = el('chatArea');
  if (!area) return;

  const opts = q.options.map((opt, i) => {
    const letter = String.fromCharCode(65 + i);
    return `<button class="quiz-option" data-idx="${i}" data-action="quiz-answer">
      <span class="quiz-option-letter">${letter}.</span>
      <span>${escHtml(opt)}</span>
    </button>`;
  }).join('');

  const row = document.createElement('div');
  row.className = 'msg ai';
  row.innerHTML = `
    <div class="msg-avatar"><img src="/logo-icon.png?v=4" alt="NexA"></div>
    <div class="bubble">
      <div class="quiz-q-header">Question ${idx + 1} of ${state.quizTotal} · ${String({easy:'Easy',medium:'Medium',hard:'Hard'}[state.quizDiff]||'Medium')}</div>
      <div class="quiz-question" lang="${contentLanguage(q.question)}">${escHtml(q.question)}</div>
      <div class="quiz-options-wrap">${opts}</div>
      <div class="quiz-explanation-box" style="display:none">
        <div class="expl-text"></div>
      </div>
    </div>`;

  // Store correct answer on the row for the click handler
  row.dataset.correct  = q.answer;
  row.dataset.expl     = q.explanation;
  row.dataset.subTopic = q.sub_topic;
  area.appendChild(row);
  scrollToBottom();
}

window.onQuizAnswer = (btn, ansIdx) => {
  const row   = btn.closest('.msg');
  const wrap  = btn.closest('.quiz-options-wrap');
  const expl  = row?.querySelector('.quiz-explanation-box');
  const correct = parseInt(row?.dataset.correct ?? '-1', 10);
  const subTopic = row?.dataset.subTopic || '';

  // Disable all options
  qsa('.quiz-option', wrap).forEach(b => { b.disabled = true; b.classList.add('answered'); });
  btn.classList.add(ansIdx === correct ? 'is-correct' : 'is-wrong');
  if (correct >= 0) wrap.querySelectorAll('.quiz-option')[correct]?.classList.add('is-correct');

  const isCorrect = (ansIdx === correct);
  
  // Adaptive Difficulty Logic
  if (isCorrect) {
    state.quizScore++;
    state.quizConsecutiveCorrect++;
    state.quizConsecutiveWrong = 0;
    
    // Scale UP difficulty if doing perfectly well
    if (state.quizConsecutiveCorrect >= 2) {
      if (state.quizDiff === 'easy') { state.quizDiff = 'medium'; state.quizConsecutiveCorrect = 0; }
      else if (state.quizDiff === 'medium') { state.quizDiff = 'hard'; state.quizConsecutiveCorrect = 0; }
    }
  } else {
    state.quizConsecutiveWrong++;
    state.quizConsecutiveCorrect = 0;
    
    // Scale DOWN difficulty if struggling
    if (state.quizConsecutiveWrong >= 2) {
      if (state.quizDiff === 'hard') { state.quizDiff = 'medium'; state.quizConsecutiveWrong = 0; }
      else if (state.quizDiff === 'medium') { state.quizDiff = 'easy'; state.quizConsecutiveWrong = 0; }
    }
  }
  
  // Record History for Weak Topic Analysis
  state.quizHistory.push({
    correct: isCorrect,
    sub_topic: subTopic
  });

  state.quizCurrent++;

  // Show explanation
  if (expl) {
    expl.style.display = 'block';
    const et = expl.querySelector('.expl-text');
    const correctLetter = String.fromCharCode(65 + correct);
    if (et) et.textContent = row?.dataset.expl || (isCorrect ? 'Correct!' : 'Correct answer: ' + correctLetter);

    const nextBtn = document.createElement('button');
    nextBtn.className = 'quiz-next-btn';
    nextBtn.innerHTML = state.quizCurrent < state.quizTotal ? '<span>Next</span>' + uiIcon('arrowRight') : '<span>See Results</span>' + uiIcon('trophy');
    nextBtn.onclick = () => { nextBtn.remove(); loadNextQuizQuestion(); };
    expl.after(nextBtn);
  }
  scrollToBottom();
};


function renderQuizScore() {
  state.quizActive = false;
  const area = el('chatArea');
  if (!area) return;
  const pct = Math.round((state.quizScore / state.quizTotal) * 100);
  const resultIcon = pct >= 80 ? 'trophy' : pct >= 50 ? 'target' : 'bookOpen';
  const msg   = pct >= 80 ? 'Excellent work!' : pct >= 50 ? 'Good effort!' : 'Keep practising!';
  
  // ADAPTIVE: Weak Topic Analysis
  const weakTopics = new Set();
  if (state.quizHistory) {
      state.quizHistory.forEach(item => {
          if (!item.correct && item.sub_topic && item.sub_topic.length > 2) {
              // Normalize topic strings (e.g. capitalize)
              const t = item.sub_topic.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ');
              weakTopics.add(t);
          }
      });
  }

  let targetedHtml = '';
  if (weakTopics.size > 0 && pct < 100) {
      const topicBtns = Array.from(weakTopics).slice(0, 3).map(t => 
          `<button class="weak-topic-btn" data-action="targeted-quiz" data-topic="${escHtml(t)}">
              ${uiIcon("target")}<span>Practice ${escHtml(t)}</span>
           </button>`
      ).join('');
      
      targetedHtml = `
        <div class="weak-topics-section">
          <div class="wt-title">Weak Topics Detected:</div>
          <div class="wt-desc">Take a focused mini-quiz on the concepts you missed.</div>
          <div class="wt-actions">${topicBtns}</div>
        </div>
      `;
  }

  const row = document.createElement('div');
  row.className = 'msg ai';
  row.innerHTML = `
    <div class="msg-avatar"><img src="/logo-icon.png?v=4" alt="NexA"></div>
    <div class="bubble">
      <div class="quiz-score-card">
        <div class="quiz-score-badge">${uiIcon(resultIcon)}<span>${pct}%</span></div>
        <div class="quiz-score-big">${state.quizScore}/${state.quizTotal}</div>
        <div class="quiz-score-label">${pct}% — ${msg}</div>
        ${targetedHtml}
      </div>
    </div>`;
  area.appendChild(row);
  scrollToBottom();
}

window.startTargetedQuiz = (subTopic) => {
  const ti = el('quizTopicInput');
  if (ti) {
    ti.value = subTopic;
    state.quizDiff = 'medium'; // reset difficulty for new quiz
    state.quizCount = 5;       // focused mini-quiz
    
    // Update UI toggles
    qsa('.q-diff-btn').forEach(b => b.classList.toggle('active', b.dataset.val === 'medium'));
    qsa('.q-count-btn').forEach(b => b.classList.toggle('active', b.dataset.val === '5'));
    
    startQuiz();
  }
}

/* ═══════════════════════════════════════════════════════
   VOICE INPUT
═══════════════════════════════════════════════════════ */
function toggleVoice() {
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    showToast('Voice input not supported in this browser.'); return;
  }
  if (state.recording) {
    state.recognition?.stop();
    state.recording = false;
    el('voiceBtn')?.classList.remove('recording');
    return;
  }
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const rec = new SR();
  rec.lang = 'bn-IN';
  rec.interimResults = true;
  rec.continuous = false;
  state.recognition = rec;
  state.recording   = true;
  el('voiceBtn')?.classList.add('recording');

  rec.onresult = e => {
    const transcript = Array.from(e.results).map(r => r[0].transcript).join('');
    const qInput = el('questionInput');
    if (qInput) { qInput.value = transcript; autoResizeTextarea(qInput); }
  };
  rec.onend = () => { state.recording = false; el('voiceBtn')?.classList.remove('recording'); };
  rec.onerror = () => { state.recording = false; el('voiceBtn')?.classList.remove('recording'); };
  rec.start();
}

/* ═══════════════════════════════════════════════════════
   MCQ CARD RENDERER (Quiz Mode)
═══════════════════════════════════════════════════════ */
function renderQuizCards(bubble, text) {
  if (!bubble || !text) return;
  const textEl = bubble.querySelector('.ai-text');
  if (!textEl) return;

  // Very permissive regex: matches A. text, A) text, **A.** text, A: text, (A) text
  // Works for both English and Bengali content after the letter
  const optionRegex = /^[>\s]*[\*_]{0,2}\(?([A-D])\)?[.):\*_]{0,3}[\s\u200b]+(.{1,300})$/gm;
  const options = [];
  let m;
  const usedLetters = new Set();
  while ((m = optionRegex.exec(text)) !== null) {
    const letter = m[1].toUpperCase();
    if (!usedLetters.has(letter)) {
      usedLetters.add(letter);
      options.push({ letter, text: m[2].replace(/[\*_]{1,2}/g, '').trim() });
    }
  }

  // Need at least A and B to be a real MCQ
  if (options.length < 2 || !usedLetters.has('A') || !usedLetters.has('B')) return;

  // Extract question text (everything before first option line)
  const firstOptIdx = text.search(/^[>\s]*[\*_]{0,2}\(?[A-D]\)?[.):\*_]{0,3}[\s\u200b]+/m);
  const questionText = firstOptIdx > 0 ? text.substring(0, firstOptIdx).trim() : '';

  // Remove reaction bar (will re-add after)
  const reactionBar = bubble.querySelector('.reaction-bar');
  if (reactionBar) reactionBar.remove();

  const wrapId = 'qw' + Date.now();
  const optBtns = options.map(opt => `
    <button class="quiz-option" data-letter="${escHtml(opt.letter)}" data-action="pick-quiz">
      <span class="quiz-option-letter">${escHtml(opt.letter)}.</span>
      <span>${escHtml(opt.text)}</span>
    </button>`).join('');

  textEl.innerHTML = `
    <div class="quiz-q-header">${uiIcon("listChecks")}<span>Quiz Question</span></div>
    <div class="quiz-question">${renderMarkdown(questionText)}</div>
    <div class="quiz-options-wrap" id="${wrapId}">${optBtns}</div>`;
}

window.pickQuizOption = (btn) => {
  const letter = btn.dataset.letter;
  const wrap   = btn.closest('.quiz-options-wrap');
  // Visual feedback
  qsa('.quiz-option', wrap).forEach(b => { b.disabled = true; b.classList.add('answered'); });
  btn.classList.add('is-correct');
  // Auto-send the chosen letter
  const qInput = el('questionInput');
  if (qInput) qInput.value = letter;
  setTimeout(() => sendMessage(), 150);
};

function renderMarkdown(text) {
  if (!text) return '';
  let html;
  if (MARKED() && PURIFY()) {
    marked.setOptions({ breaks: true, gfm: true });
    html = DOMPurify.sanitize(marked.parse(text));
  } else {
    html = escHtml(text).replace(/\n/g, '<br>');
  }
  return html;
}

function applyMath(el) {
  if (!el || !KATEX()) return;
  try {
    renderMathInElement(el, {
      delimiters: [
        { left: '$$', right: '$$', display: true },
        { left: '$',  right: '$',  display: false },
        { left: '\\[', right: '\\]', display: true },
        { left: '\\(', right: '\\)', display: false },
      ],
      throwOnError: false,
    });
  } catch{}
}

function applyCodeCopyButtons(container) {
  if (!container) return;
  qsa('pre', container).forEach(pre => {
    if (pre.querySelector('.code-header')) return;
    const code = pre.querySelector('code');
    const lang = (code?.className?.match(/language-(\w+)/) || [])[1] || '';
    const header = document.createElement('div');
    header.className = 'code-header';
    header.innerHTML = `<span>${lang}</span><button class="copy-code-btn">Copy</button>`;
    header.querySelector('.copy-code-btn').addEventListener('click', () => {
      navigator.clipboard.writeText(code?.textContent || '');
      header.querySelector('.copy-code-btn').textContent = 'Copied!';
      setTimeout(() => { header.querySelector('.copy-code-btn').textContent = 'Copy'; }, 2000);
    });
    pre.insertBefore(header, pre.firstChild);
    if (HLJS() && code) {
      try { hljs.highlightElement(code); } catch{}
    }
  });
}

/* ═══════════════════════════════════════════════════════
   UTILITIES
═══════════════════════════════════════════════════════ */
function scrollToBottom() {
  const area = el('chatArea');
  if (area) area.scrollTop = area.scrollHeight;
}

function autoResizeTextarea(ta) {
  ta.style.height = 'auto';
  ta.style.height = Math.min(ta.scrollHeight, 140) + 'px';
}

function updateCharCount(len) {
  const cc = el('charCount');
  if (!cc) return;
  if (len > 3000) {
    cc.textContent = `${len}/4000`;
    cc.classList.add('visible', 'warn');
  } else if (len > 100) {
    cc.textContent = `${len}/4000`;
    cc.classList.add('visible');
    cc.classList.remove('warn');
  } else {
    cc.classList.remove('visible', 'warn');
  }
}

function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#039;');
}


function showToast(msg, type = 'error') {
  const t = el('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = `show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.className = ''; }, 4500);
}

/* ════════════════════════════════════════════════════════════
   GAME-CHANGER FEATURES
════════════════════════════════════════════════════════════ */

/* ── EXAM COUNTDOWN ──────────────────────────────────────── */
// ANM/GNM WB exam — update this date from admin panel announcements or hardcode
// Using July 2025 as placeholder — admin can update via announcement banner
const ANM_GNM_EXAM_DATE = new Date('2025-07-15T00:00:00+05:30');

function startCountdown() {
  function tick() {
    const now  = new Date();
    const diff = ANM_GNM_EXAM_DATE - now;
    const daysEl = el('countdownDays');
    if (!daysEl) return;
    if (diff <= 0) {
      daysEl.textContent = '0';
      return;
    }
    const days = Math.ceil(diff / (1000 * 60 * 60 * 24));
    daysEl.textContent = days;
  }
  tick();
  setInterval(tick, 60000); // update every minute
}

/* ── STUDY STREAK ──────────────────────────────────────────── */
function updateStreak() {
  const today      = new Date().toISOString().split('T')[0]; // YYYY-MM-DD
  const lastActive = localStorage.getItem('nexa-streak-date') || '';
  let streak       = parseInt(localStorage.getItem('nexa-streak-count') || '0', 10);
  let bestStreak   = parseInt(localStorage.getItem('nexa-streak-best')  || '0', 10);

  if (lastActive === today) {
    // Already counted today — do nothing
  } else {
    const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
    if (lastActive === yesterday) {
      streak++; // consecutive day
    } else {
      streak = 1; // reset
    }
    if (streak > bestStreak) bestStreak = streak;
    localStorage.setItem('nexa-streak-date',  today);
    localStorage.setItem('nexa-streak-count', streak);
    localStorage.setItem('nexa-streak-best',  bestStreak);
  }

  // Render into drawer
  set('streakCount', streak);
  const best = el('streakBest');
  if (best && bestStreak > 1) best.textContent = `• Best: ${bestStreak} days`;

  // Toast if milestone
  if (streak > 1 && lastActive !== today) {
    setTimeout(() => showToast(`${streak}-day streak! Keep it up!`, 'success'), 1500);
  }
}

/* ── BOOKMARKS ────────────────────────────────────────────── */
const BOOKMARK_KEY = 'nexa-bookmarks';

function getBookmarks() {
  try { return JSON.parse(localStorage.getItem(BOOKMARK_KEY) || '[]'); } catch { return []; }
}

function saveBookmark(text, btn) {
  const bookmarks = getBookmarks();
  const entry = {
    id:   Date.now(),
    text: text.substring(0, 2000),
    time: new Date().toLocaleString('en-IN', { dateStyle: 'short', timeStyle: 'short' })
  };
  bookmarks.unshift(entry);
  if (bookmarks.length > 50) bookmarks.pop(); // max 50 bookmarks
  localStorage.setItem(BOOKMARK_KEY, JSON.stringify(bookmarks));
  renderBookmarks();
  if (btn) { btn.innerHTML = uiIcon('bookmarkCheck') + '<span>Saved</span>'; setTimeout(() => { btn.innerHTML = uiIcon('bookmark') + '<span>Save</span>'; }, 2000); }
  showToast('Answer saved to bookmarks!', 'success');
}

window.deleteBookmark = function(id) {
  const bookmarks = getBookmarks().filter(b => b.id !== id);
  localStorage.setItem(BOOKMARK_KEY, JSON.stringify(bookmarks));
  renderBookmarks();
};

function clearAllBookmarks() {
  if (!confirm('Clear all saved bookmarks?')) return;
  localStorage.removeItem(BOOKMARK_KEY);
  renderBookmarks();
  showToast('All bookmarks cleared.', 'info');
}

function renderBookmarks() {
  const list = el('bookmarksList');
  if (!list) return;
  const bookmarks = getBookmarks();
  if (!bookmarks.length) {
    list.innerHTML = '<div class="bookmark-empty">No saved answers yet. Tap the bookmark icon on any NexA reply to save it!</div>';
    return;
  }
  list.innerHTML = bookmarks.map(b => `
    <div class="bookmark-item">
      <button class="bookmark-del" data-action="delete-bookmark" data-id="${b.id}" title="Delete">${uiIcon("trash")}</button>
      <div class="bookmark-text">${escHtml(b.text.replace(/[*_#`]/g, ''))}</div>
      <div class="bookmark-time">${escHtml(b.time)}</div>
    </div>`).join('');
}

// Patch addReactionBar to include bookmark button
const _origAddReactionBar = window.addReactionBar || null;
function addReactionBar(bubble, text) {
  if (!bubble) return;
  const bar = document.createElement('div');
  bar.className = 'reaction-bar';
  bar.innerHTML = `
    <button title="Copy" data-action="copy-answer">${uiIcon("copy")}<span>Copy</span></button>
    <button title="Save to bookmarks" data-action="save-answer">${uiIcon("bookmark")}<span>Save</span></button>`;
  bubble.appendChild(bar);
}

/* ── LEADERBOARD ─────────────────────────────────────────── */
async function openLeaderboard() {
  const trigger = surfaceState.id === 'profileDrawer' ? surfaceState.returnTo : document.activeElement;
  if (typeof closeDrawer === 'function') closeDrawer({ restoreFocus: false });
  // Remove existing modal
  const existing = document.querySelector('.leaderboard-modal');
  if (existing) { closeLeaderboard(); return; }

  const overlay = el('quizOverlay');
  if (overlay) {
    overlay.classList.remove('hidden');
    overlay.setAttribute('aria-hidden', 'false');
  }

  const modal = document.createElement('div');
  modal.className = 'leaderboard-modal';
  modal.id = 'leaderboardModal';
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-modal', 'true');
  modal.setAttribute('aria-labelledby', 'leaderboardTitle');
  modal.setAttribute('tabindex', '-1');
  modal.innerHTML = `
    <div class="leaderboard-header">
      <h2 id="leaderboardTitle">${uiIcon("trophy")}<span>Today's Top Scorers</span></h2>
      <button type="button" class="icon-btn" data-action="close-leaderboard" aria-label="Close leaderboard">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="leaderboard-list" id="lbList"><div class="lb-empty">Loading...</div></div>`;
  document.body.appendChild(modal);
  activateSurface('leaderboardModal', trigger, closeLeaderboard);

  try {
    const res  = await fetch('/api/leaderboard.php', { credentials: 'include' });
    const data = await res.json();
    const lbList = el('lbList');
    if (!lbList) return;

    if (!data.leaders || !data.leaders.length) {
      lbList.innerHTML = '<div class="lb-empty">No quiz results today yet.<br>Be the first to play!</div>';
      return;
    }
    const medals = ['gold', 'silver', 'bronze'];
    lbList.innerHTML = data.leaders.map((u, i) => `
      <div class="lb-row${i < 3 ? ' lb-top' : ''}">
        <span class="lb-rank rank-${i}">${uiIcon("medal")}</span>
        <span class="lb-name">${escHtml(u.name || 'Student')}</span>
        <span class="lb-score">${u.score}</span>
      </div>`).join('');
  } catch {
    const lbList = el('lbList');
    if (lbList) lbList.innerHTML = '<div class="lb-empty">Could not load leaderboard right now.</div>';
  }

}

function closeLeaderboard() {
  document.querySelector('.leaderboard-modal')?.remove();
  hide('quizOverlay');
  attr('quizOverlay', 'aria-hidden', 'true');
  releaseSurface('leaderboardModal');
}

/* ════════════════════════════════════════════════════════════
   MOBILE BROWSER FIXES
════════════════════════════════════════════════════════════ */

/**
 * FIX 1: iOS/Android Keyboard pushes input off screen
 * Uses VisualViewport API to detect keyboard open/close and
 * re-anchor the app layout so input island stays visible.
 */
function initMobileKeyboardFix() {
  if (!window.visualViewport) return;

  const app = document.querySelector('.app');
  if (!app) return;

  let ticking = false;

  function onViewportChange() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      const vv     = window.visualViewport;
      const offset = window.innerHeight - vv.height;

      if (offset > 100) {
        // Keyboard is OPEN — shift the app up by the keyboard height
        app.style.height    = vv.height + 'px';
        app.style.transform = `translateY(${vv.offsetTop}px)`;
        // Auto-scroll chat to bottom after keyboard opens
        const chatArea = document.querySelector('.chat-area');
        if (chatArea) setTimeout(() => { chatArea.scrollTop = chatArea.scrollHeight; }, 100);
      } else {
        // Keyboard is CLOSED — restore to full height
        app.style.height    = '';
        app.style.transform = '';
      }
      ticking = false;
    });
  }

  window.visualViewport.addEventListener('resize', onViewportChange);
  window.visualViewport.addEventListener('scroll', onViewportChange);
}

/**
 * FIX 2: Double-tap zoom prevention on buttons only
 * We allow pinch-zoom (removed maximum-scale) but prevent
 * double-tap zoom on interactive elements.
 */
function initDoubleTapZoomFix() {
  let lastTap = 0;
  document.addEventListener('touchend', (e) => {
    const t = Date.now();
    // Only block double-tap on buttons and chips, not on chat content
    if (e.target.matches('button, .welcome-chip, .quiz-option, .icon-btn, .send-btn')) {
      if (t - lastTap < 300) e.preventDefault();
    }
    lastTap = t;
  }, { passive: false });
}

/**
 * FIX 3: Dismiss keyboard when tapping outside input
 * On mobile, tapping outside an input should collapse the keyboard.
 */
document.addEventListener('DOMContentLoaded', () => {
  document.addEventListener('touchstart', (e) => {
    const input = document.querySelector('.question-input:focus');
    if (!input) return;
    if (!e.target.closest('.input-island')) {
      input.blur();
    }
  }, { passive: true });

  // ── ?q= URL param: auto-send a pre-loaded question ──────────
  // Use: https://ai.nexzen.live/?app=true&q=What+is+photosynthesis
  const _preQ = new URLSearchParams(window.location.search).get('q');
  if (_preQ && _preQ.trim()) {
    setTimeout(() => {
      const qInput = el('questionInput');
      if (qInput && typeof sendMessage === 'function') {
        qInput.value = decodeURIComponent(_preQ.trim());
        autoResizeTextarea(qInput);
        sendMessage();
      }
    }, 1500);
  }
});

function contentLanguage(value) {
  return /[\u0980-\u09FF]/u.test(String(value ?? '')) ? 'bn' : 'en';
}
