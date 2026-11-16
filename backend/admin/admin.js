const apiBase = new URL('../api/v1/', window.location.href);
const state = { csrfToken: null, profile: null, userOffset: 0, userTotal: 0, activeSection: 'overview' };

const elements = {
  loginView: document.querySelector('#login-view'),
  adminView: document.querySelector('#admin-view'),
  loginForm: document.querySelector('#login-form'),
  loginUsername: document.querySelector('#login-username'),
  loginPassword: document.querySelector('#login-password'),
  loginMessage: document.querySelector('#login-message'),
  appMessage: document.querySelector('#app-message'),
  operatorName: document.querySelector('#operator-name'),
  operatorEmail: document.querySelector('#operator-email'),
  footerTime: document.querySelector('#footer-time'),
  metrics: document.querySelector('#metrics-grid'),
  recentRegistrations: document.querySelector('#recent-registrations'),
  overviewSystem: document.querySelector('#overview-system'),
  usersTable: document.querySelector('#users-table'),
  usersEmpty: document.querySelector('#users-empty'),
  userCount: document.querySelector('#user-count'),
  userSearch: document.querySelector('#user-search'),
  userStatus: document.querySelector('#user-status'),
  userFilters: document.querySelector('#user-filters'),
  loadMoreUsers: document.querySelector('#load-more-users'),
  systemStatus: document.querySelector('#system-status'),
  authEvents: document.querySelector('#auth-events'),
  auditList: document.querySelector('#audit-list'),
  refresh: document.querySelector('#refresh-button'),
  logout: document.querySelector('#logout-button'),
  connection: document.querySelector('#connection-state'),
  pageTitle: document.querySelector('#page-title'),
  sectionLabel: document.querySelector('#section-label'),
};

