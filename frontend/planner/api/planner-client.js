(function () {
  'use strict';

  let csrfToken = '';

  async function request(path, method, body) {
    const headers = { Accept: 'application/json' };
    const options = { method: method || 'GET', credentials: 'include', headers };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
      options.body = JSON.stringify(body);
    }
    const response = await fetch(path, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.error || 'Planner request failed.');
      error.code = data.code || 'planner_request_failed';
      error.status = response.status;
      error.requestId = data.request_id || '';
      throw error;
    }
    return data;
  }

  const api = {
    setCsrfToken(token) {
      csrfToken = typeof token === 'string' ? token : '';
    },
    getProfile() {
      return request('/api/planner-profile.php');
    },
    saveProfile(payload) {
      return request('/api/planner-profile.php', 'POST', payload);
    },
    getDiagnostic() {
      return request('/api/planner-assessment.php');
    },
    startDiagnostic() {
      return request('/api/planner-assessment.php', 'POST', { action: 'start' });
    },
    answerDiagnostic(payload) {
      return request('/api/planner-assessment.php', 'POST', { action: 'answer', ...payload });
    },
    completeDiagnostic(attemptId) {
      return request('/api/planner-assessment.php', 'POST', { action: 'complete', attempt_id: attemptId });
    },
    getMonth(year, month) {
      const params = year && month ? '?year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month) : '';
      return request('/api/planner-month.php' + params);
    },
    regenerateMonth() {
      return request('/api/planner-month.php', 'POST', { regenerate: true });
    },
    createManualPlan(payload) {
      return request('/api/planner-manual.php', 'POST', payload);
    },
    getWeek(date) {
      const suffix = date ? '?date=' + encodeURIComponent(date) : '';
      return request('/api/planner-week.php' + suffix);
    },
    saveWeekSubjects(date, subjectCodes) {
      return request('/api/planner-week.php', 'POST', {
        date,
        subject_codes: Array.isArray(subjectCodes) ? subjectCodes : [],
      });
    },
    getDay(date) {
      const suffix = date ? '?date=' + encodeURIComponent(date) : '';
      return request('/api/planner-day.php' + suffix);
    },
    getQuestion(params) {
      const query = new URLSearchParams(params || {}).toString();
      return request('/api/planner-question.php' + (query ? '?' + query : ''));
    },
    getProgress() {
      return request('/api/planner-progress.php');
    },
    answerProgress(payload) {
      return request('/api/planner-progress.php', 'POST', { action: 'answer', ...payload });
    },
    updateTask(payload) {
      return request('/api/planner-task.php', 'POST', payload);
    },
  };

  window.NexaPlanner = api;
}());