/* Studious — static frontend (vanilla JS, hash routing). Talks to backend/api.php over fetch. */
(() => {
'use strict';

const CFG = window.STUDIOUS_CONFIG || {};
const API = String(CFG.API_BASE_URL || '').replace(/\/+$/, '');
const APP = CFG.APP_NAME || 'Studious';
const CURRENCY = CFG.CURRENCY || 'NGN';
const LEVELS = ['100', '200', '300', '400', '500', '600', 'Postgraduate'];

/* ---------- helpers ---------- */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const nl2br = (s) => esc(s).replace(/\n/g, '<br>');
const money = (n) => (CURRENCY === 'NGN' ? '₦' : CURRENCY + ' ') + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).replace(' ', 'T')); return isNaN(t) ? esc(d) : t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); };
const options = (list, sel, placeholder) => (placeholder ? `<option value="">${esc(placeholder)}</option>` : '') + list.map((v) => `<option ${String(v) === String(sel) ? 'selected' : ''}>${esc(v)}</option>`).join('');
const loading = () => '<div class="loading"><div class="spinner"></div>Loading…</div>';
const empty = (icon, msg) => `<div class="empty"><b>${icon}</b>${esc(msg)}</div>`;

const session = {
  get token() { try { return localStorage.getItem('studious.token') || ''; } catch { return ''; } },
  get user() { try { return JSON.parse(localStorage.getItem('studious.user') || 'null'); } catch { return null; } },
  set(token, user) { try { localStorage.setItem('studious.token', token); localStorage.setItem('studious.user', JSON.stringify(user)); } catch { /* storage blocked */ } },
  clear() { try { localStorage.removeItem('studious.token'); localStorage.removeItem('studious.user'); } catch { /* ignore */ } },
};

function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = 'toast ' + (type === 'err' ? 'err' : '');
  el.textContent = msg;
  $('#toasts').appendChild(el);
  setTimeout(() => el.remove(), 4500);
}

async function api(path, opts = {}) {
  const headers = { Accept: 'application/json' };
  if (session.token) headers.Authorization = 'Bearer ' + session.token;
  let body;
  if (opts.form) body = opts.form;
  else if (opts.body !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(opts.body); }
  let res;
  try { res = await fetch(API + path, { method: opts.method || 'GET', headers, body }); }
  catch { throw new Error('Cannot reach the server. Check your connection and try again.'); }
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON */ }
  if (res.status === 401 && session.token && !opts.quiet401) {
    session.clear();
    go('/login');
    throw new Error((data && data.error) || 'Please sign in again.');
  }
  if (!res.ok || !data || data.ok === false) {
    throw new Error((data && data.error) || `Request failed (${res.status}).`);
  }
  return data;
}
const qs = (obj) => { const p = new URLSearchParams(); Object.entries(obj).forEach(([k, v]) => { if (v !== undefined && v !== null) p.set(k, v); }); const s = p.toString(); return s ? '?' + s : ''; };