function endpoint(path) {
  return new URL(path.replace(/^\//, ''), apiBase);
}

async function request(path, options = {}) {
  const method = options.method || 'GET';
  const headers = new Headers(options.headers || {});
  if (options.body !== undefined) headers.set('content-type', 'application/json');
  if (method !== 'GET' && method !== 'HEAD' && state.csrfToken) headers.set('x-csrf-token', state.csrfToken);
  const response = await fetch(endpoint(path), {
    ...options,
    method,
    headers,
    credentials: 'same-origin',
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload || !payload.ok) {
    const error = new Error((payload && payload.error && payload.error.message) || 'No se pudo completar la operación.');
    error.code = payload && payload.error ? payload.error.code : undefined;
    throw error;
  }
  return payload;
}

function make(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function setMessage(message = '', kind = 'error') {
  elements.appMessage.textContent = message;
  elements.appMessage.style.color = kind === 'success' ? '#247a58' : '#a74641';
}

function formatDate(value, fallback = 'Sin dato') {
  if (!value) return fallback;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return fallback;
  return new Intl.DateTimeFormat('es-HN', { dateStyle: 'medium' }).format(date);
}

function formatDateTime(value, fallback = 'Sin dato') {
  if (!value) return fallback;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return fallback;
  return new Intl.DateTimeFormat('es-HN', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function initials(name) {
  const result = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
  return result || 'R';
}

function showApp(profile, csrfToken) {
  state.profile = profile;
  state.csrfToken = csrfToken;
  elements.operatorName.textContent = profile.displayName;
  elements.operatorEmail.textContent = profile.username ? '@' + profile.username : (profile.email || '');
  document.querySelector('.operator-avatar').textContent = initials(profile.displayName);
  elements.loginView.hidden = true;
  elements.adminView.hidden = false;
  elements.footerTime.textContent = new Intl.DateTimeFormat('es-HN', { dateStyle: 'medium' }).format(new Date());
  loadActiveSection();
}

function showLogin(message = '') {
  state.profile = null;
  state.csrfToken = null;
  elements.adminView.hidden = true;
  elements.loginView.hidden = false;
  elements.loginMessage.textContent = message;
}

function setConnection(online) {
  elements.connection.classList.toggle('online', online);
  elements.connection.classList.toggle('offline', !online);
  elements.connection.lastChild.textContent = online ? ' Sistema disponible' : ' No disponible';
}

function systemRows(container, system) {
  container.replaceChildren();
  const migrations = system && Array.isArray(system.migrations) && system.migrations.length ? system.migrations.join(', ') : 'Sin lectura';
  const rows = [
    ['API', system && system.api === 'available' ? 'Disponible' : 'Revisar', Boolean(system && system.api === 'available')],
    ['Base de datos', system && system.database === 'available' ? 'Conectada' : 'Revisar', Boolean(system && system.database === 'available')],
    ['Migraciones', migrations, Boolean(system && Array.isArray(system.migrations) && system.migrations.length)],
  ];
  rows.forEach(([label, value, okay]) => {
    const row = make('div', 'system-item');
    const indicator = make('i');
    const status = make('strong', okay ? '' : 'warning', value);
    status.prepend(indicator);
    row.append(make('span', '', label), status);
    container.append(row);
  });
}

function renderMetrics(metrics) {
  const cards = [
    ['Usuarios registrados', metrics.totalUsers, 'Cuentas creadas en el demo'],
    ['Cuentas activas', metrics.activeUsers, 'Con acceso permitido'],
    ['Pendientes', metrics.pendingUsers, 'En espera de activación'],
    ['Trials activos', metrics.activeTrials, 'Dentro de los siete días'],
    ['Trials vencidos', metrics.expiredTrials, 'Requieren revisión'],
    ['Restringidas', metrics.restrictedUsers, 'Bloqueadas o suspendidas'],
  ];
  elements.metrics.replaceChildren();
  cards.forEach(([label, value, note]) => {
    const card = make('article', 'metric');
    card.append(make('p', '', label), make('strong', '', String(value || 0)), make('small', '', note));
    elements.metrics.append(card);
  });
}

function renderRecent(users) {
  elements.recentRegistrations.replaceChildren();
  if (!users.length) {
    elements.recentRegistrations.append(make('p', 'empty-state', 'Todavía no hay registros recientes.'));
    return;
  }
  users.forEach((user) => {
    const row = make('div', 'registration-row');
    const account = make('div');
    account.append(make('strong', '', user.displayName), make('small', '', user.email));
    row.append(account, make('time', '', formatDate(user.createdAt)));
    elements.recentRegistrations.append(row);
  });
}

async function loadOverview() {
  const response = await request('admin/dashboard');
  const dashboard = response.data;
  renderMetrics(dashboard.metrics);
  renderRecent(dashboard.recentRegistrations || []);
  systemRows(elements.overviewSystem, dashboard.system);
  setConnection(dashboard.system && dashboard.system.database === 'available');
}

function statusClass(status) {
  return ['active', 'pending', 'locked', 'disabled'].includes(status) ? status : '';
}

function statusLabel(status) {
  const labels = { active: 'Activa', pending: 'Pendiente', locked: 'Bloqueada', disabled: 'Suspendida' };
  return labels[status] || status;
}

function accountAction(user) {
  if (user.status === 'pending') return ['activate', 'Activar', '¿Activar esta cuenta e iniciar su trial de siete días?'];
  if (user.status === 'disabled' || user.status === 'locked') return ['restore', 'Restaurar', '¿Restaurar el acceso de esta cuenta?'];
  return ['suspend', 'Suspender', '¿Suspender esta cuenta y revocar sus sesiones activas?'];
}

function userRow(user) {
  const row = document.createElement('tr');
  const account = document.createElement('td');
  account.append(make('strong', '', user.displayName), make('small', '', user.email));
  const stateCell = document.createElement('td');
  stateCell.append(make('span', 'status ' + statusClass(user.status), statusLabel(user.status)));
  const trial = make('td', '', user.trial && user.trial.endsAt ? 'Hasta ' + formatDate(user.trial.endsAt) : 'No iniciado');
  const lastLogin = make('td', '', formatDateTime(user.lastLoginAt));
  const actions = document.createElement('td');
  const actionWrap = make('div', 'row-actions');
  const actionDetails = accountAction(user);
  const action = actionDetails[0];
  const button = make('button', action === 'suspend' ? 'danger' : '', actionDetails[1]);
  button.type = 'button';
  button.addEventListener('click', () => manageAccount(user, action, actionDetails[2]));
  actionWrap.append(button);
  if (user.status === 'active') {
    const extend = make('button', '', '+ Trial');
    extend.type = 'button';
    extend.addEventListener('click', () => extendTrial(user));
    actionWrap.append(extend);
  }
  actions.append(actionWrap);
  row.append(account, stateCell, trial, lastLogin, actions);
  return row;
}

async function loadUsers(append = false) {
  const search = elements.userSearch.value.trim();
  const status = elements.userStatus.value;
  const offset = append ? state.userOffset : 0;
  const query = new URLSearchParams({ limit: '25', offset: String(offset) });
  if (search) query.set('search', search);
  if (status) query.set('status', status);
  const response = await request('admin/users?' + query.toString());
  const users = response.data || [];
  const total = Number((response.meta && response.meta.total) || 0);
  if (!append) elements.usersTable.replaceChildren();
  users.forEach((user) => elements.usersTable.append(userRow(user)));
  state.userOffset = offset + users.length;
  state.userTotal = total;
  elements.usersEmpty.hidden = Boolean(users.length || append);
  elements.userCount.textContent = total === 1 ? '1 cuenta' : String(total) + ' cuentas';
  elements.loadMoreUsers.hidden = state.userOffset >= total;
}

async function manageAccount(user, action, confirmation) {
  if (!window.confirm(confirmation)) return;
  try {
    await request('admin/users/' + encodeURIComponent(user.id) + '/account', { method: 'POST', body: { action } });
    setMessage('La acción administrativa se registró correctamente.', 'success');
    await Promise.all([loadUsers(), loadOverview()]);
  } catch (error) {
    setMessage(error.message);
  }
}

async function extendTrial(user) {
  const raw = window.prompt('Días extra de trial para ' + user.displayName + ' (1–30):', '7');
  if (raw === null) return;
  const days = Number(raw);
  if (!Number.isInteger(days) || days < 1 || days > 30) {
    setMessage('Ingresá un número entero entre 1 y 30.');
    return;
  }
  try {
    await request('admin/users/' + encodeURIComponent(user.id) + '/account', {
      method: 'POST',
      body: { action: 'extend-trial', days },
    });
    setMessage('El trial se extendió y la acción quedó en la bitácora.', 'success');
    await Promise.all([loadUsers(), loadOverview()]);
  } catch (error) {
    setMessage(error.message);
  }
}

function renderEvents(events) {
  elements.authEvents.replaceChildren();
  if (!events.length) {
    elements.authEvents.append(make('p', 'empty-state', 'No hay eventos de autenticación registrados.'));
    return;
  }
  events.forEach((event) => {
    const row = make('div', 'event-row');
    const details = make('div');
    details.append(make('strong', '', event.type === 'login-success' ? 'Inicio de sesión validado' : 'Intento de inicio rechazado'));
    details.append(make('small', '', 'Identificadores y secretos protegidos'));
    row.append(details, make('time', '', formatDateTime(event.occurredAt)));
    elements.authEvents.append(row);
  });
}

function renderAudit(logs) {
  elements.auditList.replaceChildren();
  if (!logs.length) {
    elements.auditList.append(make('p', 'empty-state', 'Aún no hay acciones administrativas registradas.'));
    return;
  }
  logs.forEach((log) => {
    const row = make('div', 'audit-row');
    const details = make('div');
    details.append(make('strong', '', log.action));
    details.append(make('small', '', 'Solicitud ' + log.requestId.slice(0, 12) + '…'));
    row.append(details, make('time', '', formatDateTime(log.createdAt)));
    elements.auditList.append(row);
  });
}

async function loadMonitoring() {
  const result = await Promise.all([request('admin/system'), request('admin/auth-events?limit=20')]);
  systemRows(elements.systemStatus, result[0].data);
  renderEvents(result[1].data || []);
  setConnection(result[0].data && result[0].data.database === 'available');
}

async function loadAudit() {
  const response = await request('admin/audit-logs?limit=30');
  renderAudit(response.data || []);
}

const labels = {
  overview: ['Pulso operativo', 'VISTA GENERAL'],
  users: ['Cuentas personales', 'CUENTAS'],
  monitoring: ['Monitoreo del demo', 'MONITOREO'],
  audit: ['Bitácora administrativa', 'AUDITORÍA'],
};

async function loadActiveSection() {
  const section = state.activeSection;
  document.querySelectorAll('.section').forEach((node) => {
    const active = node.id === 'section-' + section;
    node.hidden = !active;
    node.classList.toggle('active', active);
  });
  document.querySelectorAll('.side-link[data-section]').forEach((node) => {
    node.classList.toggle('active', node.dataset.section === section);
  });
  elements.pageTitle.textContent = labels[section][0];
  elements.sectionLabel.textContent = labels[section][1];
  setMessage('');
  try {
    if (section === 'overview') await loadOverview();
    if (section === 'users') await loadUsers();
    if (section === 'monitoring') await loadMonitoring();
    if (section === 'audit') await loadAudit();
  } catch (error) {
    setConnection(false);
    setMessage(error.message);
  }
}

async function authenticateExistingSession() {
  try {
    const response = await request('auth/session');
    const profile = response.data.profile;
    if (!profile.roles || !profile.roles.includes('admin')) {
      state.csrfToken = response.data.csrfToken;
      await request('auth/logout', { method: 'POST', body: {} });
      showLogin('Esta cuenta no tiene acceso administrativo.');
      return;
    }
    showApp(profile, response.data.csrfToken);
  } catch {
    showLogin();
  }
}

elements.loginForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  elements.loginMessage.textContent = '';
  try {
    const response = await request('admin/auth/login', {
      method: 'POST',
      body: { username: elements.loginUsername.value, password: elements.loginPassword.value },
    });
    if (!response.data.profile.roles || !response.data.profile.roles.includes('admin')) {
      state.csrfToken = response.data.csrfToken;
      await request('auth/logout', { method: 'POST', body: {} });
      throw new Error('Esta cuenta no tiene acceso administrativo.');
    }
    elements.loginPassword.value = '';
    showApp(response.data.profile, response.data.csrfToken);
  } catch (error) {
    elements.loginMessage.textContent = error.message;
  }
});

elements.logout.addEventListener('click', async () => {
  try {
    await request('auth/logout', { method: 'POST', body: {} });
  } catch {
    // The interface returns to the login state even if the session has expired.
  }
  showLogin('Sesión cerrada.');
});

elements.refresh.addEventListener('click', () => loadActiveSection());
elements.userFilters.addEventListener('submit', (event) => {
  event.preventDefault();
  loadUsers().catch((error) => setMessage(error.message));
});
elements.loadMoreUsers.addEventListener('click', () => loadUsers(true).catch((error) => setMessage(error.message)));
document.querySelectorAll('[data-section]').forEach((button) => {
  button.addEventListener('click', () => {
    state.activeSection = button.dataset.section;
    loadActiveSection();
  });
});
document.querySelectorAll('[data-go-section]').forEach((button) => {
  button.addEventListener('click', () => {
    state.activeSection = button.dataset.goSection;
    loadActiveSection();
  });
});

authenticateExistingSession();
