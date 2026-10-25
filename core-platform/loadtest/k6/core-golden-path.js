// Core read-path load test (docs/go-live/core/core-go-live-evidence.md §9). Never run against
// production: it signs in synthetic accounts and checks synthetic cards.
//
//   k6 run -e BASE_URL=https://localhost:8444 -e DATA=<outside-repo>/load-data.json \
//          -e VUS=20 -e DURATION=60s --insecure-skip-tls-verify core-platform/loadtest/k6/core-golden-path.js
//
// Each virtual user is one synthetic viewer account (per-account limits: 240 API calls and
// 60 card checks a minute) with a token made in advance, because sign-in is throttled per address.
// Mix per iteration: employee search 25 %, employee profile 20 %, card check by UUID 15 %,
// partner card check by credential 15 %, organization tree 10 %, dashboard 10 %, sign-in 5 %.
// 429 is counted apart (the limiter working); every other non-2xx is an error.
import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const BASE = __ENV.BASE_URL || 'https://localhost:8444';
const data = new SharedArray('core', () => [JSON.parse(open(__ENV.DATA))])[0];
const errors = new Rate('core_errors');
const throttled = new Counter('core_throttled');
const trends = Object.fromEntries(['search', 'profile', 'verify', 'partner', 'tree', 'dashboard', 'login'].map((k) => [k, new Trend(`core_${k}_ms`, true)]));

export const options = {
    scenarios: { load: { executor: 'constant-vus', vus: Number(__ENV.VUS || 5), duration: __ENV.DURATION || '60s', gracefulStop: '10s' } },
    summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
    insecureSkipTLSVerify: true,
};

const pick = (list) => list[Math.floor(Math.random() * list.length)];
let session = null;

function record(name, res, expected = 200) {
    trends[name].add(res.timings.duration, { kind: name });
    if (res.status === 429) { throttled.add(1); return; }
    const ok = check(res, { [`${name} ${expected}`]: (r) => r.status === expected });
    errors.add(!ok, { kind: name });
}

function signIn(account) {
    const jar = http.cookieJar();
    jar.clear(BASE);
    http.get(`${BASE}/login`, { tags: { name: 'login-page' } });
    const xsrf = decodeURIComponent((jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [''])[0]);
    const res = http.post(`${BASE}/login`, { email: account.email, password: account.password },
        { headers: { 'X-XSRF-TOKEN': xsrf }, redirects: 0, tags: { name: 'login' } });
    record('login', res, 302);
    return res.status === 302;
}

export default function () {
    const account = data.accounts[(__VU - 1) % data.accounts.length];
    const api = { headers: { Authorization: `Bearer ${account.token}`, Accept: 'application/json' } };
    const roll = Math.random() * 100;
    if (roll < 25) {
        record('search', http.get(`${BASE}/api/v1/employees?per_page=20&search=${encodeURIComponent(pick(data.terms))}`, { ...api, tags: { name: 'search' } }));
    } else if (roll < 45) {
        record('profile', http.get(`${BASE}/api/v1/employees/${pick(data.employees)}`, { ...api, tags: { name: 'profile' } }));
    } else if (roll < 60) {
        const res = http.post(`${BASE}/api/v1/cards/verify`, JSON.stringify({ card_uuid: pick(data.cards) }),
            { headers: { ...api.headers, 'Content-Type': 'application/json' }, tags: { name: 'verify' } });
        record('verify', res);
    } else if (roll < 75) {
        const res = http.post(`${BASE}/api/v1/integration/cards/verify`, JSON.stringify({ token: pick(data.cards) }),
            { headers: { Authorization: `Bearer ${data.clients[(__VU - 1) % data.clients.length]}`, Accept: 'application/json', 'Content-Type': 'application/json' }, tags: { name: 'partner' } });
        record('partner', res);
        check(res, { 'partner valid': (r) => r.status !== 200 || r.json('valid') === true });
    } else if (roll < 85) {
        record('tree', http.get(`${BASE}/api/v1/hierarchy`, { ...api, tags: { name: 'tree' } }));
    } else if (roll < 95) {
        if (session !== account.email) { session = signIn(account) ? account.email : null; }
        record('dashboard', http.get(`${BASE}/core`, { redirects: 0, tags: { name: 'dashboard' } }));
    } else {
        session = signIn(account) ? account.email : null;
    }
}
