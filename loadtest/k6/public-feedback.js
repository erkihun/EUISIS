// Public feedback load test (QA brief §71–§73): scenarios A–J plus a scan
// burst, driven at arrival rates against one or more stateless app nodes.
//
//   k6 run -e PROFILE=baseline -e BASE_URLS=http://127.0.0.1:8101,http://127.0.0.1:8102 \
//          -e DATA=loadtest-data.json -e PASSWORD='…' loadtest/k6/public-feedback.js
//
// Input data comes from `php artisan scale:export-loadtest-data` run against a
// database seeded by `scale:generate` and `feedback:scale-seed` — never from
// production. The public submit routes are throttled per QR code and per client
// address; one generator is one address, so every request carries an
// X-Forwarded-For from a per-VU pool and the nodes under test must trust the
// generator as a proxy (APP_TRUSTED_PROXIES). A 429 is the limiter working and
// is counted in `pf_throttled`, not as an error.
import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const BASES = (__ENV.BASE_URLS || __ENV.BASE_URL || 'http://127.0.0.1:8000').split(',').map((s) => s.trim());
function node() {
    return BASES[Math.floor(Math.random() * BASES.length)];
}
const BASE = BASES[0];
const PASSWORD = __ENV.PASSWORD || 'Scale-Load-Test-2026!';
const PROFILE = __ENV.PROFILE || 'smoke';

const data = new SharedArray('data', () => [JSON.parse(open(__ENV.DATA || './loadtest-data.json'))])[0];

export const errors = new Rate('pf_errors');
const throttled = new Rate('pf_throttled');
const accepted = new Counter('pf_submissions_accepted');
const submitTime = new Trend('pf_submit_roundtrip_ms', true);
const loginTime = new Trend('pf_login_ms', true);

// Rates are iterations per second for the whole group: `landing` is split over
// A and B, `submit` over C–H (each of the six runs at submit/6), `internal` is
// I and `review` is J. `burst` adds a ramp to `burstRate` scans per second of
// ONE institution poster on top of a light background.
const PROFILES = {
    smoke: { landing: 2, submit: 1, internal: 1, review: 1, duration: '30s' },
    baseline: { landing: 20, submit: 6, internal: 4, review: 2, duration: '3m' },
    peak: { landing: 60, submit: 15, internal: 10, review: 4, duration: '3m' },
    stress: { landing: 150, submit: 30, internal: 20, review: 8, duration: '3m' },
    burst: { landing: 5, submit: 2, internal: 2, review: 1, duration: '70s', burstRate: Number(__ENV.BURST_RATE || 200) },
    custom: {
        landing: Number(__ENV.LANDING_RATE || 10), submit: Number(__ENV.SUBMIT_RATE || 3),
        internal: Number(__ENV.INTERNAL_RATE || 2), review: Number(__ENV.REVIEW_RATE || 1),
        duration: __ENV.DURATION || '3m', burstRate: Number(__ENV.BURST_RATE || 0),
    },
};
const p = PROFILES[PROFILE] || PROFILES.smoke;

function arrival(exec, rate, timeUnit, vus) {
    return { executor: 'constant-arrival-rate', exec, rate, timeUnit, duration: p.duration, preAllocatedVUs: vus, maxVUs: vus * 10 };
}

