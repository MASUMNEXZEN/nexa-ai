// NexA AI — Admin Panel Engine

const state = {
  allUsers: [],
  usageChart: null,
  subjectChart: null,
};

function el(id) { return document.getElementById(id); }
function qsa(sel, parent = document) { return Array.from(parent.querySelectorAll(sel)); }
function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

window.onload = async () => {
  try {
    const res = await fetch('/api/admin-check.php', { credentials: 'include' });
    if (!res.ok) throw new Error('Not logged in');
    const data = await res.json();
    if (!data.loggedIn) throw new Error('Not logged in');
    
    // Authenticated — show all panels
    const loading = el('loadingScreen');
    if (loading) loading.style.display = 'none';
    
    if (el('authHeader')) el('authHeader').style.display = 'flex';
    if (el('tabNav'))    el('tabNav').style.display = 'flex';
    if (el('dashboard')) el('dashboard').style.display = 'block';
    // Show default dashboard tab
    if (el('tab-dashboard')) el('tab-dashboard').style.display = 'grid';
    
    if (el('adminEmail')) el('adminEmail').textContent = data.email || 'Admin';

    initCharts();
    fetchStats();
    loadSubscriptions(); // pre-load revenue numbers
    loadAnnouncement();

    setInterval(fetchStats, 30000);
  } catch (e) {
    window.location.href = '/admin-login';
  }
};

async function logout() {
  await fetch('/api/admin-logout.php', { credentials: 'include' });
  window.location.href = '/admin-login';
}

function initCharts() {
  if (typeof Chart === 'undefined') return;
  Chart.defaults.color = '#a1a1aa';
  Chart.defaults.font.family = "'Outfit', sans-serif";
  
  const ctxUsage = el('usageChart');
  if (ctxUsage) {
    state.usageChart = new Chart(ctxUsage.getContext('2d'), {
      type: 'line',
      data: {
        labels: [],
        datasets: [{
          label: 'Questions Answered',
          data: [],
          borderColor: '#e62335',
          backgroundColor: 'rgba(230, 35, 53, 0.1)',
          borderWidth: 2,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: '#08090f',
          pointBorderColor: '#e62335',
          pointRadius: 4,
          pointBorderWidth: 2
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 12, cornerRadius: 8 }
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } }
        }
      }
    });
  }

  const ctxSub = el('subjectChart');
  if (ctxSub) {
    state.subjectChart = new Chart(ctxSub.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: [],
        datasets: [{
          data: [],
          backgroundColor: ['#e62335', '#ff3b4d', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#64748b'],
          borderWidth: 0,
          hoverOffset: 4
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
          legend: { position: 'right', labels: { color: '#a1a1aa', padding: 20 } }
        }
      }
    });
  }
}

