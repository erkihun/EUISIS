// Addis One transport boarding load test (docs/transport/uat.md § Load test). Never run against production.
//
//   php artisan transport:seed-loadtest storage/app/loadtest/transport-loadtest.json --employees=600 --routes=6
//   k6 run -e BASE_URL=http://127.0.0.1:8000 -e DATA=storage/app/loadtest/transport-loadtest.json \
//          -e PROFILE=morning loadtest/k6/transport-boarding.js
//
// Every virtual scanner is one seeded device (its own credential and bus);
// riders are synthetic cards. The morning profile is the realistic burst:
// every bus boards its queue within twenty minutes, with the duplicate and
// retry behaviour a real door produces (a resent request with the same
// idempotency key, a second scan of the same card on the same trip).

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const PROFILE = __ENV.PROFILE || 'smoke';
const DIRECTION = __ENV.DIRECTION || 'inbound';
const data = new SharedArray('data', () => [JSON.parse(open(__ENV.DATA || './transport-loadtest.json'))])[0];

export const boardingErrors = new Rate('transport_boarding_errors');
export const refused = new Rate('transport_boarding_refused');
export const replayed = new Counter('transport_boarding_replayed');
export const duplicates = new Counter('transport_boarding_duplicates');
const verifyTime = new Trend('transport_verify_ms', true);

// Boardings per second across the whole fleet (a bus door clears a card every 2–3 s; 12 buses ≈ 5/s).
const PROFILES = {
    smoke: { rate: 1, duration: '30s' },
    morning: { rate: 5, duration: '20m' },
    peak: { rate: 15, duration: '10m' },
    stress: { rate: 40, duration: '5m' },
    custom: { rate: Number(__ENV.RATE || 5), duration: __ENV.DURATION || '5m' },
};
const p = PROFILES[PROFILE];

export const options = {
    scenarios: {
        boarding: { executor: 'constant-arrival-rate', exec: 'board', rate: p.rate, timeUnit: '1s', duration: p.duration, preAllocatedVUs: Math.max(4, p.rate * 3), maxVUs: p.rate * 20 },
    },
    thresholds: {
        transport_verify_ms: ['p(95)<800', 'p(99)<1500'],
        transport_boarding_errors: ['rate<0.01'],
        http_req_failed: ['rate<0.01'],
    },
};

function headers(credential) {
    return { headers: { Authorization: `Bearer ${credential}`, Accept: 'application/json', 'Content-Type': 'application/json' } };
}

function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); });
}

// Activate every trip once: the engine refuses a boarding on a trip that is not boarding.
export function setup() {
    const started = [];
    for (const device of data.devices) {
        const trip = device.trips[DIRECTION];
        const res = http.post(`${BASE}/api/v1/transport/trips/${trip}/start`, null, headers(device.credential));
        check(res, { 'trip started': (r) => r.status === 200 || r.status === 409 || r.status === 422 });
        started.push(trip);
    }
    return { started };
}

export function board() {
    const device = data.devices[Math.floor(Math.random() * data.devices.length)];
    const trip = device.trips[DIRECTION];
    const onRoute = data.riders.filter((r) => r.route === device.route);
    const rider = onRoute[Math.floor(Math.random() * onRoute.length)];
    const key = uuid();
    const payload = JSON.stringify({ credential: { type: 'qr', value: rider.qr }, trip_id: trip, idempotency_key: key, recorded_at: new Date().toISOString() });

    const res = http.post(`${BASE}/api/v1/transport/boardings/verify`, payload, headers(device.credential));
    verifyTime.add(res.timings.duration);
    const ok = res.status === 201 || res.status === 422;
    boardingErrors.add(!ok);
    if (res.status === 422) {
        refused.add(1);
        try { if (JSON.parse(res.body).result_code === 'already_boarded') duplicates.add(1); } catch (e) { /* not json */ }
    } else {
        refused.add(0);
    }
    check(res, { 'boarding answered': () => ok, 'server dated the scan': (r) => r.status !== 201 || JSON.parse(r.body).transaction !== undefined });

    // One request in twenty is resent as the network would resend it: same key, same answer, never a second row.
    if (Math.random() < 0.05) {
        const again = http.post(`${BASE}/api/v1/transport/boardings/verify`, payload, headers(device.credential));
        if (again.status === 201 || again.status === 422) {
            replayed.add(1);
            check(again, { 'replay answered identically': (r) => r.status === res.status });
        } else {
            boardingErrors.add(1);
        }
    }
    sleep(0.1);
}

export function teardown(state) {
    for (const device of data.devices) {
        http.post(`${BASE}/api/v1/transport/trips/${device.trips[DIRECTION]}/complete`, null, headers(device.credential));
    }
}