const scenarios = {
    A_employee_landing: arrival('employeeLanding', p.landing, '2s', Math.max(2, p.landing)),
    B_organization_landing: arrival('organizationLanding', p.landing, '2s', Math.max(2, p.landing)),
    C_employee_feedback_submit: arrival('employeeFeedbackSubmit', p.submit, '6s', Math.max(2, p.submit)),
    D_employee_suggestion_submit: arrival('employeeSuggestionSubmit', p.submit, '6s', Math.max(2, p.submit)),
    E_employee_complaint_submit: arrival('employeeComplaintSubmit', p.submit, '6s', Math.max(2, p.submit)),
    F_organization_feedback_submit: arrival('organizationFeedbackSubmit', p.submit, '6s', Math.max(2, p.submit)),
    G_organization_suggestion_submit: arrival('organizationSuggestionSubmit', p.submit, '6s', Math.max(2, p.submit)),
    H_organization_complaint_submit: arrival('organizationComplaintSubmit', p.submit, '6s', Math.max(2, p.submit)),
    I_internal_list_dashboard: arrival('internalListDashboard', p.internal, '1s', Math.max(2, p.internal * 2)),
    J_assign_review: arrival('assignReview', p.review, '1s', Math.max(2, p.review * 2)),
};
if (p.burstRate) {
    scenarios.BURST_one_poster_scanned = {
        executor: 'ramping-arrival-rate', exec: 'burstScan', startRate: 0, timeUnit: '1s',
        preAllocatedVUs: Math.max(20, p.burstRate), maxVUs: p.burstRate * 10,
        stages: [{ target: p.burstRate, duration: '10s' }, { target: p.burstRate, duration: '30s' }, { target: 0, duration: '10s' }],
    };
}

