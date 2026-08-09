(function () {
  'use strict';

  const state = {
    profile: null,
    exams: [],
    subjects: [],
    topics: [],
    availability: [],
    attemptId: null,
    questions: [],
    activeView: 'today',
    month: null,
    week: null,
    question: null,
  };

  const el = (id) => document.getElementById(id);
  const show = (id) => el(id)?.classList.remove('hidden');
  const hide = (id) => el(id)?.classList.add('hidden');
  const text = (id, value) => { const node = el(id); if (node) node.textContent = value == null ? '' : String(value); };
  const todayIso = () => new Date().toISOString().slice(0, 10);

  function setStatus(message, kind) {
    const node = el('plannerPanelStatus');
    if (!node) return;
    node.textContent = message;
    node.dataset.state = kind || 'neutral';
  }

  function setActive(isPlanner) {
    const chatButton = el('sidebarChatBtn');
    const plannerButton = el('sidebarPlannerBtn');
    chatButton?.classList.toggle('is-active', !isPlanner);
    plannerButton?.classList.toggle('is-active', isPlanner);
    if (isPlanner) {
      chatButton?.removeAttribute('aria-current');
      plannerButton?.setAttribute('aria-current', 'page');
    } else {
      plannerButton?.removeAttribute('aria-current');
      chatButton?.setAttribute('aria-current', 'page');
    }
  }

  function openPlanner() {
    el('chatArea')?.classList.add('hidden');
    document.querySelector('.input-footer-wrap')?.classList.add('hidden');
    show('plannerPanel');
    setActive(true);
    window.closeWorkspaceSidebar?.();
    loadProfile();
  }

  function closePlanner() {
    hide('plannerPanel');
    el('chatArea')?.classList.remove('hidden');
    document.querySelector('.input-footer-wrap')?.classList.remove('hidden');
    setActive(false);
  }

  function populateExamOptions() {
    const select = el('plannerExam');
    if (!select) return;
    select.replaceChildren();
    state.exams.forEach((exam) => {
      const option = document.createElement('option');
      option.value = exam.code || '';
      option.textContent = exam.display_name || exam.code || 'Exam';
      select.appendChild(option);
    });
    if (state.profile?.exam_code) select.value = state.profile.exam_code;
  }

  function populateProfileForm() {
    if (!state.profile) return;
    const daily = el('plannerDailyMinutes');
    if (daily) daily.value = state.profile.daily_minutes == null ? '' : state.profile.daily_minutes;
    const session = el('plannerSessionMinutes');
    if (session) session.value = String(state.profile.preferred_session_minutes || 50);
    const level = el('plannerCurrentLevel');
    if (level) level.value = state.profile.current_level || 'beginner';
    const timeBlock = el('plannerTimeBlock');
    if (timeBlock) timeBlock.value = state.profile.preferred_time_block || '';
    const availabilityByDay = new Map(state.availability.map((row) => [Number(row.weekday), Boolean(Number(row.available))]));
    document.querySelectorAll('.planner-day-checkbox').forEach((checkbox) => {
      const weekday = Number(checkbox.dataset.weekday);
      if (availabilityByDay.has(weekday)) checkbox.checked = availabilityByDay.get(weekday);
    });
  }

  async function loadProfile() {
    if (!window.NexaPlanner) {
      setStatus('Planner client is not available.', 'error');
      return;
    }
    try {
      hide('plannerAuthPrompt');
      const response = await window.NexaPlanner.getProfile();
      state.exams = Array.isArray(response.exams) ? response.exams : [];
      state.subjects = Array.isArray(response.subjects) ? response.subjects : [];
      state.topics = Array.isArray(response.topics) ? response.topics : [];
      state.availability = Array.isArray(response.availability) ? response.availability : [];
      state.profile = response.profile || null;
      populateExamOptions();
      populateProfileForm();
      if (!state.profile || !state.profile.onboarding_completed) {
        show('plannerOnboardingCard');
        hide('plannerDiagnosticCard');
        hide('plannerPlanCard');
        setStatus('Set your preferences first. The planner never asks for an exam date.', 'neutral');
        return;
      }
      hide('plannerOnboardingCard');
      await loadDiagnosticState();
    } catch (error) {
      if (error?.status === 401) {
        hide('plannerOnboardingCard');
        hide('plannerDiagnosticCard');
        hide('plannerPlanCard');
        show('plannerAuthPrompt');
        setStatus('Sign in to save preferences and use the study planner.', 'neutral');
        return;
      }
      setStatus(error.message || 'Could not load planner profile.', 'error');
    }
  }

  function profilePayload() {
    const daily = el('plannerDailyMinutes')?.value.trim() || '';
    const dailyMinutes = daily === '' ? null : Number(daily);
    const minutesPerDay = dailyMinutes == null ? 180 : dailyMinutes;
    return {
      exam_code: el('plannerExam')?.value || '',
      daily_minutes: dailyMinutes,
      preferred_session_minutes: Number(el('plannerSessionMinutes')?.value || 50),
      preferred_time_block: el('plannerTimeBlock')?.value.trim() || null,
      current_level: el('plannerCurrentLevel')?.value || 'beginner',
      complete_onboarding: true,
      availability: Array.from(document.querySelectorAll('.planner-day-checkbox')).map((checkbox) => ({
        weekday: Number(checkbox.dataset.weekday),
        available: checkbox.checked,
        minutes: minutesPerDay,
      })),
    };
  }

  async function saveProfile(event) {
    event.preventDefault();
    const button = el('plannerSaveProfileBtn');
    if (button) button.disabled = true;
    setStatus('Saving your study preferences...', 'loading');
    try {
      const response = await window.NexaPlanner.saveProfile(profilePayload());
      state.profile = response.profile;
      hide('plannerOnboardingCard');
      await loadDiagnosticState();
    } catch (error) {
      setStatus(error.message || 'Could not save your preferences.', 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function loadDiagnosticState() {
    show('plannerDiagnosticCard');
    hide('plannerPlanCard');
    try {
      const response = await window.NexaPlanner.getDiagnostic();
      if (response.status === 'completed' || response.diagnostic_completed) {
        hide('plannerDiagnosticCard');
        show('plannerPlanCard');
        await loadToday();
        return;
      }
      if (response.status === 'started' && response.questions?.length) {
        state.attemptId = response.attempt_id;
        state.questions = response.questions;
        renderDiagnostic();
      } else {
        state.attemptId = null;
        state.questions = [];
        renderDiagnosticStart();
      }
      setStatus('Complete the diagnostic to unlock your personalized plan.', 'neutral');
    } catch (error) {
      setStatus(error.message || 'Could not load the diagnostic.', 'error');
    }
  }

  function renderDiagnosticStart() {
    const body = el('plannerDiagnosticBody');
    if (!body) return;
    body.replaceChildren();
    const copy = document.createElement('p');
    copy.textContent = 'The assessment uses verified questions and samples the subjects in your selected exam.';
    body.appendChild(copy);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'planner-primary-button';
    button.id = 'plannerStartDiagnosticBtn';
    button.textContent = 'Start diagnostic';
    button.addEventListener('click', startDiagnostic);
    body.appendChild(button);
  }

  function renderDiagnostic() {
    const body = el('plannerDiagnosticBody');
    if (!body) return;
    body.replaceChildren();
    const intro = document.createElement('p');
    intro.textContent = 'Answer each question. Your score guides the planner; it is not a prediction of your exam result.';
    body.appendChild(intro);
    state.questions.forEach((question, index) => {
      const card = document.createElement('article');
      card.className = 'planner-question-card';
      const heading = document.createElement('div');
      heading.className = 'planner-question-card__meta';
      heading.textContent = 'Question ' + (index + 1) + ' - ' + (question.subject_name_en || question.subject_code || 'Subject');
      card.appendChild(heading);
      const prompt = document.createElement('h4');
      prompt.textContent = question.prompt_en || '';
      card.appendChild(prompt);
      const options = document.createElement('div');
      options.className = 'planner-question-options';
      const selected = question.selected_option_key || '';
      (question.options || []).forEach((option) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'planner-option-button' + (selected === option.option_key ? ' is-selected' : '');
        button.dataset.questionId = String(question.id);
        button.dataset.optionKey = option.option_key;
        button.disabled = Boolean(selected);
        const key = document.createElement('strong');
        key.textContent = option.option_key;
        const label = document.createElement('span');
        label.textContent = option.option_text_en || '';
        button.append(key, label);
        options.appendChild(button);
      });
      card.appendChild(options);
      body.appendChild(card);
    });
    const complete = document.createElement('button');
    complete.type = 'button';
    complete.className = 'planner-primary-button';
    complete.id = 'plannerCompleteDiagnosticBtn';
    complete.textContent = 'Complete diagnostic';
    complete.disabled = state.questions.some((question) => !question.selected_option_key);
    complete.addEventListener('click', completeDiagnostic);
    body.appendChild(complete);
  }

  async function startDiagnostic() {
    const button = el('plannerStartDiagnosticBtn');
    if (button) button.disabled = true;
    setStatus('Preparing your diagnostic...', 'loading');
    try {
      const response = await window.NexaPlanner.startDiagnostic();
      state.attemptId = response.attempt_id;
      state.questions = response.questions || [];
      renderDiagnostic();
      setStatus('Answer the diagnostic to unlock your personalized plan.', 'neutral');
    } catch (error) {
      setStatus(error.message || 'Diagnostic could not start.', 'error');
      if (button) button.disabled = false;
    }
  }

  async function answerDiagnostic(button) {
    if (button.disabled || !state.attemptId) return;
    const questionId = Number(button.dataset.questionId);
    const optionKey = button.dataset.optionKey || '';
    button.disabled = true;
    try {
      await window.NexaPlanner.answerDiagnostic({ attempt_id: state.attemptId, question_id: questionId, option_key: optionKey });
      const question = state.questions.find((item) => Number(item.id) === questionId);
      if (question) question.selected_option_key = optionKey;
      const card = button.closest('.planner-question-card');
      card?.querySelectorAll('.planner-option-button').forEach((option) => { option.disabled = true; });
      button.classList.add('is-selected');
      const complete = el('plannerCompleteDiagnosticBtn');
      if (complete) complete.disabled = state.questions.some((item) => !item.selected_option_key);
    } catch (error) {
      button.disabled = false;
      setStatus(error.message || 'Answer could not be saved.', 'error');
    }
  }

  async function completeDiagnostic() {
    if (!state.attemptId) return;
    const button = el('plannerCompleteDiagnosticBtn');
    if (button) button.disabled = true;
    setStatus('Scoring your diagnostic and building your plan...', 'loading');
    try {
      await window.NexaPlanner.completeDiagnostic(state.attemptId);
      state.profile.diagnostic_completed = true;
      hide('plannerDiagnosticCard');
      show('plannerPlanCard');
      await loadToday();
    } catch (error) {
      setStatus(error.message || 'Diagnostic could not be completed.', 'error');
      if (button) button.disabled = false;
    }
  }

  function dateLabel(value, options) {
    if (!value) return '';
    return new Intl.DateTimeFormat('en-IN', options || { day: 'numeric', month: 'short' }).format(new Date(value + 'T12:00:00'));
  }

  function createIcon(name) {
    const node = document.createElement('span');
    node.innerHTML = window.NexaIcons?.svg(name) || '';
    return node.firstElementChild || node;
  }

  function renderTaskList(tasks, targetId) {
    const list = el(targetId);
    if (!list) return;
    list.replaceChildren();
    if (!tasks.length) {
      const empty = document.createElement('p');
      empty.className = 'planner-empty-state';
      empty.textContent = 'No tasks are scheduled for this day.';
      list.appendChild(empty);
      return;
    }
    tasks.forEach((task) => {
      const item = document.createElement('article');
      item.className = 'planner-task-item' + (task.status === 'completed' ? ' is-completed' : '') + (task.status === 'skipped' ? ' is-skipped' : '');
      item.dataset.taskId = String(task.id || '');
      const icon = document.createElement('span');
      icon.className = 'planner-task-item__icon';
      icon.textContent = String((task.subject_code || 'NX').slice(0, 2));
      const copy = document.createElement('div');
      copy.className = 'planner-task-item__copy';
      const title = document.createElement('h4');
      title.textContent = task.title_en || 'Study task';
      const meta = document.createElement('p');
      meta.textContent = (task.subject_name_en || task.subject_code || 'Subject') + ' - ' + String(task.task_type || 'practice').replace('_', ' ') + ' - ' + String(task.estimated_minutes || 0) + ' min';
      copy.append(title, meta);
      if (task.question_id) {
        const question = document.createElement('p');
        question.className = 'planner-task-question';
        question.textContent = task.question_year ? 'Verified question - ' + task.question_year : 'Verified question ready';
        copy.appendChild(question);
      }
      item.append(icon, copy);
      if (['planned', 'in_progress'].includes(task.status)) {
        const actions = document.createElement('div');
        actions.className = 'planner-task-actions';
        if (task.question_id) actions.appendChild(questionButton(task.id));
        if (task.status === 'planned') actions.appendChild(taskButton('start', 'arrowRight', 'Start', task.id));
        actions.appendChild(taskButton('complete', 'check', 'Complete', task.id));
        actions.appendChild(taskButton('skip', 'arrowRight', 'Skip', task.id));
        item.appendChild(actions);
      } else {
        const status = document.createElement('span');
        status.className = 'planner-task-status';
        status.textContent = String(task.status || 'planned').replace('_', ' ');
        item.appendChild(status);
      }
      list.appendChild(item);
    });
  }

  function taskButton(action, iconName, label, taskId) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'planner-task-action';
    button.dataset.action = action;
    button.dataset.taskId = String(taskId || '');
    button.setAttribute('aria-label', label + ' task');
    button.append(createIcon(iconName), document.createTextNode(label));
    return button;
  }

  async function loadToday() {
    try {
      setStatus('Building your calendar plan...', 'loading');
      const [month, today] = await Promise.all([window.NexaPlanner.getMonth(), window.NexaPlanner.getDay()]);
      state.month = month;
      renderToday(month, today);
      if (state.activeView === 'week') await loadWeek();
      if (state.activeView === 'progress') await loadProgress();
      setStatus('Your plan is ready. Complete, review, and adjust one task at a time.', 'success');
    } catch (error) {
      setStatus(error.message || 'Your plan could not be loaded.', 'error');
    }
  }

  function renderToday(month, today) {
    const tasks = Array.isArray(today.tasks) ? today.tasks : [];
    const summary = month.month?.summary || {};
    text('plannerTodaySummary', String(summary.active_days || 0) + ' active days this month - ' + String(summary.total_minutes || 0) + ' planned minutes');
    text('plannerTodayDate', dateLabel(today.date || todayIso(), { weekday: 'short', day: 'numeric', month: 'short' }));
    text('plannerTodayHeading', tasks.length ? 'Your next best study session' : 'A lighter day is still a useful day');
    renderTaskList(tasks, 'plannerTodayTasks');
  }

  function manualWeekStart(value) {
    const source = new Date((value || todayIso()) + 'T12:00:00');
    if (Number.isNaN(source.getTime())) return todayIso();
    const day = source.getDay();
    const offset = day === 0 ? -6 : 1 - day;
    source.setDate(source.getDate() + offset);
    return source.toISOString().slice(0, 10);
  }

  function makeManualField(labelText, control, className) {
    const label = document.createElement('label');
    label.className = 'planner-manual-field' + (className ? ' ' + className : '');
    const caption = document.createElement('span');
    caption.textContent = labelText;
    label.append(caption, control);
    return label;
  }

  function fillManualSubjects(select, selectedCode) {
    if (!select) return;
    select.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose a subject';
    select.appendChild(placeholder);
    state.subjects.forEach((subject) => {
      const option = document.createElement('option');
      option.value = subject.code || '';
      option.textContent = subject.name_en || subject.code || 'Subject';
      option.selected = String(subject.code) === String(selectedCode || '');
      select.appendChild(option);
    });
  }

  function fillManualTopics(select, subjectCode, selectedTopicId) {
    if (!select) return;
    select.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Any syllabus topic';
    select.appendChild(placeholder);
    const subject = state.subjects.find((item) => String(item.code) === String(subjectCode || ''));
    const topics = subject ? state.topics.filter((topic) => Number(topic.subject_id) === Number(subject.id)) : [];
    topics.forEach((topic) => {
      const option = document.createElement('option');
      option.value = String(topic.id);
      option.textContent = topic.title_en || topic.code || 'Syllabus topic';
      option.selected = Number(topic.id) === Number(selectedTopicId || 0);
      select.appendChild(option);
    });
  }

  function fillManualTaskTypes(select, selectedType) {
    if (!select) return;
    select.replaceChildren();
    [['learn', 'Learn'], ['practice', 'Practice'], ['pyq', 'PYQ practice'], ['revision', 'Revision'], ['error_review', 'Error review'], ['mock_test', 'Mock test'], ['recovery', 'Catch-up']].forEach(([value, label]) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      option.selected = value === (selectedType || 'learn');
      select.appendChild(option);
    });
  }

  function addManualWeekRow(values) {
    const target = el('plannerManualWeekRows');
    if (!target) return;
    const row = document.createElement('div');
    row.className = 'planner-manual-week-row';
    const date = document.createElement('input');
    date.type = 'date';
    date.className = 'planner-manual-date';
    date.value = values?.scheduled_date || el('plannerManualWeekStart')?.value || todayIso();
    const subject = document.createElement('select');
    subject.className = 'planner-manual-subject';
    fillManualSubjects(subject, values?.subject_code || '');
    const topic = document.createElement('select');
    topic.className = 'planner-manual-topic';
    fillManualTopics(topic, subject.value, values?.topic_id || '');
    const type = document.createElement('select');
    type.className = 'planner-manual-task-type';
    fillManualTaskTypes(type, values?.task_type || 'learn');
    const minutes = document.createElement('input');
    minutes.type = 'number';
    minutes.min = '30';
    minutes.max = '1440';
    minutes.step = '5';
    minutes.className = 'planner-manual-minutes';
    date.required = true;
    subject.required = true;
    minutes.required = true;
    minutes.value = String(values?.estimated_minutes || state.profile?.preferred_session_minutes || 50);
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'planner-icon-button planner-manual-remove';
    remove.setAttribute('aria-label', 'Remove session');
    remove.title = 'Remove session';
    remove.appendChild(createIcon('trash'));
    remove.addEventListener('click', () => {
      if (target.children.length > 1) row.remove();
    });
    subject.addEventListener('change', () => fillManualTopics(topic, subject.value, ''));
    row.append(
      makeManualField('Date', date),
      makeManualField('Subject', subject),
      makeManualField('Topic', topic),
      makeManualField('Type', type),
      makeManualField('Minutes', minutes),
      remove,
    );
    target.appendChild(row);
  }

  function setManualScope(scope) {
    state.manualScope = scope === 'week' ? 'week' : 'day';
    document.querySelectorAll('[data-manual-scope]').forEach((button) => {
      const active = button.dataset.manualScope === state.manualScope;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const dayFields = el('plannerManualDayFields');
    const weekFields = el('plannerManualWeekFields');
    dayFields?.classList.toggle('hidden', state.manualScope !== 'day');
    ['plannerManualDayDate', 'plannerManualDaySubject', 'plannerManualDayMinutes'].forEach((id) => { const control = el(id); if (control) control.required = state.manualScope === 'day'; });
    const weekStartControl = el('plannerManualWeekStart');
    if (weekStartControl) weekStartControl.required = state.manualScope === 'week';
    weekFields?.classList.toggle('hidden', state.manualScope !== 'week');
    text('plannerManualModalTitle', state.manualScope === 'day' ? 'Plan a study day' : 'Plan your study week');
    text('plannerManualModalDescription', state.manualScope === 'day'
      ? 'Choose the exact session you want to complete today or on another generated study date.'
      : 'Add the sessions you want to own this week. You can mix subjects, dates, and session types.');
    if (state.manualScope === 'week' && !el('plannerManualWeekRows')?.children.length) addManualWeekRow();
  }

  function openManualPlanner(scope) {
    const modal = el('plannerManualModal');
    if (!modal) return;
    state.manualScope = scope === 'week' ? 'week' : 'day';
    const dayDate = el('plannerManualDayDate');
    if (dayDate) dayDate.value = todayIso();
    const daySubject = el('plannerManualDaySubject');
    fillManualSubjects(daySubject, '');
    fillManualTopics(el('plannerManualDayTopic'), daySubject?.value || '', '');
    fillManualTaskTypes(el('plannerManualDayTaskType'), 'learn');
    const dayMinutes = el('plannerManualDayMinutes');
    if (dayMinutes) dayMinutes.value = String(state.profile?.preferred_session_minutes || 50);
    const dayTitle = el('plannerManualDayTitle');
    if (dayTitle) dayTitle.value = '';
    const weekStart = el('plannerManualWeekStart');
    if (weekStart) weekStart.value = manualWeekStart(todayIso());
    const weekRows = el('plannerManualWeekRows');
    if (weekRows) weekRows.replaceChildren();
    setManualScope(state.manualScope);
    show('plannerManualModal');
    (state.manualScope === 'day' ? dayDate : weekStart)?.focus();
  }

  function closeManualPlanner() {
    hide('plannerManualModal');
  }

  function manualEntryFromRow(row) {
    return {
      scheduled_date: row.querySelector('.planner-manual-date')?.value || '',
      subject_code: row.querySelector('.planner-manual-subject')?.value || '',
      topic_id: row.querySelector('.planner-manual-topic')?.value || null,
      task_type: row.querySelector('.planner-manual-task-type')?.value || 'learn',
      estimated_minutes: Number(row.querySelector('.planner-manual-minutes')?.value || 0),
    };
  }

  async function submitManualPlan(event) {
    event.preventDefault();
    const submit = el('plannerManualSubmit');
    const scope = state.manualScope === 'week' ? 'week' : 'day';
    let entries = [];
    if (scope === 'day') {
      entries = [{
        scheduled_date: el('plannerManualDayDate')?.value || '',
        subject_code: el('plannerManualDaySubject')?.value || '',
        topic_id: el('plannerManualDayTopic')?.value || null,
        task_type: el('plannerManualDayTaskType')?.value || 'learn',
        estimated_minutes: Number(el('plannerManualDayMinutes')?.value || 0),
        title_en: el('plannerManualDayTitle')?.value.trim() || '',
      }];
    } else {
      entries = Array.from(document.querySelectorAll('#plannerManualWeekRows .planner-manual-week-row')).map(manualEntryFromRow);
    }
    if (!entries.length || entries.some((entry) => !entry.scheduled_date || !entry.subject_code || entry.estimated_minutes < 30)) {
      setStatus('Choose a date, subject, and at least 30 minutes for every session.', 'error');
      return;
    }
    if (submit) submit.disabled = true;
    setStatus(scope === 'day' ? 'Saving your manual day plan...' : 'Saving your manual week plan...', 'loading');
    try {
      const payload = { scope, entries };
      if (scope === 'week') payload.week_start = el('plannerManualWeekStart')?.value || '';
      const response = await window.NexaPlanner.createManualPlan(payload);
      closeManualPlanner();
      await loadToday();
      if (state.activeView === 'week') await loadWeek();
      if (state.activeView === 'month') renderMonth(state.month);
      setStatus(response.message || 'Your manual plan was saved.', 'success');
    } catch (error) {
      setStatus(error.message || 'The manual plan could not be saved.', 'error');
    } finally {
      if (submit) submit.disabled = false;
    }
  }
  function setPlannerView(view) {
    const valid = ['today', 'week', 'month', 'progress'];
    state.activeView = valid.includes(view) ? view : 'today';
    document.querySelectorAll('[data-planner-view]').forEach((button) => {
      const active = button.dataset.plannerView === state.activeView;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-planner-panel]').forEach((panel) => {
      const active = panel.dataset.plannerPanel === state.activeView;
      panel.classList.toggle('hidden', !active);
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    if (state.activeView === 'week') loadWeek();
    if (state.activeView === 'month') renderMonth(state.month);
    if (state.activeView === 'progress') loadProgress();
  }

  async function loadWeek() {
    try {
      const response = await window.NexaPlanner.getWeek();
      state.week = response;
      renderWeek(response);
    } catch (error) {
      setStatus(error.message || 'The weekly plan could not be loaded.', 'error');
    }
  }

  function renderWeek(response) {
    const tasks = Array.isArray(response.tasks) ? response.tasks : [];
    const week = response.week || {};
    text('plannerWeekHeading', dateLabel(week.week_start) + ' - ' + dateLabel(week.week_end));
    text('plannerWeekSummary', String(tasks.length) + ' planned sessions. Choose up to six subjects to guide this week.');
    const selected = new Set((response.preferences || []).map((item) => String(item.code)));
    const subjectList = el('plannerWeekSubjects');
    if (subjectList) {
      subjectList.replaceChildren();
      state.subjects.forEach((subject) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'planner-subject-chip' + (selected.has(String(subject.code)) ? ' is-selected' : '');
        button.dataset.subjectCode = subject.code;
        button.setAttribute('aria-pressed', selected.has(String(subject.code)) ? 'true' : 'false');
        button.textContent = subject.name_en || subject.code;
        subjectList.appendChild(button);
      });
    }
    const target = el('plannerWeekTasks');
    if (!target) return;
    target.replaceChildren();
    const grouped = new Map();
    tasks.forEach((task) => {
      const key = task.scheduled_date;
      if (!grouped.has(key)) grouped.set(key, []);
      grouped.get(key).push(task);
    });
    if (!grouped.size) {
      const empty = document.createElement('p');
      empty.className = 'planner-empty-state';
      empty.textContent = 'No active study days in this week.';
      target.appendChild(empty);
      return;
    }
    grouped.forEach((dayTasks, date) => {
      const day = document.createElement('section');
      day.className = 'planner-week-day';
      const heading = document.createElement('div');
      heading.className = 'planner-week-day__header';
      const dayName = document.createElement('span');
      dayName.textContent = dateLabel(date, { weekday: 'long', day: 'numeric', month: 'short' });
      const minutes = dayTasks.reduce((total, task) => total + Number(task.estimated_minutes || 0), 0);
      const total = document.createElement('span');
      total.textContent = minutes + ' min';
      heading.append(dayName, total);
      day.appendChild(heading);
      const taskList = document.createElement('div');
      taskList.className = 'planner-week-day__tasks';
      dayTasks.forEach((task) => {
        const row = document.createElement('div');
        row.className = 'planner-week-task';
        const label = document.createElement('strong');
        label.textContent = task.title_en || 'Study task';
        const status = document.createElement('small');
        status.textContent = String(task.status || 'planned').replace('_', ' ');
        row.append(label, status);
        taskList.appendChild(row);
      });
      day.appendChild(taskList);
      target.appendChild(day);
    });
  }

  async function saveWeekSubjects() {
    const button = el('plannerSaveWeekSubjects');
    const selected = Array.from(document.querySelectorAll('.planner-subject-chip.is-selected')).map((item) => item.dataset.subjectCode);
    if (button) button.disabled = true;
    try {
      await window.NexaPlanner.saveWeekSubjects(todayIso(), selected);
      await loadWeek();
      setStatus('Weekly subject focus saved. Regenerate the month when you want the change reflected in future tasks.', 'success');
    } catch (error) {
      setStatus(error.message || 'Weekly subject focus could not be saved.', 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  function renderMonth(monthResponse) {
    const target = el('plannerMonthGrid');
    if (!target || !monthResponse?.month) return;
    const month = monthResponse.month;
    const year = Number(month.calendar_year);
    const monthNumber = Number(month.calendar_month);
    text('plannerMonthHeading', new Intl.DateTimeFormat('en-IN', { month: 'long', year: 'numeric' }).format(new Date(year, monthNumber - 1, 1)));
    target.replaceChildren();
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach((label) => {
      const node = document.createElement('span');
      node.className = 'planner-month-day-label';
      node.textContent = label;
      target.appendChild(node);
    });
    const firstDay = new Date(year, monthNumber - 1, 1).getDay();
    const daysInMonth = new Date(year, monthNumber, 0).getDate();
    const tasksByDate = new Map();
    (monthResponse.tasks || []).forEach((task) => {
      if (!tasksByDate.has(task.scheduled_date)) tasksByDate.set(task.scheduled_date, []);
      tasksByDate.get(task.scheduled_date).push(task);
    });
    for (let index = 0; index < firstDay; index += 1) {
      const empty = document.createElement('div');
      empty.className = 'planner-month-day is-empty';
      target.appendChild(empty);
    }
    for (let dayNumber = 1; dayNumber <= daysInMonth; dayNumber += 1) {
      const iso = year + '-' + String(monthNumber).padStart(2, '0') + '-' + String(dayNumber).padStart(2, '0');
      const tasks = tasksByDate.get(iso) || [];
      const day = document.createElement('div');
      day.className = 'planner-month-day' + (iso === todayIso() ? ' is-today' : '');
      const number = document.createElement('div');
      number.className = 'planner-month-day__number';
      const label = document.createElement('span');
      label.textContent = String(dayNumber);
      const count = document.createElement('span');
      count.className = 'planner-month-day__count';
      count.textContent = tasks.length ? String(tasks.length) : '';
      number.append(label, count);
      day.appendChild(number);
      if (tasks.length) {
        const bar = document.createElement('div');
        bar.className = 'planner-month-day__bar';
        const fill = document.createElement('span');
        fill.style.width = Math.min(100, tasks.reduce((sum, task) => sum + Number(task.estimated_minutes || 0), 0) / 3) + '%';
        bar.appendChild(fill);
        day.appendChild(bar);
        const meta = document.createElement('small');
        meta.className = 'planner-month-day__meta';
        meta.textContent = tasks.reduce((sum, task) => sum + Number(task.estimated_minutes || 0), 0) + ' min';
        day.appendChild(meta);
      }
      target.appendChild(day);
    }
  }

  async function loadProgress() {
    try {
      const response = await window.NexaPlanner.getProgress();
      renderProgress(response.items || []);
    } catch (error) {
      setStatus(error.message || 'Progress could not be loaded.', 'error');
    }
  }

  function renderProgress(items) {
    const target = el('plannerProgressList');
    if (!target) return;
    target.replaceChildren();
    if (!items.length) {
      const empty = document.createElement('p');
      empty.className = 'planner-empty-state';
      empty.textContent = 'Your progress will appear after the diagnostic or your first practice answer.';
      target.appendChild(empty);
      return;
    }
    items.forEach((item) => {
      const row = document.createElement('article');
      row.className = 'planner-progress-row';
      const top = document.createElement('div');
      top.className = 'planner-progress-row__top';
      const name = document.createElement('span');
      name.textContent = item.topic_title_en || item.topic_code || 'Topic';
      const accuracy = document.createElement('span');
      accuracy.textContent = Math.round(Number(item.recent_accuracy || 0) * 100) + '%';
      top.append(name, accuracy);
      const detail = document.createElement('p');
      detail.textContent = (item.subject_name_en || item.subject_code || 'Subject') + ' - ' + String(item.mastery_status || 'not started').replace('_', ' ') + ' - ' + String(item.attempts || 0) + ' attempts';
      const bar = document.createElement('div');
      bar.className = 'planner-progress-bar';
      const fill = document.createElement('span');
      fill.style.width = Math.round(Number(item.recent_accuracy || 0) * 100) + '%';
      bar.appendChild(fill);
      row.append(top, detail, bar);
      target.appendChild(row);
    });
  }

  function questionButton(taskId) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'planner-task-action planner-question-action';
    button.dataset.taskId = String(taskId || '');
    button.setAttribute('aria-label', 'Open practice question');
    button.append(createIcon('bookOpen'), document.createTextNode('Open'));
    return button;
  }

  function closeQuestionModal() {
    hide('plannerQuestionModal');
    state.question = null;
  }

  function renderQuestion(question) {
    const modal = el('plannerQuestionModal');
    const prompt = el('plannerQuestionPrompt');
    const meta = el('plannerQuestionMeta');
    const options = el('plannerQuestionOptions');
    const feedback = el('plannerQuestionFeedback');
    const submit = el('plannerQuestionSubmit');
    if (!modal || !prompt || !meta || !options || !feedback || !submit) return;
    meta.textContent = (question.subject_name_en || question.subject_code || 'Subject') + (question.topic_title_en ? ' - ' + question.topic_title_en : '');
    prompt.textContent = question.prompt_en || '';
    feedback.textContent = '';
    feedback.className = 'planner-question-feedback';
    options.replaceChildren();
    (question.options || []).forEach((option) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'planner-option-button';
      button.dataset.optionKey = option.option_key;
      const key = document.createElement('strong');
      key.textContent = option.option_key;
      const label = document.createElement('span');
      label.textContent = option.option_text_en || '';
      button.append(key, label);
      options.appendChild(button);
    });
    submit.disabled = false;
    show('plannerQuestionModal');
  }

  async function openPlannerQuestion(taskId) {
    if (!taskId) return;
    try {
      const response = await window.NexaPlanner.getQuestion({ task_id: taskId });
      state.question = response.question || null;
      if (!state.question) throw new Error('This practice question is no longer available.');
      renderQuestion(state.question);
    } catch (error) {
      setStatus(error.message || 'The practice question could not be loaded.', 'error');
    }
  }

  async function submitPlannerQuestion() {
    const question = state.question;
    const selected = el('plannerQuestionOptions')?.querySelector('.is-selected');
    const submit = el('plannerQuestionSubmit');
    const feedback = el('plannerQuestionFeedback');
    if (!question || !selected || !submit || !feedback) return;
    submit.disabled = true;
    try {
      const response = await window.NexaPlanner.answerProgress({
        question_id: question.id,
        task_id: question.task_id,
        option_key: selected.dataset.optionKey,
        source_type: 'planner_task',
      });
      feedback.className = 'planner-question-feedback ' + (response.is_correct ? 'is-correct' : 'is-incorrect');
      feedback.textContent = (response.is_correct ? 'Correct. ' : 'Not quite. ') + (response.explanation_en || 'Your result has been added to your progress.');
      el('plannerQuestionOptions')?.querySelectorAll('.planner-option-button').forEach((button) => { button.disabled = true; });
      await loadProgress();
    } catch (error) {
      feedback.className = 'planner-question-feedback is-incorrect';
      feedback.textContent = error.message || 'Your answer could not be saved.';
      submit.disabled = false;
    }
  }

  async function updateTaskAction(button) {
    const taskId = Number(button.dataset.taskId || 0);
    const action = button.dataset.action || '';
    if (!taskId || !action) return;
    button.disabled = true;
    try {
      await window.NexaPlanner.updateTask({ action, task_id: taskId });
      await loadToday();
      if (state.activeView === 'month') renderMonth(state.month);
    } catch (error) {
      setStatus(error.message || 'The task could not be updated.', 'error');
      button.disabled = false;
    }
  }

  async function refreshMonth() {
    const button = el('plannerRefreshBtn');
    if (button) button.disabled = true;
    try {
      state.month = await window.NexaPlanner.regenerateMonth();
      const today = await window.NexaPlanner.getDay();
      renderToday(state.month, today);
      if (state.activeView === 'week') await loadWeek();
      if (state.activeView === 'month') renderMonth(state.month);
      setStatus('Your monthly plan has been refreshed from your current preferences.', 'success');
    } catch (error) {
      setStatus(error.message || 'The monthly plan could not be refreshed.', 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  function bind() {
    el('sidebarPlannerBtn')?.addEventListener('click', openPlanner);
    el('sidebarChatBtn')?.addEventListener('click', closePlanner);
    el('plannerCloseBtn')?.addEventListener('click', closePlanner);
    el('plannerProfileForm')?.addEventListener('submit', saveProfile);
    el('plannerStartDiagnosticBtn')?.addEventListener('click', startDiagnostic);
    el('plannerRefreshBtn')?.addEventListener('click', refreshMonth);
    el('plannerManualDayBtn')?.addEventListener('click', () => openManualPlanner('day'));
    el('plannerManualWeekBtn')?.addEventListener('click', () => openManualPlanner('week'));
    el('plannerManualClose')?.addEventListener('click', closeManualPlanner);
    el('plannerManualCancel')?.addEventListener('click', closeManualPlanner);
    el('plannerManualModal')?.addEventListener('click', (event) => {
      if (event.target.dataset.plannerManualClose === 'true') closeManualPlanner();
    });
    document.querySelectorAll('[data-manual-scope]').forEach((button) => {
      button.addEventListener('click', () => setManualScope(button.dataset.manualScope));
    });
    el('plannerManualForm')?.addEventListener('submit', submitManualPlan);
    el('plannerManualAddRow')?.addEventListener('click', () => addManualWeekRow());
    el('plannerManualDaySubject')?.addEventListener('change', (event) => {
      fillManualTopics(el('plannerManualDayTopic'), event.target.value, '');
    });
    el('plannerManualWeekStart')?.addEventListener('change', (event) => {
      event.target.value = manualWeekStart(event.target.value);
    });
    el('plannerViewTabs')?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-planner-view]');
      if (button) setPlannerView(button.dataset.plannerView);
    });
    el('plannerTodayTasks')?.addEventListener('click', (event) => {
      const questionButton = event.target.closest('.planner-question-action');
      if (questionButton) {
        openPlannerQuestion(Number(questionButton.dataset.taskId || 0));
        return;
      }
      const button = event.target.closest('.planner-task-action');
      if (button) updateTaskAction(button);
    });
    el('plannerQuestionOptions')?.addEventListener('click', (event) => {
      const button = event.target.closest('.planner-option-button');
      if (!button || button.disabled) return;
      el('plannerQuestionOptions')?.querySelectorAll('.planner-option-button').forEach((option) => option.classList.remove('is-selected'));
      button.classList.add('is-selected');
    });
    el('plannerQuestionClose')?.addEventListener('click', closeQuestionModal);
    el('plannerQuestionCancel')?.addEventListener('click', closeQuestionModal);
    el('plannerQuestionSubmit')?.addEventListener('click', submitPlannerQuestion);
    el('plannerQuestionModal')?.addEventListener('click', (event) => {
      if (event.target.dataset.plannerQuestionClose === 'true') closeQuestionModal();
    });
    el('plannerWeekSubjects')?.addEventListener('click', (event) => {
      const button = event.target.closest('.planner-subject-chip');
      if (!button) return;
      const selected = button.classList.toggle('is-selected');
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    el('plannerSaveWeekSubjects')?.addEventListener('click', saveWeekSubjects);
    el('plannerDiagnosticBody')?.addEventListener('click', (event) => {
      const button = event.target.closest('.planner-option-button');
      if (button) answerDiagnostic(button);
    });
  }

  document.addEventListener('DOMContentLoaded', bind);
}());