/* ---------- router ---------- */
function go(path) { location.hash = '#' + path; }
function parseHash() {
  const h = location.hash.replace(/^#/, '') || '/';
  const [path, query] = h.split('?');
  return { path: path || '/', query: new URLSearchParams(query || '') };
}

const ROUTES = {
  '/': { render: pageHome },
  '/login': { render: pageLogin },
  '/register': { render: pageRegister },
  '/explore': { render: pageExplore },
  '/dashboard': { render: pageDashboard, auth: true },
  '/questions': { render: pageQuestions, auth: true },
  '/materials': { render: pageMaterials, auth: true },
  '/plans': { render: pagePlans, auth: true },
  '/payments': { render: pagePayments, auth: true },
  '/payment/callback': { render: pagePaymentCallback, auth: true },
  '/admin': { render: adminOverview, auth: true, admin: true },
  '/admin/questions': { render: adminQuestions, auth: true, admin: true, after: bindDeptFilters },
  '/admin/materials': { render: adminMaterials, auth: true, admin: true, after: bindDeptFilters },
  '/admin/payments': { render: adminPayments, auth: true, admin: true },
  '/admin/plans': { render: adminPlans, auth: true, admin: true },
  '/admin/institutions': { render: adminInstitutions, auth: true, admin: true },
  '/admin/users': { render: adminUsers, auth: true, admin: true },
};

let navToken = 0;
async function route() {
  const { path, query } = parseHash();
  const def = ROUTES[path];
  const user = session.user;
  renderChrome(path);
  const view = $('#view');
  if (!def) { view.innerHTML = empty('🧭', 'Page not found.') + '<p style="text-align:center"><a class="btn" href="#/">Go home</a></p>'; return; }
  if (def.auth && !session.token) { go('/login'); return; }
  if (def.admin && (!user || user.role !== 'admin')) { view.innerHTML = empty('🚫', 'Administrator access required.'); return; }
  if ((path === '/' || path === '/login' || path === '/register') && session.token) { go('/dashboard'); return; }
  const mine = ++navToken;
  view.innerHTML = loading();
  try {
    const html = await def.render(query);
    if (mine !== navToken) return;
    view.innerHTML = html;
    if (def.after) def.after(query);
    window.scrollTo(0, 0);
  } catch (e) {
    if (mine !== navToken) return;
    view.innerHTML = `<div class="card"><h3>Something went wrong</h3><p class="s">${esc(e.message)}</p><button class="btn sec" data-act="reload">Try again</button></div>`;
  }
}

function renderChrome(path) {
  const u = session.user;
  const link = (href, label) => `<a href="#${href}" class="${path === href ? 'on' : ''}">${label}</a>`;
  $('#hdr').innerHTML = `
    <a class="brand" href="#/"><span class="brand-mark">S</span><span>${esc(APP)}<small>Campus learning library</small></span></a>
    <button id="menuBtn" aria-label="Open menu" data-act="menu">☰</button>
    <div class="header-links" id="hdrLinks">
      ${link('/explore', 'Explore')}
      ${u ? link('/dashboard', 'Dashboard') + (u.role === 'admin' ? link('/admin', 'Admin') : '') + `<button class="linklike" data-act="logout">Sign out</button>`
          : link('/login', 'Sign in') + '<a class="mini-cta" href="#/register">Join</a>'}
    </div>`;
  $('#ftr').innerHTML = `<span>© ${new Date().getFullYear()} ${esc(APP)}</span><span>Learn with confidence.</span>`;
  document.title = APP;
}

/* ---------- public pages ---------- */
async function pageHome() {
  return `
  <section class="hero card">
    <span class="eyebrow">YOUR CAMPUS LIBRARY, ONLINE</span>
    <h2>Past questions.<br><em>Better preparation.</em></h2>
    <p>Find verified past examination questions and study materials organised around your school, department, level and curriculum.</p>
    <div class="hero-actions"><a class="btn" href="#/register">Create account</a><a class="btn sec" href="#/login">Sign in</a></div>
    <div class="hero-stats"><div><b>Past questions</b><small>With answer explanations</small></div><div><b>Study materials</b><small>PDFs, notes and images</small></div></div>
  </section>
  <section class="section-head"><div><span class="eyebrow">HOW IT WORKS</span><h2>Study with direction</h2></div></section>
  <div class="feature-grid">
    <article class="card"><div class="ic">🎯</div><h3>Curriculum-aware</h3><p class="s">Browse by institution, department, level, course and curriculum version.</p></article>
    <article class="card"><div class="ic">📝</div><h3>Learn the why</h3><p class="s">Questions can include answers, worked solutions and extra notes.</p></article>
    <article class="card"><div class="ic">🔓</div><h3>Flexible access</h3><p class="s">Pay securely with Paystack and unlock everything for the period you choose.</p></article>
  </div>`;
}

async function pageLogin() {
  return `<div class="form-wrap card"><span class="eyebrow">WELCOME BACK</span><h1>Sign in</h1><p class="muted">Continue your preparation.</p>
  <form data-form="login"><label>Email address</label><input type="email" name="email" required autocomplete="email">
  <label>Password</label><input type="password" name="password" required autocomplete="current-password">
  <button class="btn full">Sign in</button></form>
  <p class="auth-note">New here? <a href="#/register">Create an account</a></p></div>`;
}

async function pageRegister() {
  const [s, d] = await Promise.all([api('/schools'), api('/departments')]);
  window.__deps = d.data;
  return `<div class="form-wrap card"><span class="eyebrow">GET STARTED</span><h1>Create your account</h1><p class="muted">Set up your academic profile to find relevant resources.</p>
  <form data-form="register">
    <label>Full name</label><input name="name" required autocomplete="name">
    <label>Email address</label><input type="email" name="email" required autocomplete="email">
    <label>Password (at least 8 characters)</label><input type="password" name="password" required minlength="8" autocomplete="new-password">
    <label>Institution</label><select name="school_id" required><option value="">Choose institution</option>${s.data.map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select>
    <label>Department</label><select name="department_id" required><option value="">Choose department</option>${d.data.map((x) => `<option value="${x.id}" data-school="${x.school_id}">${esc(x.name)}</option>`).join('')}</select>
    <label>Current level</label><select name="level" required>${options(LEVELS, '', 'Choose level')}</select>
    <button class="btn full">Create account</button></form>
  <p class="auth-note">Already registered? <a href="#/login">Sign in</a></p></div>`;
}
ROUTES['/register'].after = bindDeptFilters;

async function pageExplore(query) {
  const school = query.get('school_id') || '';
  const term = query.get('q') || '';
  const [s, c] = await Promise.all([api('/schools'), api('/catalog' + qs({ school_id: school, q: term }))]);
  const authed = !!session.token;
  return `
  <div class="section-head"><div><span class="eyebrow">THE LIBRARY</span><h2>Explore resources</h2><p class="muted">Find questions by course, institution and level.</p></div></div>
  <form class="toolbar" data-form="explore"><input name="q" placeholder="Search course or title…" value="${esc(term)}">
    <select name="school_id"><option value="">All institutions</option>${s.data.map((x) => `<option value="${x.id}" ${String(x.id) === school ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>
    <button class="btn">Search</button></form>
  <section class="card"><h3>Past question previews</h3>
  ${c.data.length ? c.data.map((i) => `<div class="list-item"><div class="ic">📝</div><div class="grow"><h3>${esc(i.course_code)} · ${esc(i.title)}</h3><p>${esc(i.school)} · ${esc(i.department)} · Level ${esc(i.level)} · ${esc(i.exam_year)}</p></div>
    <a class="btn sm" href="#/${authed ? 'questions?q=' + encodeURIComponent(i.title) : 'login'}">Open</a></div>`).join('') : empty('🔎', 'No matching published questions yet.')}
  </section>`;
}

/* ---------- student pages ---------- */
async function pageDashboard() {
  const d = await api('/dashboard');
  session.set(session.token, d.user);
  const u = d.user;
  return `
  <div class="section-head"><div><span class="eyebrow">YOUR STUDY SPACE</span><h2>Hello, ${esc((u.name || '').split(' ')[0])} 👋</h2><p class="muted">Pick up where your preparation takes you.</p></div></div>
  <div class="stats">
    <div class="card stat"><b>${d.counts.questions}</b><span>Published questions</span></div>
    <div class="card stat"><b>${d.counts.materials}</b><span>Study materials</span></div>
    <div class="card stat"><b>${d.premium ? '✓' : '—'}</b><span>Premium access</span></div>
    <div class="card stat"><b>${esc(u.level || '—')}</b><span>Your level</span></div>
  </div>
  <div class="grid-2">
    <section class="card"><div class="ic">📚</div><h3>Past questions</h3><p class="s">Practice previous exam questions, check answers and read explanations.</p><a class="btn" href="#/questions">Browse questions</a></section>
    <section class="card"><div class="ic">📖</div><h3>Study materials</h3><p class="s">Find lecture notes, guides, PDFs and other course resources.</p><a class="btn sec" href="#/materials">Browse materials</a></section>
  </div>
  <section class="card"><div class="row"><div class="grow"><h3>Access status</h3>
    <p class="s">${d.premium
      ? (u.role === 'admin' ? 'Administrator accounts have full access.' : `Your ${esc(d.subscription?.plan || 'subscription')} is active until ${fmtDate(d.subscription?.ends_at)}.`)
      : 'Subscribe to unlock full question answers, explanations and downloadable study materials.'}</p></div>
    ${u.role === 'admin' ? '' : `<a class="btn ${d.premium ? 'sec' : ''}" href="#/plans">${d.premium ? 'Extend access' : 'See plans'}</a>`}</div></section>
  ${u.role === 'admin' ? '<section class="card"><h3>Administration</h3><p class="s">Manage institutions, resources, plans and payments.</p><a class="btn" href="#/admin">Open admin panel</a></section>' : ''}`;
}

async function pageQuestions(query) {
  const u = session.user || {};
  const level = query.has('level') ? query.get('level') : (u.role === 'admin' ? '' : u.level || '');
  const term = query.get('q') || '';
  const course = query.get('course') || '';
  const page = Math.max(1, parseInt(query.get('page') || '1', 10));
  const r = await api('/questions' + qs({ q: term, course, level, page }));
  const pages = Math.max(1, Math.ceil(r.total / r.limit));
  const pageLink = (p) => `#/questions${qs({ q: term, course, level, page: p })}`;
  return `
  <div class="section-head"><div><span class="eyebrow">PRACTICE &amp; UNDERSTAND</span><h2>Past questions</h2><p class="muted">${level ? 'Level ' + esc(level) : 'All levels'} · ${r.total} found</p></div></div>
  <form class="toolbar" data-form="questions"><input name="q" placeholder="Search question or title" value="${esc(term)}"><input name="course" placeholder="Course code e.g. CSC201" value="${esc(course)}">
    <select name="level">${options(LEVELS, level, 'All levels')}</select><button class="btn">Filter</button></form>
  ${r.premium ? '' : `<div class="card"><div class="row"><div class="ic">🔐</div><div class="grow"><h3>Unlock full explanations</h3><p class="s">Question previews are free, but answers, notes and full practice need an active subscription.</p></div><a class="btn" href="#/plans">Subscribe</a></div></div>`}
  <section class="card">${r.data.length ? r.data.map((q, i) => `
    <article class="question">
      <span class="pill">${esc(q.course_code)}</span> <span class="pill">${esc(q.exam_year)}</span>
      <h3>${(page - 1) * r.limit + i + 1}. ${esc(q.title)}</h3>
      <p class="s">${esc(q.school)} · ${esc(q.department)} · Level ${esc(q.level)} · ${esc(q.curriculum_version)}</p>
      <p>${nl2br(q.question_text)}</p>
      ${q.image_url ? `<img class="file-preview" src="${esc(q.image_url)}" alt="Question illustration" loading="lazy">` : ''}
      ${q.options ? Object.entries(q.options).map(([k, v]) => `<div class="option"><b>${esc(k)}.</b> ${esc(v)}</div>`).join('') : ''}
      ${r.premium
        ? `<button class="btn sec sm" data-act="reveal" data-target="ans${q.id}">Show answer &amp; notes</button>
           <div class="answer-box" id="ans${q.id}" hidden><h4>Correct answer</h4><p>${nl2br(q.correct_answer || 'Not supplied')}</p><h4>Explanation / additional notes</h4><p>${nl2br(q.explanation || 'No additional notes supplied.')}</p></div>`
        : '<p class="s">🔒 Answer and explanation available with a subscription.</p>'}
    </article>`).join('') : empty('📚', 'No questions found for these filters.')}</section>
  ${pages > 1 ? `<div class="pager">${page > 1 ? `<a class="btn sec sm" href="${pageLink(page - 1)}">← Prev</a>` : ''}<span class="s">Page ${page} of ${pages}</span>${page < pages ? `<a class="btn sec sm" href="${pageLink(page + 1)}">Next →</a>` : ''}</div>` : ''}`;
}

async function pageMaterials() {
  const r = await api('/materials');
  return `
  <div class="section-head"><div><span class="eyebrow">READ, REVIEW, REVISE</span><h2>Study materials</h2><p class="muted">Lecture notes, summaries, guides and course resources.</p></div></div>
  ${r.premium ? '' : `<div class="card"><div class="row"><div class="ic">🔐</div><div class="grow"><h3>Premium resource library</h3><p class="s">Subscribe to download or open full study materials.</p></div><a class="btn" href="#/plans">See plans</a></div></div>`}
  <section class="card">${r.data.length ? r.data.map((m) => `
    <div class="list-item"><div class="ic">📄</div><div class="grow"><h3>${esc(m.title)}</h3>
      <p>${esc(m.course_code)} · ${esc(m.department)} · ${esc(m.file_type)} · ${(m.file_size / 1048576).toFixed(1)} MB</p><p>${esc(m.description)}</p></div>
      ${m.download_url ? `<a class="btn sm" href="${esc(m.download_url)}" target="_blank" rel="noopener">Open</a>` : '<span class="pill">Locked</span>'}</div>`).join('') : empty('📖', 'No materials published for your institution yet.')}</section>`;
}

async function pagePlans() {
  const r = await api('/plans');
  const u = session.user || {};
  return `
  <div class="section-head"><div><span class="eyebrow">SIMPLE, FLEXIBLE ACCESS</span><h2>Choose your plan</h2><p class="muted">Pay once with Paystack — card, bank transfer, USSD or bank. Access starts the moment payment is confirmed.</p></div></div>
  ${u.role === 'admin' ? '<p class="flash">Administrator accounts already have full access.</p>' : ''}
  <div class="feature-grid">${r.data.map((p) => `
    <article class="card"><div class="ic">✨</div><h3>${esc(p.name)}</h3><div class="price">${money(p.price)}</div>
      <p class="s">${p.duration_days} days of access</p><p>${esc(p.description)}</p>
      <button class="btn full" data-act="pay" data-plan="${p.id}" ${u.role === 'admin' ? 'disabled' : ''}>Pay with Paystack</button></article>`).join('')}</div>
  <section class="card"><h3>Secure checkout</h3><p class="s">Payments are processed by Paystack. We never see or store your card details, and access is only granted after the payment is verified directly with Paystack. Buying again while subscribed adds time to your current plan.</p></section>`;
}

async function pagePayments() {
  const r = await api('/payments');
  return `
  <div class="section-head"><div><span class="eyebrow">ACCOUNT</span><h2>Payments</h2></div><a class="btn" href="#/plans">View plans</a></div>
  <section class="card">${r.data.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>Reference</th><th>Plan</th><th>Amount</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>
    ${r.data.map((p) => `<tr><td>${esc(p.reference)}</td><td>${esc(p.plan_name)}</td><td>${money(p.amount)}</td><td><span class="pill ${esc(p.status)}">${esc(p.status)}</span></td><td>${fmtDate(p.created_at)}</td>
      <td>${p.status === 'pending' ? `<button class="btn sm sec" data-act="verify" data-ref="${esc(p.reference)}">Check status</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : empty('💳', 'No payments yet.')}</section>
  <section class="card"><h3>Paid but no access?</h3><p class="s">Press “Check status” next to the payment — we confirm it directly with Paystack. If it still doesn't show, contact support with your payment reference.</p></section>`;
}

async function pagePaymentCallback(query) {
  const ref = query.get('reference') || query.get('trxref');
  if (!ref) return empty('⚠️', 'Missing payment reference.');
  const r = await api('/payments/verify' + qs({ reference: ref }));
  const p = r.payment;
  const icon = p.status === 'success' ? '✅' : p.status === 'pending' ? '⏳' : '❌';
  const title = p.status === 'success' ? 'Payment confirmed' : p.status === 'pending' ? 'Payment not completed yet' : 'Payment failed';
  return `<div class="card result"><div class="big">${icon}</div><h2>${title}</h2><p class="s">${esc(p.message || '')}</p>
    <p class="s">Reference: ${esc(ref)}</p>
    <p><a class="btn" href="#/${p.status === 'success' ? 'dashboard' : 'plans'}">${p.status === 'success' ? 'Go to dashboard' : 'Back to plans'}</a> <a class="btn sec" href="#/payments">My payments</a></p></div>`;
}

/* ---------- admin pages ---------- */
function adminNav(active) {
  const items = [['/admin', 'Overview'], ['/admin/questions', 'Questions'], ['/admin/materials', 'Materials'], ['/admin/payments', 'Payments'], ['/admin/plans', 'Plans'], ['/admin/institutions', 'Institutions'], ['/admin/users', 'Users']];
  return `<nav class="admin-nav">${items.map(([h, l]) => `<a href="#${h}" class="${h === active ? 'on' : ''}">${l}</a>`).join('')}</nav>`;
}
const schoolOptions = (schools) => schools.filter((s) => s.is_active).map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
const deptOptions = (depts) => depts.filter((d) => d.is_active).map((d) => `<option value="${d.id}" data-school="${d.school_id}">${esc(d.name)}</option>`).join('');
const toggleBtn = (kind, id) => `<button class="btn sm sec" data-act="toggle" data-kind="${kind}" data-id="${id}">Toggle</button>`;

async function adminOverview() {
  const o = await api('/admin/overview');
  const stat = (n, l) => `<div class="card stat"><b>${n}</b><span>${l}</span></div>`;
  return `<div class="section-head"><div><span class="eyebrow">LIBRARY CONTROL CENTRE</span><h2>Administration</h2><p class="muted">Manage content, plans and payments.</p></div></div>${adminNav('/admin')}
  <div class="stats">${stat(o.students, 'Students')}${stat(o.questions, 'Questions')}${stat(o.materials, 'Materials')}${stat(o.active_subscriptions, 'Active subscriptions')}</div>
  <div class="stats">${stat(money(o.revenue), 'Revenue (Paystack)')}${stat(o.payments, 'Payments')}${stat(o.pending, 'Pending payments')}</div>
  <div class="card"><h3>Needs attention</h3><p>${o.pending} pending payment(s). Pending payments are confirmed automatically by the Paystack webhook; you can also re-check them manually.</p><a class="btn" href="#/admin/payments">Review payments</a></div>
  <div class="card"><h3>CSV question import</h3><p class="s">Bulk-add questions from the Questions section using the sample template.</p><a class="btn sec" href="#/admin/questions">Manage question bank</a></div>`;
}

async function adminQuestions() {
  const [t, q] = await Promise.all([api('/admin/taxonomy'), api('/admin/questions')]);
  return `<div class="section-head"><div><span class="eyebrow">CONTENT MANAGEMENT</span><h2>Past questions</h2></div></div>${adminNav('/admin/questions')}
  <div class="grid-2"><section class="card"><h3>Add a question</h3>
  <form data-form="admin-question">
    <label>Institution</label><select name="school_id" required>${schoolOptions(t.schools)}</select>
    <label>Department</label><select name="department_id" required>${deptOptions(t.departments)}</select>
    <div class="grid-2"><div><label>Level</label><select name="level">${options(LEVELS)}</select></div><div><label>Exam year</label><input type="number" name="exam_year" min="1950" max="2100" value="${new Date().getFullYear()}" required></div></div>
    <label>Curriculum version</label><input name="curriculum_version" placeholder="e.g. 2024/2025 curriculum" required>
    <label>Course code</label><input name="course_code" placeholder="CSC201" required>
    <label>Question title / topic</label><input name="title" required>
    <label>Question text</label><textarea name="question_text" required></textarea>
    <label>Options JSON (optional)</label><textarea name="options_json" placeholder='{"A":"First option","B":"Second option"}'></textarea>
    <label>Correct answer</label><textarea name="correct_answer"></textarea>
    <label>Explanation / additional notes</label><textarea name="explanation"></textarea>
    <label>Question image (optional, max 20 MB)</label><input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
    <label class="checkrow"><input type="checkbox" name="is_published" value="1" checked> Publish immediately</label>
    <button class="btn full">Save question</button></form></section>
  <section class="card"><h3>Import questions from CSV</h3>
    <p class="s">Columns: school (name) or school_id, department (name) or department_id, level, curriculum_version, course_code, title, exam_year, question_text, options_json, correct_answer, explanation. Save as UTF-8. Up to 2,000 rows.</p>
    <p><a href="assets/samples/sample-questions.csv" download>Download CSV template</a></p>
    <form data-form="admin-import"><label>CSV file</label><input type="file" name="csv" accept=".csv,text/csv" required>
    <label class="checkrow"><input type="checkbox" name="publish_import" value="1"> Publish imported rows immediately</label><button class="btn full">Import CSV</button></form></section></div>
  <section class="card"><h3>Recent questions</h3><div class="table-wrap"><table class="table"><thead><tr><th>Course</th><th>Title</th><th>Institution / department</th><th>Level</th><th>Curriculum</th><th>Status</th><th></th></tr></thead><tbody>
  ${q.data.map((x) => `<tr><td>${esc(x.course_code)}</td><td>${esc(x.title)}</td><td>${esc(x.school)} / ${esc(x.department)}</td><td>${esc(x.level)}</td><td>${esc(x.curriculum_version)}</td><td><span class="pill ${x.is_published ? 'success' : 'draft'}">${x.is_published ? 'Published' : 'Draft'}</span></td><td>${toggleBtn('questions', x.id)}</td></tr>`).join('')}
  </tbody></table></div></section>`;
}

async function adminMaterials() {
  const [t, m] = await Promise.all([api('/admin/taxonomy'), api('/admin/materials')]);
  return `<div class="section-head"><div><span class="eyebrow">RESOURCE MANAGEMENT</span><h2>Study materials</h2></div></div>${adminNav('/admin/materials')}
  <div class="grid-2"><section class="card"><h3>Upload a resource</h3>
  <form data-form="admin-material">
    <label>Institution</label><select name="school_id" required>${schoolOptions(t.schools)}</select>
    <label>Department</label><select name="department_id" required>${deptOptions(t.departments)}</select>
    <label>Level</label><select name="level">${options([...LEVELS, 'All levels'])}</select>
    <label>Curriculum version</label><input name="curriculum_version" required placeholder="2024/2025 curriculum">
    <label>Course code</label><input name="course_code" required><label>Title</label><input name="title" required>
    <label>Description</label><textarea name="description"></textarea>
    <label>File (PDF, DOC/DOCX, TXT or image; max 20 MB)</label><input type="file" name="material" required accept=".pdf,.doc,.docx,.txt,.png,.jpg,.jpeg,.webp">
    <label class="checkrow"><input type="checkbox" name="is_published" value="1" checked> Publish immediately</label>
    <button class="btn full">Upload material</button></form></section>
  <section class="card"><h3>File storage</h3><p class="s">With Google Drive credentials configured on the server, files are stored privately in Drive. Without them (local XAMPP) files are saved in <code>backend/uploads</code>. Students open files through short-lived signed links, only while their subscription is active.</p></section></div>
  <section class="card"><h3>Recent materials</h3><div class="table-wrap"><table class="table"><thead><tr><th>Title</th><th>Course</th><th>Institution / department</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody>
  ${m.data.map((x) => `<tr><td>${esc(x.title)}</td><td>${esc(x.course_code)}</td><td>${esc(x.school)} / ${esc(x.department)}</td><td>${esc(x.file_type)}</td><td><span class="pill ${x.is_published ? 'success' : 'draft'}">${x.is_published ? 'Published' : 'Draft'}</span></td><td>${toggleBtn('materials', x.id)}</td></tr>`).join('')}
  </tbody></table></div></section>`;
}

async function adminPayments() {
  const r = await api('/admin/payments');
  return `<div class="section-head"><div><span class="eyebrow">BILLING</span><h2>Payments</h2></div></div>${adminNav('/admin/payments')}
  <section class="card"><p class="s">Payments are confirmed automatically through Paystack. “Re-check” asks Paystack for the latest status of a pending payment.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Student</th><th>Plan</th><th>Amount</th><th>Reference</th><th>Channel</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
  ${r.data.map((p) => `<tr><td>${esc(p.user_name)}<br><small>${esc(p.email)}</small></td><td>${esc(p.plan_name)}</td><td>${money(p.amount)}</td><td>${esc(p.reference)}</td><td>${esc(p.channel || '—')}</td>
    <td><span class="pill ${esc(p.status)}">${esc(p.status)}</span></td><td>${fmtDate(p.created_at)}</td>
    <td>${p.status === 'pending' ? `<button class="btn sm" data-act="admin-verify" data-id="${p.id}">Re-check</button>` : ''}</td></tr>`).join('')}
  </tbody></table></div></section>`;
}

async function adminPlans() {
  const r = await api('/admin/plans');
  return `<div class="section-head"><div><span class="eyebrow">SUBSCRIPTION SETUP</span><h2>Plans</h2></div></div>${adminNav('/admin/plans')}
  <div class="grid-2"><section class="card"><h3>Create plan</h3><form data-form="admin-plan">
    <label>Name</label><input name="name" required placeholder="Monthly access"><label>Description</label><textarea name="description"></textarea>
    <label>Price (${esc(CURRENCY)})</label><input type="number" min="1" step="0.01" name="price" required>
    <label>Duration in days</label><input type="number" min="1" name="duration_days" value="30" required>
    <label class="checkrow"><input type="checkbox" name="is_active" value="1" checked> Active</label><button class="btn full">Create plan</button></form></section>
  <section class="card"><h3>Available plans</h3>${r.data.map((p) => `<div class="list-item"><div class="grow"><h3>${esc(p.name)} · ${money(p.price)}</h3><p>${p.duration_days} days · ${p.is_active ? 'Active' : 'Inactive'}</p></div>${toggleBtn('plans', p.id)}</div>`).join('')}</section></div>`;
}

async function adminInstitutions() {
  const t = await api('/admin/taxonomy');
  return `<div class="section-head"><div><span class="eyebrow">ACADEMIC TAXONOMY</span><h2>Institutions &amp; departments</h2></div></div>${adminNav('/admin/institutions')}
  <div class="grid-2"><section class="card"><h3>Add institution</h3><form data-form="admin-school"><label>Institution name</label><input name="name" required><label>Short name</label><input name="short_name" placeholder="e.g. UNILAG"><button class="btn full">Add institution</button></form>
  <hr class="divider"><h3>Institutions</h3>${t.schools.map((s) => `<div class="list-item"><div class="grow"><h3>${esc(s.name)} <small>(${esc(s.short_name)})</small></h3><p>${s.is_active ? 'Active' : 'Inactive'}</p></div>${toggleBtn('schools', s.id)}</div>`).join('')}</section>
  <section class="card"><h3>Add department</h3><form data-form="admin-department"><label>Institution</label><select name="school_id" required>${schoolOptions(t.schools)}</select><label>Department name</label><input name="name" required><button class="btn full">Add department</button></form>
  <hr class="divider"><h3>Departments</h3>${t.departments.map((d) => `<div class="list-item"><div class="grow"><h3>${esc(d.name)}</h3><p>${esc(d.school)} · ${d.is_active ? 'Active' : 'Inactive'}</p></div>${toggleBtn('departments', d.id)}</div>`).join('')}</section></div>`;
}

async function adminUsers() {
  const r = await api('/admin/users');
  const me = session.user || {};
  return `<div class="section-head"><div><span class="eyebrow">ACCOUNT MANAGEMENT</span><h2>Users</h2></div></div>${adminNav('/admin/users')}
  <section class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Institution / department</th><th>Level</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>
  ${r.data.map((u) => `<tr><td>${esc(u.name)}</td><td>${esc(u.email)}</td><td>${esc(u.school || '—')} / ${esc(u.department || '—')}</td><td>${esc(u.level || '—')}</td><td>${esc(u.role)}</td><td>${u.is_active ? 'Active' : 'Disabled'}</td><td>${u.id !== me.id ? toggleBtn('users', u.id) : ''}</td></tr>`).join('')}
  </tbody></table></div></section>`;
}

/* ---------- department <-> institution select filtering ---------- */
function bindDeptFilters() {
  $$('form').forEach((f) => {
    const school = f.querySelector('select[name="school_id"]');
    const dept = f.querySelector('select[name="department_id"]');
    if (!school || !dept) return;
    const apply = () => {
      let firstVisible = null;
      [...dept.options].forEach((o) => {
        if (!o.dataset.school) return;
        o.hidden = o.disabled = school.value !== '' && o.dataset.school !== school.value;
        if (!o.hidden && !firstVisible) firstVisible = o;
      });
      const cur = dept.options[dept.selectedIndex];
      if (!cur || cur.hidden) dept.value = firstVisible ? firstVisible.value : '';
    };
    school.addEventListener('change', apply);
    apply();
  });
}

/* ---------- actions & forms ---------- */
async function busy(btn, fn) {
  if (btn) { btn.disabled = true; }
  try { return await fn(); }
  catch (e) { toast(e.message, 'err'); }
  finally { if (btn && btn.isConnected) btn.disabled = false; }
}

const actions = {
  reload: () => route(),
  menu: () => $('#hdrLinks').classList.toggle('open'),
  reveal: (el) => { const box = document.getElementById(el.dataset.target); if (box) box.hidden = !box.hidden; },
  logout: async () => { try { await api('/auth/logout', { method: 'POST', quiet401: true }); } catch { /* ignore */ } session.clear(); go('/'); route(); },
  pay: (el) => busy(el, async () => {
    const r = await api('/payments/initialize', { method: 'POST', body: { plan_id: Number(el.dataset.plan) } });
    window.location.href = r.authorization_url;
  }),
  verify: (el) => busy(el, async () => {
    const r = await api('/payments/verify' + qs({ reference: el.dataset.ref }));
    toast(r.payment.message || r.payment.status, r.payment.status === 'failed' ? 'err' : 'ok');
    route();
  }),
  'admin-verify': (el) => busy(el, async () => {
    const r = await api(`/admin/payments/${el.dataset.id}/verify`, { method: 'POST' });
    toast(r.payment.message || r.payment.status, r.payment.status === 'failed' ? 'err' : 'ok');
    route();
  }),
  toggle: (el) => busy(el, async () => {
    const r = await api(`/admin/${el.dataset.kind}/${el.dataset.id}/toggle`, { method: 'POST' });
    toast(r.message || 'Updated.');
    route();
  }),
};

const forms = {
  login: (f) => busy(f.querySelector('button'), async () => {
    const r = await api('/auth/login', { method: 'POST', body: Object.fromEntries(new FormData(f)), quiet401: true });
    session.set(r.token, r.user); go('/dashboard'); route();
  }),
  register: (f) => busy(f.querySelector('button'), async () => {
    const r = await api('/auth/register', { method: 'POST', body: Object.fromEntries(new FormData(f)) });
    session.set(r.token, r.user); go('/dashboard'); route();
  }),
  explore: (f) => { const d = Object.fromEntries(new FormData(f)); go('/explore' + qs({ q: d.q || undefined, school_id: d.school_id || undefined })); },
  questions: (f) => { const d = Object.fromEntries(new FormData(f)); go('/questions' + qs({ q: d.q || undefined, course: d.course || undefined, level: d.level })); },
  'admin-question': (f) => adminPost(f, '/admin/questions'),
  'admin-import': (f) => adminPost(f, '/admin/questions/import', (r) => { if (r.skipped && r.skipped.length) alert('Some rows were skipped:\n\n' + r.skipped.join('\n')); }),
  'admin-material': (f) => adminPost(f, '/admin/materials'),
  'admin-plan': (f) => adminPost(f, '/admin/plans', null, true),
  'admin-school': (f) => adminPost(f, '/admin/schools', null, true),
  'admin-department': (f) => adminPost(f, '/admin/departments', null, true),
};

function adminPost(f, path, then, asJson = false) {
  return busy(f.querySelector('button'), async () => {
    const fd = new FormData(f);
    const r = asJson ? await api(path, { method: 'POST', body: Object.fromEntries(fd) }) : await api(path, { method: 'POST', form: fd });
    toast(r.message || 'Saved.');
    if (then) then(r);
    route();
  });
}

document.addEventListener('click', (e) => {
  const el = e.target.closest('[data-act]');
  if (el && actions[el.dataset.act]) { e.preventDefault(); actions[el.dataset.act](el); return; }
  const links = $('#hdrLinks');
  if (links && e.target.closest('#hdrLinks a')) links.classList.remove('open');
});
document.addEventListener('submit', (e) => {
  const f = e.target.closest('form[data-form]');
  if (f && forms[f.dataset.form]) { e.preventDefault(); forms[f.dataset.form](f); }
});

/* ---------- boot ---------- */
// Paystack sends customers back to  <site>/?reference=...&trxref=...  — hand that to the callback page.
(function handlePaystackReturn() {
  const sp = new URLSearchParams(location.search);
  const ref = sp.get('reference') || sp.get('trxref');
  if (ref) history.replaceState(null, '', location.pathname + '#/payment/callback?reference=' + encodeURIComponent(ref));
})();

window.addEventListener('hashchange', route);
route();
})();
