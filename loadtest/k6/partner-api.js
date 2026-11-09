// Partner / integration API load test (docs/production-readiness/load-test-results.md).
// Never run against production: it records synthetic service transactions.
//
//   php artisan scale:export-api-loadtest-data <outside-repo>/api-data.json     (benchmark DB)
//   k6 run -e BASE_URLS=http://127.0.0.1:8101,http://127.0.0.1:8102 -e DATA=<file> \
//          -e PROFILE=normal loadtest/k6/partner-api.js
//
// PROFILE (arrival rate, requests/s):
//   smoke   2/s  30s     normal 20/s 3m     busy 50/s 3m     peak 100/s 3m
//   burst   card-scan lunch burst: ramps 5 → BURST_RATE (default 120) scans/s in 30 s, holds, drops
//   soak    SOAK_RATE (default 20)/s for SOAK_DURATION (default 30m)
//   custom  RATE/s for DURATION
// MIX=mixed (default) | read | scan | one endpoint name below, to measure it alone.
//
// Mixed ratio (per request): employee by UUID 25 %, card verify 30 %, service
// eligibility 15 %, current assignment 10 %, employee by number 8 %,
// organization units 5 %, change-feed poll 5 %, service transaction (write) 2 %.

import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const BASES = (__ENV.BASE_URLS || __ENV.BASE_URL || 'http://127.0.0.1:8000').split(',').map((s) => s.trim());
const PROFILE = __ENV.PROFILE || 'smoke';
const MIX = __ENV.MIX || 'mixed';
const data = new SharedArray('api', () => [JSON.parse(open(__ENV.DATA || './api-data.json'))])[0];

const unexpected = new Rate('api_unexpected_errors'); // 5xx, timeouts, connection errors
const throttled = new Counter('api_throttled'); // 429: the limiter working
const denied = new Counter('api_auth_denied'); // 401/403: should be 0 with valid tokens
const conflicts = new Counter('api_conflicts'); // 409: idempotency in progress/reused (expected under duplicates)
const businessDenied = new Counter('api_business_denied'); // 200 with allowed=false
const notFound = new Counter('api_not_found'); // 404: organization-scoped clients asking outside their bound (expected)
const scanMs = new Trend('api_scan_flow_ms', true);

const ratePlan = {
    smoke: { rate: 2, duration: '30s' },
    normal: { rate: 20, duration: '3m' },
    busy: { rate: 50, duration: '3m' },
    peak: { rate: 100, duration: '3m' },
    soak: { rate: Number(__ENV.SOAK_RATE || 20), duration: __ENV.SOAK_DURATION || '30m' },
    custom: { rate: Number(__ENV.RATE || 10), duration: __ENV.DURATION || '2m' },
};

function scenarioFor(profile) {
    if (profile === 'burst') {
        const top = Number(__ENV.BURST_RATE || 120);
        return {
            executor: 'ramping-arrival-rate', exec: 'run', startRate: 5, timeUnit: '1s',
            preAllocatedVUs: top * 2, maxVUs: top * 8,
            stages: [
                { target: 5, duration: '30s' }, { target: top, duration: '30s' },
                { target: top, duration: __ENV.BURST_HOLD || '2m' }, { target: 5, duration: '30s' }, { target: 5, duration: '1m' },
            ],
        };
    }
    const r = ratePlan[profile];
    return {
        executor: 'constant-arrival-rate', exec: 'run', rate: r.rate, timeUnit: '1s', duration: r.duration,
        preAllocatedVUs: Math.max(10, r.rate * 2), maxVUs: Math.max(50, r.rate * 10),
    };
}

