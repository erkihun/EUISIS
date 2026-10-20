// Minimal health-checked round-robin load balancer for the failure tests
// (docs/production-readiness/failure-testing.md). Test rig only — not for
// production. Polls GET /health/ready on every backend each INTERVAL ms, takes
// a backend out after FAILS consecutive failures and back in after one success,
// and retries an idempotent request (GET) once on another backend when the
// chosen one refuses the connection — like nginx `proxy_next_upstream error`.
//
//   node loadtest/rig/lb.mjs <listen-port> http://127.0.0.1:8101 http://127.0.0.1:8102 ...
//   GET /__lb/status on the LB port lists backend state and counters.
import http from 'node:http';

const [port, ...backends] = process.argv.slice(2);
const INTERVAL = Number(process.env.LB_INTERVAL || 2000);
const FAILS = Number(process.env.LB_FAILS || 2);
const pool = backends.map((url) => ({ url: new URL(url), healthy: true, fails: 0, served: 0, errors: 0 }));
let next = 0;
const stats = { requests: 0, retried: 0, noBackend: 0, upstreamErrors: 0 };

function probe(b) {
    const req = http.get({ host: b.url.hostname, port: b.url.port, path: '/health/ready', timeout: 3000 }, (res) => {
        res.resume();
        if (res.statusCode === 200) { b.fails = 0; b.healthy = true; } else if (++b.fails >= FAILS) { b.healthy = false; }
    });
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', () => { if (++b.fails >= FAILS) b.healthy = false; });
}
setInterval(() => pool.forEach(probe), INTERVAL);

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
    const headers = { ...req.headers, 'x-forwarded-for': req.socket.remoteAddress, 'x-forwarded-proto': 'http' };
    const up = http.request({ host: b.url.hostname, port: b.url.port, method: req.method, path: req.url, headers, timeout: 60000 }, (ur) => {
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

http.createServer((req, res) => {
    if (req.url === '/__lb/status') {
        res.writeHead(200, { 'content-type': 'application/json' });
        return res.end(JSON.stringify({ stats, pool: pool.map((b) => ({ url: b.url.href, healthy: b.healthy, served: b.served, errors: b.errors })) }));
    }
    stats.requests++;
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => forward(req, res, Buffer.concat(chunks), 0, null));
}).listen(Number(port), '127.0.0.1', () => console.log(`lb on ${port} → ${pool.length} backends`));