export const options = {
    scenarios,
    thresholds: {
        pf_errors: ['rate<0.01'],
        'http_req_duration{kind:landing}': ['p(95)<800'],
        'http_req_duration{kind:submit}': ['p(95)<1500'],
        'http_req_duration{kind:page}': ['p(95)<1500', 'p(99)<3000'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

// Per-VU client address pool, so the per-address limiter sees a crowd and not
// one generator. Needs APP_TRUSTED_PROXIES on the nodes under test.
function clientIp() {
    return `10.${(__VU >> 8) & 255}.${__VU & 255}.${(__ITER + 1) & 255}`;
}
function headers(extra) {
    return Object.assign({ 'X-Forwarded-For': clientIp(), Accept: 'text/html' }, extra || {});
}
function xsrf(jar) {
    const cookies = jar.cookiesForURL(BASE);
    return cookies['XSRF-TOKEN'] ? decodeURIComponent(cookies['XSRF-TOKEN'][0]) : '';
}
function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}
function formToken(body) {
    const m = String(body).match(/&quot;formToken&quot;:&quot;([0-9a-f]{32})&quot;/);
    return m ? m[1] : null;
}

// ---- A, B: landing pages ----------------------------------------------------
function landing(path, name) {
    const res = http.get(`${node()}${path}`, { headers: headers(), tags: { kind: 'landing', name }, responseCallback: http.expectedStatuses(200, 429) });
    throttled.add(res.status === 429);
    if (res.status === 429) return null;
    const ok = check(res, { [`${name} 200`]: (r) => r.status === 200 && r.body.includes('data-page') });
    errors.add(!ok);
    return ok ? res : null;
}
export function employeeLanding() {
    landing(`/service-feedback/${pick(data.employeeFeedback).token}`, 'employee_landing');
}
export function organizationLanding() {
    landing(`/feedback/${pick(data.organizationTokens)}`, 'organization_landing');
}
// Burst: a crowd scanning the SAME poster (one institution QR) at once.
export function burstScan() {
    landing(`/feedback/${data.organizationTokens[0]}`, 'burst_scan');
}

// ---- C–H: form page then submit ----------------------------------------------
function submit(path, name, payload) {
    const jar = http.cookieJar();
    const form = http.get(`${node()}${path}`, { headers: headers(), tags: { kind: 'form', name }, responseCallback: http.expectedStatuses(200, 429) });
    throttled.add(form.status === 429);
    if (form.status === 429) return;
    const token = form.status === 200 ? formToken(form.body) : null;
    if (!token) {
        errors.add(true);
        return;
    }
    const started = Date.now();
    const res = http.post(`${node()}${path}`, Object.assign({ form_token: token }, payload), {
        headers: headers({ 'X-XSRF-TOKEN': xsrf(jar), Referer: `${BASE}${path}` }),
        tags: { kind: 'submit', name }, responseCallback: http.expectedStatuses(200, 429),
    });
    submitTime.add(Date.now() - started);
    throttled.add(res.status === 429);
    if (res.status === 429) return;
    // The POST redirects to the form page, which renders the receipt after a stored submission.
    const ok = check(res, { [`${name} stored`]: (r) => r.status === 200 && r.body.includes('&quot;submitted&quot;:true') });
    errors.add(!ok);
    if (ok) accepted.add(1);
}
const MESSAGE = 'Load-test submission: the counter service took longer than expected today.';
export function employeeFeedbackSubmit() {
    const e = pick(data.employeeFeedback);
    submit(`/service-feedback/${e.token}/feedback`, 'C_employee_feedback', { rating: 1 + Math.floor(Math.random() * 5), message: MESSAGE, position_service_id: e.serviceId });
}
export function employeeSuggestionSubmit() {
    submit(`/service-feedback/${pick(data.employeeFeedback).token}/suggestion`, 'D_employee_suggestion', { message: MESSAGE, category_id: pick(data.categories) });
}
export function employeeComplaintSubmit() {
    submit(`/service-feedback/${pick(data.employeeFeedback).token}/complaint`, 'E_employee_complaint', { message: MESSAGE, category_id: pick(data.categories) });
}
export function organizationFeedbackSubmit() {
    submit(`/feedback/${pick(data.organizationTokens)}/feedback`, 'F_organization_feedback', { rating: 1 + Math.floor(Math.random() * 5), message: MESSAGE });
}
export function organizationSuggestionSubmit() {
    submit(`/feedback/${pick(data.organizationTokens)}/suggestion`, 'G_organization_suggestion', { message: MESSAGE, category_id: pick(data.categories) });
}
export function organizationComplaintSubmit() {
    submit(`/feedback/${pick(data.organizationTokens)}/complaint`, 'H_organization_complaint', { message: MESSAGE, category_id: pick(data.categories) });
}

// ---- I, J: internal ------------------------------------------------------------
const signedIn = {};
function ensureLogin(email) {
    if (signedIn[__VU] === email) return true;
    const jar = http.cookieJar();
    jar.clear(BASE);
    http.get(`${node()}/login`, { headers: headers(), tags: { kind: 'login' } });
    const started = Date.now();
    const res = http.post(`${node()}/login`, { email, password: PASSWORD }, {
        headers: headers({ 'X-XSRF-TOKEN': xsrf(jar) }), redirects: 0, tags: { kind: 'login' },
    });
    loginTime.add(Date.now() - started);
    const ok = check(res, { 'login redirects': (r) => r.status === 302 && !String(r.headers.Location || '').includes('/login') });
    errors.add(!ok);
    if (ok) signedIn[__VU] = email;
    return ok;
}
function page(path, name) {
    const res = http.get(`${node()}${path}`, { headers: headers(), tags: { kind: 'page', name } });
    const ok = check(res, { [`${name} 200`]: (r) => r.status === 200 && r.body.includes('data-page') });
    errors.add(!ok);
    return res;
}
export function internalListDashboard() {
    if (!ensureLogin(pick(data.feedbackHandlers).email)) return;
    page('/service-complaints', 'complaints_inbox');
    page('/service-suggestions', 'suggestions_inbox');
    page('/feedback-admin/dashboard', 'feedback_dashboard');
}
export function assignReview() {
    if (!ensureLogin(pick(data.feedbackAdmins))) return;
    const s = pick(data.openSubmissions);
    const showPath = `/service-submissions/${s.type}/${s.id}`;
    const jar = http.cookieJar();
    const res = http.post(`${node()}${showPath}/assign`, { handler_type: 'user', handler_id: String(s.handlerId), reason: 'Load test reassignment' }, {
        headers: headers({ 'X-XSRF-TOKEN': xsrf(jar), Referer: `${BASE}${showPath}` }), tags: { kind: 'page', name: 'assign' },
    });
    // back() lands on the detail page; a validation error would render it with a non-empty errors object.
    const ok = check(res, { 'assign ok': (r) => r.status === 200 && r.body.includes('data-page') && !/&quot;errors&quot;:\{&quot;/.test(r.body) });
    errors.add(!ok);
}
