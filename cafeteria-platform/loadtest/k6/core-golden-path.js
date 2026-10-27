// Core read-path load test (docs/go-live/core/core-go-live-evidence.md §9). Never run against
// production: it signs in synthetic accounts and checks synthetic cards.
//
//   k6 run -e BASE_URL=https://localhost:8444 -e DATA=<outside-repo>/load-data.json \
//          -e VUS=20 -e DURATION=60s core-platform/loadtest/k6/core-golden-path.js
//
// Each virtual user is one synthetic viewer account (per-account limits: 240 API calls and 60 card
// checks a minute) with an API token made in advance, and one client address (X-Forwarded-For,
// appended by the TLS front as nginx does), because sign-in is throttled per address.
// Mix per iteration: employee search 25 %, employee profile 20 %, card check by UUID 15 %,
// partner card check by credential 15 %, organization tree 10 %, dashboard 10 %, sign-in 5 %.
// 429 is counted apart (the limiter working); every other unexpected status is an error.
// DEBUG=1 logs each unexpected response.
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
    // A VU keeps its browser session across iterations, like a person: k6 otherwise clears cookies after each iteration.
    noCookiesReset: true,
};

const pick = (list) => list[Math.floor(Math.random() * list.length)];
const client = () => ({ 'X-Forwarded-For': `10.20.${Math.floor(__VU / 250)}.${(__VU % 250) + 1}` });
let session = null;

function record(name, res, ok) {
    trends[name].add(res.timings.duration);
    if (res.status === 429) { throttled.add(1); return; }
    check(res, { [name]: () => ok });
    errors.add(!ok, { kind: name });
    if (!ok && __ENV.DEBUG) { console.log(`${name}: ${res.status} ${res.headers['Location'] || ''}`); }
}

function signIn(account) {
    const jar = http.cookieJar();
    jar.clear(BASE);
    http.get(`${BASE}/login`, { headers: client(), tags: { name: 'login-page' } });
    const xsrf = decodeURIComponent((jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [''])[0]);
    const res = http.post(`${BASE}/login`, { email: account.email, password: account.password },
        { headers: { ...client(), 'X-XSRF-TOKEN': xsrf }, redirects: 0, tags: { name: 'login' } });
    // A refused sign-in also answers 302 (back to /login): only a redirect elsewhere is a success.
    const signedIn = res.status === 302 && !String(res.headers['Location'] || '').includes('/login');
    record('login', res, signedIn);
    return signedIn;
}

export default function () {
    const account = data.accounts[(__VU - 1) % data.accounts.length];
    const api = { ...client(), Authorization: `Bearer ${account.token}`, Accept: 'application/json' };
    const roll = Math.random() * 100;
    if (roll < 25) {
        const res = http.get(`${BASE}/api/v1/employees?per_page=20&search=${encodeURIComponent(pick(data.terms))}`, { headers: api, tags: { name: 'search' } });
        record('search', res, res.status === 200);
    } else if (roll < 45) {
        const res = http.get(`${BASE}/api/v1/employees/${pick(data.employees)}`, { headers: api, tags: { name: 'profile' } });
        record('profile', res, res.status === 200);
    } else if (roll < 60) {
        const res = http.post(`${BASE}/api/v1/cards/verify`, JSON.stringify({ card_uuid: pick(data.cards) }),
            { headers: { ...api, 'Content-Type': 'application/json' }, tags: { name: 'verify' } });
        record('verify', res, res.status === 200 && res.json('valid') === true);
    } else if (roll < 75) {
        const res = http.post(`${BASE}/api/v1/integration/cards/verify`, JSON.stringify({ token: pick(data.cards) }), { headers: { ...client(),
            Authorization: `Bearer ${data.clients[(__VU - 1) % data.clients.length]}`, Accept: 'application/json', 'Content-Type': 'application/json' }, tags: { name: 'partner' } });
        record('partner', res, res.status === 200 && res.json('valid') === true);
    } else if (roll < 85) {
        const res = http.get(`${BASE}/api/v1/hierarchy`, { headers: api, tags: { name: 'tree' } });
        record('tree', res, res.status === 200);
    } else if (roll < 95) {
        if (session !== account.email) { session = signIn(account) ? account.email : null; }
        const res = http.get(`${BASE}/core`, { headers: client(), redirects: 0, tags: { name: 'dashboard' } });
        record('dashboard', res, res.status === 200);
        if (res.status !== 200) { session = null; }
    } else {
        session = signIn(account) ? account.email : null;
    }
}
