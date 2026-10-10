// EUISIS load test (docs/load-testing.md). Never run against production.
//
//   k6 run -e BASE_URL=http://127.0.0.1:8000 -e DATA=./loadtest-data.json \
//          -e PROFILE=baseline loadtest/k6/euisis.js
//
// DATA is produced by `php artisan scale:export-loadtest-data` on the
// synthetic scale database (scale:generate). Transport is out of scope and has
// no scenario here.

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { SharedArray } from 'k6/data';

// One or more app nodes (comma separated). Every request picks a random node,
// like a non-sticky load balancer: a session started on one node must work on
// all of them (shared session store).
const BASES = (__ENV.BASE_URLS || __ENV.BASE_URL || 'http://127.0.0.1:8000').split(',').map((s) => s.trim());
function node() {
    return BASES[Math.floor(Math.random() * BASES.length)];
}
const BASE = BASES[0];
const PASSWORD = __ENV.PASSWORD || 'Scale-Load-Test-2026!';
const PROFILE = __ENV.PROFILE || 'smoke';

const data = new SharedArray('data', () => [JSON.parse(open(__ENV.DATA || './loadtest-data.json'))])[0];

export const errors = new Rate('euisis_errors');
const loginTime = new Trend('euisis_login_ms', true);

// Arrival-rate profiles: requests are driven at a target rate regardless of
// response time, so slow responses show as latency and dropped iterations
// instead of silently lowering the load.
const PROFILES = {
    smoke: { employees: 2, hr: 1, public: 1, duration: '30s' },
    baseline: { employees: 20, hr: 5, public: 10, duration: '3m' },
    peak: { employees: 60, hr: 15, public: 30, duration: '3m' },
    stress: { employees: 150, hr: 30, public: 60, duration: '3m' },
};
const p = PROFILES[PROFILE];

export const options = {
    scenarios: {
        employee_portal: {
            executor: 'constant-arrival-rate', exec: 'employeePortal',
            rate: p.employees, timeUnit: '1s', duration: p.duration,
            preAllocatedVUs: p.employees * 4, maxVUs: p.employees * 20,
        },
        hr_officer: {
            executor: 'constant-arrival-rate', exec: 'hrOfficer',
            rate: p.hr, timeUnit: '1s', duration: p.duration,
            preAllocatedVUs: p.hr * 4, maxVUs: p.hr * 20,
        },
        public_id_checker: {
            executor: 'constant-arrival-rate', exec: 'publicIdChecker',
            rate: p.public, timeUnit: '1s', duration: p.duration,
            preAllocatedVUs: p.public * 2, maxVUs: p.public * 10,
        },
    },
    thresholds: {
        euisis_errors: ['rate<0.01'],
        'http_req_duration{kind:page}': ['p(95)<1500', 'p(99)<3000'],
        'http_req_duration{kind:public}': ['p(95)<800'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

function xsrf(jar) {
    const cookies = jar.cookiesForURL(BASE);
    return cookies['XSRF-TOKEN'] ? decodeURIComponent(cookies['XSRF-TOKEN'][0]) : '';
}

// One sign-in per VU; the session cookie is kept in the VU's jar.
const signedIn = {};
function ensureLogin(email) {
    if (signedIn[__VU] === email) return true;
    const jar = http.cookieJar();
    jar.clear(BASE);
    http.get(`${node()}/login`, { tags: { kind: 'login' } });
    const started = Date.now();
    const res = http.post(`${node()}/login`, { email, password: PASSWORD }, {
        headers: { 'X-XSRF-TOKEN': xsrf(jar), Accept: 'text/html' },
        redirects: 0, tags: { kind: 'login' },
    });
    loginTime.add(Date.now() - started);
    const ok = check(res, { 'login redirects': (r) => r.status === 302 && !String(r.headers.Location || '').includes('/login') });
    errors.add(!ok);
    if (ok) signedIn[__VU] = email;
    return ok;
}

function page(path, name) {
    const res = http.get(`${node()}${path}`, { tags: { kind: 'page', name } });
    const ok = check(res, { [`${name} 200`]: (r) => r.status === 200 });
    errors.add(!ok);
    return res;
}

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

export function employeePortal() {
    const user = data.employees[(__VU - 1) % data.employees.length];
    if (!ensureLogin(user)) return;
    group('my portal', () => {
        page('/my-portal', 'portal');
        page('/my-portal/profile', 'profile');
        page('/my-portal/services', 'services');
        page('/my-portal/daily-activity', 'daily_activity_entry');
    });
    sleep(Math.random());
}

export function hrOfficer() {
    const user = data.hr[(__VU - 1) % data.hr.length];
    if (!ensureLogin(user)) return;
    group('hr officer', () => {
        page('/dashboard', 'dashboard');
        page('/employees', 'employee_list');
        page(`/employees?search=${encodeURIComponent(pick(data.searchTerms))}`, 'employee_search');
        page('/positions/status', 'position_status');
    });
    sleep(Math.random());
}

export function publicIdChecker() {
    const uuid = pick(data.publicCardUuids);
    const res = http.get(`${node()}/id-checker/${uuid}`, { tags: { kind: 'public', name: 'id_checker' } });
    const ok = check(res, { 'id checker 200': (r) => r.status === 200 });
    errors.add(!ok);
}