export const options = {
    scenarios: { api: scenarioFor(PROFILE) },
    // Engineering targets pending an approved SLA (NEEDS_POLICY_DECISION_SLA), not SLOs.
    thresholds: {
        api_unexpected_errors: ['rate<0.01'],
        'http_req_duration{kind:read}': ['p(95)<500', 'p(99)<1000'],
        'http_req_duration{kind:write}': ['p(95)<800'],
        // Listed per endpoint so the summary reports each one's percentiles (always-pass bound).
        ...Object.fromEntries(['employee_uuid', 'employee_number', 'assignment', 'eligibility', 'card_uuid', 'card_verify', 'org_units',
            'employee_list', 'change_feed', 'transaction'].map((n) => [`http_req_duration{name:${n}}`, ['max>=0']])),
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'],
    discardResponseBodies: false,
};

const pick = (list) => list[Math.floor(Math.random() * list.length)];
const node = () => pick(BASES);
const cityClients = data.clients.filter((c) => !c.scoped);
const scopedClients = data.clients.filter((c) => c.scoped);
// Change-feed cursors are bound to the client that received them, so keep one per VU and client.
const cursors = {};

function headers(client, extra = {}) {
    return { headers: Object.assign({ Authorization: `Bearer ${client.token}`, Accept: 'application/json' }, extra) };
}

function record(res, name) {
    const s = res.status;
    if (s === 429) throttled.add(1);
    if (s === 401 || s === 403) denied.add(1);
    if (s === 409) conflicts.add(1);
    if (s === 404) notFound.add(1);
    unexpected.add(s === 0 || s >= 500, { name });
    check(res, { [`${name} ok`]: (r) => r.status === 200 || r.status === 201 || r.status === 404 || r.status === 409 || r.status === 429 });
    return res;
}

function get(client, path, name) {
    return record(http.get(`${node()}${path}`, Object.assign(headers(client), { tags: { name, kind: 'read' } })), name);
}

function post(client, path, body, name, kind, extra = {}) {
    return record(http.post(`${node()}${path}`, JSON.stringify(body),
        Object.assign(headers(client, Object.assign({ 'Content-Type': 'application/json' }, extra)), { tags: { name, kind } })), name);
}

const ops = {
    employee_uuid: (c, e) => get(c, `/api/v1/employees/${e.id}`, 'employee_uuid'),
    employee_number: (c, e) => get(c, `/api/v1/employees?employee_number=${encodeURIComponent(e.number)}`, 'employee_number'),
    assignment: (c, e) => get(c, `/api/v1/employees/${e.id}/assignment`, 'assignment'),
    eligibility: (c, e) => get(c, `/api/v1/employees/${e.id}/service-eligibility?service_type=${data.serviceType}`, 'eligibility'),
    card_uuid: (c, e) => get(c, `/api/v1/id-cards/verify/${e.cardUuid}`, 'card_uuid'),
    card_verify: (c, e) => {
        const res = post(c, '/api/v1/cards/verify', { token: e.cardToken, service_type: data.serviceType, provider_code: data.providerCode }, 'card_verify', 'read');
        if (res.status === 200 && res.body && res.body.indexOf('"allowed":false') >= 0) businessDenied.add(1);
        return res;
    },
    org_units: (c, e) => get(c, `/api/v1/organizations/${e.org}/units?per_page=25`, 'org_units'),
    employee_list: (c) => get(c, '/api/v1/employees?per_page=25&status=active', 'employee_list'),
    change_feed: (c) => {
        const key = `${__VU}:${c.code}`;
        const res = get(c, `/api/v1/changes?limit=100${cursors[key] ? `&cursor=${cursors[key]}` : ''}`, 'change_feed');
        if (res.status === 200) {
            try { cursors[key] = JSON.parse(res.body).next_cursor || cursors[key]; } catch (e) { /* keep the old cursor */ }
        }
        return res;
    },
    transaction: (c, e) => post(c, `/api/v1/services/${data.serviceType}/transactions`,
        { token: e.cardToken, provider_code: data.providerCode, reference: `K6-${__VU}-${__ITER}-${Date.now()}` },
        'transaction', 'write', { 'Idempotency-Key': `k6-${__VU}-${__ITER}-${Date.now()}` }),
};

const MIXES = {
    mixed: [['employee_uuid', 25], ['card_verify', 30], ['eligibility', 15], ['assignment', 10], ['employee_number', 8], ['org_units', 5], ['change_feed', 5], ['transaction', 2]],
    read: [['employee_uuid', 35], ['card_uuid', 20], ['eligibility', 20], ['assignment', 15], ['employee_number', 10]],
};

function choose() {
    if (ops[MIX]) return MIX;
    const table = MIXES[MIX] || MIXES.mixed;
    let r = Math.random() * 100;
    for (const [name, weight] of table) {
        if ((r -= weight) < 0) return name;
    }
    return table[0][0];
}

export function run() {
    const employee = pick(data.employees);
    if (MIX === 'scan') {
        // One card scan at a till: verify, then record the meal.
        const started = Date.now();
        const client = pick(cityClients);
        ops.card_verify(client, employee);
        ops.transaction(client, employee);
        scanMs.add(Date.now() - started);
        return;
    }
    // One request in ten comes from an organization-scoped client.
    const scoped = scopedClients.length > 0 && Math.random() < 0.1;
    const client = scoped ? pick(scopedClients) : pick(cityClients);
    ops[choose()](client, employee);
}
