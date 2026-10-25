// Local HTTPS front for the Core staging rehearsal (docs/runbook/core-staging-rehearsal.md).
// TEST RIG ONLY: stands in for nginx/the load balancer on a machine without them.
// Terminates TLS with a self-signed certificate, forwards round-robin to the
// php -S nodes with X-Forwarded-Proto/-For/-Host/-Port (the Core trusts them via
// TRUSTED_PROXIES=127.0.0.1), polls GET /health/ready every INTERVAL ms and takes
// a node out after FAILS failures; retries an idempotent GET once elsewhere.
//
//   node tls-lb.mjs <port> <key.pem> <cert.pem> http://127.0.0.1:8601 http://127.0.0.1:8602 ...
//   GET /__lb/status lists the nodes and counters.
import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';

const [port, keyFile, certFile, ...backends] = process.argv.slice(2);
const INTERVAL = Number(process.env.LB_INTERVAL || 2000);
const FAILS = Number(process.env.LB_FAILS || 2);
const pool = backends.map((url) => ({ url: new URL(url), healthy: true, fails: 0, served: 0, errors: 0 }));
const agent = new http.Agent({ keepAlive: true, maxSockets: 64 });
let next = 0;
const stats = { requests: 0, retried: 0, noBackend: 0, upstreamErrors: 0 };

// Releases before eca376af have no /health/ready: a 404 there falls back to Laravel's /up.
function probe(b, path = '/health/ready') {
    const req = http.get({ host: b.url.hostname, port: b.url.port, path, timeout: 5000 }, (res) => {
        res.resume();
        if (res.statusCode === 404 && path !== '/up') { return probe(b, '/up'); }
        if (res.statusCode === 200) { b.fails = 0; b.healthy = true; } else if (++b.fails >= FAILS) { b.healthy = false; }
    });
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', () => { if (++b.fails >= FAILS) b.healthy = false; });
}
setInterval(() => pool.forEach((b) => probe(b)), INTERVAL);

function pick(exclude) {
    for (let i = 0; i < pool.length; i++) {
        const b = pool[(next++) % pool.length];
        if (b.healthy && b !== exclude) return b;
    }
    return null;
}

function forward(req, res, body, attempt, exclude) {
    const b = pick(exclude);
    if (!b) { stats.noBackend++; res.writeHead(503, { 'content-type': 'text/plain' }); return res.end('no healthy backend'); }
    // Appends the caller like nginx's $proxy_add_x_forwarded_for, so a load client can present one address per virtual user.
    const forwarded = req.headers['x-forwarded-for'] ? `${req.headers['x-forwarded-for']}, ${req.socket.remoteAddress}` : req.socket.remoteAddress;
    const headers = { ...req.headers, 'x-forwarded-for': forwarded, 'x-forwarded-proto': 'https',
        'x-forwarded-host': (req.headers.host || '').split(':')[0], 'x-forwarded-port': String(port) };
    const up = http.request({ host: b.url.hostname, port: b.url.port, method: req.method, path: req.url, headers, agent, timeout: 120000 }, (ur) => {
        b.served++;
        res.writeHead(ur.statusCode, ur.headers);
        ur.pipe(res);
    });
    up.on('timeout', () => up.destroy(new Error('upstream timeout')));
    up.on('error', (e) => {
        b.errors++;
        stats.upstreamErrors++;
        const refused = e.code === 'ECONNREFUSED' || e.code === 'ECONNRESET';
        if (refused) { b.fails = FAILS; b.healthy = false; }
        if (refused && attempt === 0 && req.method === 'GET' && !res.headersSent) {
            stats.retried++;
            return forward(req, res, body, 1, b);
        }
        if (!res.headersSent) { res.writeHead(502, { 'content-type': 'text/plain' }); }
        res.end('bad gateway');
    });
    up.end(body);
}

https.createServer({ key: fs.readFileSync(keyFile), cert: fs.readFileSync(certFile) }, (req, res) => {
    if (req.url === '/__lb/status') {
        res.writeHead(200, { 'content-type': 'application/json' });
        return res.end(JSON.stringify({ stats, pool: pool.map((b) => ({ url: b.url.href, healthy: b.healthy, served: b.served, errors: b.errors })) }));
    }
    stats.requests++;
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => forward(req, res, Buffer.concat(chunks), 0, null));
}).listen(Number(port), '127.0.0.1', () => console.log(`tls-lb on https://127.0.0.1:${port} -> ${pool.length} nodes`));