async function fetchStats() {
  try {
    const res = await fetch('/api/admin-stats.php?t=' + Date.now(), { credentials: 'include' });
    if (!res.ok) {
      if (res.status === 401) { window.location.href = '/admin-login'; return; }
      throw new Error();
    }
    const data = await res.json();
    
    // Top numbers
    if (el('totalQuestions')) el('totalQuestions').textContent = data.totalQuestionsToday || 0;
    if (el('currentLimitDisplay')) el('currentLimitDisplay').textContent = data.currentLimit || 60;
    if (el('newLimitInput') && !el('newLimitInput').matches(':focus')) el('newLimitInput').value = data.currentLimit || 60;
    if (el('totalUsersCount')) el('totalUsersCount').textContent = data.totalUsers || 0;

    // Active users today
    const activeBody = el('userTableBody');
    if (activeBody) {
      if (!data.activeUsersStats || !data.activeUsersStats.length) {
        activeBody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-muted);">No activity yet today.</td></tr>';
      } else {
        activeBody.innerHTML = data.activeUsersStats.map(u => {
          const limitReached = u.count >= (data.currentLimit || 60);
          const badgeClass = limitReached ? 'badge-danger' : 'badge-success';
          const badgeText = limitReached ? 'LIMIT REACHED' : 'ACTIVE';
          return `<tr>
            <td style="font-family:var(--font-mono); font-size:13px;">${escHtml(u.email)}</td>
            <td><strong>${u.count}</strong> <span style="color:var(--text-muted);font-size:12px;">/ ${data.currentLimit||60}</span></td>
            <td><span class="badge ${badgeClass}">${badgeText}</span></td>
          </tr>`;
        }).join('');
      }
    }

    // Registered Users
    const regBody = el('registeredUsersBody');
    if (regBody) {
      state.allUsers = data.registeredUsers || [];
      if (!state.allUsers.length) {
        regBody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No users found.</td></tr>';
      } else {
        regBody.innerHTML = state.allUsers.map((u, i) => {
          const joined = u.created_at ? u.created_at.split('T')[0] : '—';
          const loc = [u.country, u.state].filter(x => x).join(', ') || '—';
          const planType = ['pro','premium','admin','banned'].includes(u.type) ? u.type : 'free';
          const planBadge = `<span class="badge badge-${planType}">${planType.toUpperCase()}</span>`;
          return `<tr>
            <td style="color:var(--text-muted);font-size:13px;">${joined}</td>
            <td style="font-weight:600;">${escHtml(u.name) || '—'}</td>
            <td style="font-family:var(--font-mono);font-size:12px;">${escHtml(u.email)}</td>
            <td><span class="badge ${u.type === 'google' ? 'badge-info' : 'badge-neutral'}">${escHtml(u.type)}</span></td>
            <td>${planBadge}</td>
            <td style="font-size:13px;">${escHtml(loc)}</td>
            <td style="display:flex;gap:6px;">
              <button class="btn btn-mini" onclick="viewUser(${i})">View</button>
              <button class="btn btn-mini" onclick="openUserModal('${escHtml(u.email)}','${escHtml(u.name||'')}','${planType}')" style="color:var(--info)">Manage</button>
            </td>
          </tr>`;
        }).join('');
      }
    }

    // Charts
    if (state.usageChart && data.graphDates && data.graphCounts) {
      state.usageChart.data.labels = data.graphDates;
      state.usageChart.data.datasets[0].data = data.graphCounts;
      state.usageChart.update();
    }
    if (state.subjectChart && data.subjectStats) {
      state.subjectChart.data.labels = Object.keys(data.subjectStats).map(s => s || 'General');
      state.subjectChart.data.datasets[0].data = Object.values(data.subjectStats);
      state.subjectChart.update();
    }

    // Recent queries
    const topicList = el('recentTopicsList');
    if (topicList) {
      if (!data.recentQueries || !data.recentQueries.length) {
        topicList.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">No queries found.</div>';
      } else {
        topicList.innerHTML = data.recentQueries.map(q => `
          <div class="topic-item">
            <div class="topic-meta">${escHtml(q.user)} • ${escHtml(q.time)}</div>
            <div class="topic-query">${escHtml(q.query)}</div>
          </div>
        `).join('');
      }
    }

  } catch(e) {
    console.error(e);
  }
}

async function fetchTelegramUsers() {
  try {
    const res = await fetch('/api/admin-telegram-users.php?t=' + Date.now(), { credentials: 'include' });
    if (!res.ok) return;
    const data = await res.json();
    if (data.status === 'success') {
      if (el('totalTelegramUsers')) el('totalTelegramUsers').textContent = data.total_users + ' TOTAL';
      if (el('topTotalTelegramUsers')) el('topTotalTelegramUsers').textContent = data.total_users;
      
      const tbody = el('telegramUsersBody');
      if (tbody) {
        if (!data.users || !data.users.length) {
          tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);">No bot users yet</td></tr>';
          return;
        }
        tbody.innerHTML = data.users.map(u => {
          const name = [u.first_name, u.last_name].filter(Boolean).join(' ') || ('User ' + u.telegram_id);
          const userLink = u.username ? `<a href="https://t.me/${u.username}" target="_blank" style="color:var(--info);text-decoration:none;">@${u.username}</a>` : '<span style="color:var(--text-muted)">N/A</span>';
          return `<tr>
            <td><strong>${escHtml(name)}</strong><br><span style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)">ID: ${u.telegram_id}</span></td>
            <td style="font-family:var(--font-mono)">${escHtml(u.phone_number) || '—'}</td>
            <td>${userLink}</td>
            <td><strong>${u.daily_queries || 0}</strong> <span style="color:var(--text-muted);font-size:12px;">/ 50</span></td>
            <td style="font-size:13px;color:var(--text-muted)">${escHtml(u.last_active) || 'Unknown'}</td>
          </tr>`;
        }).join('');
      }
    }
  } catch(e) {}
}

async function updateLimit() {
  const inp = el('newLimitInput');
  const btn = el('saveLimitBtn');
  if (!inp || !btn) return;
  const newLimit = parseInt(inp.value, 10);
  if (!newLimit || newLimit < 1) { showToast('Invalid limit', 'error'); return; }
  
  btn.textContent = 'Saving…';
  btn.disabled = true;
  try {
    const res = await fetch('/api/admin-limit.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ daily_limit: newLimit })
    });
    if (!res.ok) throw new Error();
    const data = await res.json();
    if (data.status === 'success') {
      if (el('currentLimitDisplay')) el('currentLimitDisplay').textContent = newLimit;
      showToast('Limit updated to ' + newLimit, 'success');
      fetchStats();
    } else {
      showToast(data.error || 'Update failed', 'error');
    }
  } catch(e) {
    showToast('Network error', 'error');
  } finally {
    btn.textContent = 'Save Limit';
    btn.disabled = false;
  }
}

// Global Announcement
async function loadAnnouncement() {
  try {
    const res = await fetch('/api/admin-announcement.php', { credentials: 'include' });
    if (!res.ok) return;
    const data = await res.json();
    if (el('announcementText')) el('announcementText').value = data.text || '';
    if (el('announcementActive')) el('announcementActive').checked = data.active || false;
  } catch(e) {}
}

async function saveAnnouncement() {
  const btn = el('saveAnnouncementBtn');
  if (!btn) return;
  btn.textContent = 'Saving...';
  btn.disabled = true;
  try {
    const res = await fetch('/api/admin-announcement.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text: el('announcementText').value.trim(),
        active: el('announcementActive').checked
      })
    });
    const data = await res.json();
    if (data.success) {
      showToast('Announcement saved!', 'success');
    } else {
      showToast(data.error || 'Failed to save', 'error');
    }
  } catch(e) {
    showToast('Network error', 'error');
  } finally {
    btn.textContent = 'Save Announcement';
    btn.disabled = false;
  }
}

// Reports
async function loadReports() {
  const c = el('reportsContainer');
  const b = el('reportsBadge');
  if (!c || !b) return;
  
  c.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;">Loading…</div>';
  try {
    const res = await fetch('/api/admin-reports.php', { credentials: 'include' });
    const data = await res.json();
    const reps = data.reports || [];
    b.textContent = `${data.total || 0} REPORTS`;
    if (!reps.length) {
      c.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:20px;">No bug reports! 🎉</div>';
      return;
    }
    c.innerHTML = reps.map(r => {
      const ts = r.timestamp ? r.timestamp.replace('T', ' ').split('+')[0] : '—';
      return `
        <div class="report-item">
          <div class="report-meta">
            <span class="badge badge-danger">BUG REPORT</span>
            <span>${ts}</span>
            <span>${escHtml(r.page)}</span>
            <span style="font-family:var(--font-mono)">${(r.device_id||'').substring(0,12)}…</span>
          </div>
          <div class="report-text">${escHtml(r.message)}</div>
        </div>
      `;
    }).join('');
  } catch(e) {
    c.innerHTML = '<div style="text-align:center;color:var(--accent);padding:20px;">Failed to load reports</div>';
  }
}

// Telegram Broadcast
function openBroadcast() { showModal('broadcastModal'); }
async function sendBroadcast() {
  const txt = el('broadcastMessage').value.trim();
  if (!txt) { showToast('Message empty', 'error'); return; }
  const confirmed = confirm('Broadcast this to all Telegram bot users?');
  if (!confirmed) return;
  
  const btn = el('sendBroadcastBtn');
  btn.textContent = 'Sending...';
  btn.disabled = true;
  try {
    const res = await fetch('/api/admin-telegram-broadcast.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: txt })
    });
    const data = await res.json();
    if (data.status === 'success') {
      showToast(`Sent: ${data.success_count}. Failed: ${data.fail_count}`, 'success');
      hideModal('broadcastModal');
      el('broadcastMessage').value = '';
    } else {
      showToast(data.error || 'Failed', 'error');
    }
  } catch(e) {
    showToast('Network error', 'error');
  } finally {
    btn.textContent = 'Send Broadcast Now';
    btn.disabled = false;
  }
}

// User Profile Modal
function viewUser(idx) {
  const u = state.allUsers[idx];
  if (!u) return;
  
  el('pmAvatar').textContent = u.name ? u.name.charAt(0).toUpperCase() : u.email.charAt(0).toUpperCase();
  el('pmName').textContent = u.name || 'Not Set';
  el('pmEmail').textContent = u.email;
  el('pmType').textContent = (u.type || 'Email').toUpperCase();
  el('pmJoined').textContent = u.created_at ? u.created_at.replace('T', ' ').split('.')[0] : '—';
  
  el('pmCountry').textContent = u.country || '—';
  el('pmState').textContent = u.state || '—';
  el('pmDistrict').textContent = u.district || '—';
  el('pmPin').textContent = u.pin || '—';
  el('pmAddress').textContent = u.address || '—';
  
  showModal('userProfileModal');
}

// Toast System
function showToast(msg, type = 'success') {
  const t = el('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'show ' + type;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.className = '', 3000);
}

// Modal System
function showModal(id) { const m = el(id); if (m) m.classList.add('show'); }
function hideModal(id) { const m = el(id); if (m) m.classList.remove('show'); }

qsa('.modal-overlay').forEach(ov => {
  ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('show'); });
});

// Live user search filter
function filterUsers() {
  const q = (el('userSearch')?.value || '').toLowerCase();
  if (!state.allUsers.length) return;
  const rows = document.querySelectorAll('#registeredUsersBody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = (!q || text.includes(q)) ? '' : 'none';
  });
}
