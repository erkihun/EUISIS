'use strict';
// Round-robin HTTP proxy in front of several single-threaded `php -S` nodes.
// Usage: node proxy.js <listenPort> <firstNodePort> <nodeCount>
const http = require('http');
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');

const listen = Number(process.argv[2] || 8123);
const first = Number(process.argv[3] || 8131);
const count = Number(process.argv[4] || 6);
const repo = 'C:/Users/Erkis/Documents/GitHub/EUISIS';
const router = `${repo}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;

const nodes = [];
for (let i = 0; i < count; i++) {
    const port = first + i;
    const child = spawn('php', [
        '-d', 'zend_extension=opcache', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0',
        '-d', 'opcache.memory_consumption=256', '-d', 'memory_limit=512M', '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=80M',
        '-S', `127.0.0.1:${port}`, router,
    ], { cwd: `${repo}/public`, stdio: ['ignore', 'ignore', 'pipe'], env: { ...process.env, PHP_CLI_SERVER_WORKERS: '1', MFA_ENFORCE: 'true' } });
    child.stderr.on('data', (d) => { const s = String(d); if (/Fatal|Failed to listen|Address already/i.test(s)) process.stderr.write(`[node ${port}] ${s}`); });
    child.on('exit', (c) => process.stderr.write(`[node ${port}] exited ${c}\n`));
    nodes.push({ port, busy: 0 });
}

let rr = 0;
function pick() {
    // Least busy, round-robin tie-break.
    let best = null;
    for (let k = 0; k < nodes.length; k++) {
        const n = nodes[(rr + k) % nodes.length];
        if (!best || n.busy < best.busy) best = n;
    }
    rr = (rr + 1) % nodes.length;
    return best;
}

const MIME = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon', '.json': 'application/json', '.webmanifest': 'application/manifest+json', '.txt': 'text/plain', '.map': 'application/json' };
function tryStatic(req, res) {
    if (req.method !== 'GET' && req.method !== 'HEAD') return false;
    const p = decodeURIComponent(req.url.split('?')[0]);
    if (p.includes('..') || p === '/' || /.php$/i.test(p)) return false;
    const file = path.join(repo, 'public', p);
    let st; try { st = fs.statSync(file); } catch { return false; }
    if (!st.isFile()) return false;
    res.writeHead(200, { 'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream', 'content-length': st.size, 'cache-control': p.startsWith('/build/') ? 'public, max-age=31536000, immutable' : 'no-cache' });
    if (req.method === 'HEAD') return res.end(), true;
    fs.createReadStream(file).pipe(res);
    return true;
}
const server = http.createServer((req, res) => {
    if (tryStatic(req, res)) return;
    const node = pick();
    node.busy++;
    const headers = { ...req.headers, 'x-forwarded-for': '127.0.0.1' };
    const up = http.request({ host: '127.0.0.1', port: node.port, method: req.method, path: req.url, headers }, (ur) => {
        res.writeHead(ur.statusCode, ur.rawHeaders.length ? arrayHeaders(ur.rawHeaders) : ur.headers);
        ur.pipe(res);
        ur.on('end', () => { node.busy--; });
    });
    up.setTimeout(120000, () => up.destroy(new Error('upstream timeout')));
    up.on('error', (e) => {
        node.busy--;
        if (!res.headersSent) res.writeHead(502, { 'content-type': 'text/plain' });
        res.end(`proxy error on node ${node.port}: ${e.message}`);
    });
    req.pipe(up);
});
function arrayHeaders(raw) {
    const out = {};
    for (let i = 0; i < raw.length; i += 2) {
        const k = raw[i]; const v = raw[i + 1];
        if (out[k] === undefined) out[k] = v; else out[k] = [].concat(out[k], v);
    }
    return out;
}
server.keepAliveTimeout = 5000;
server.listen(listen, '127.0.0.1', () => console.log(`proxy :${listen} -> ${nodes.map((n) => n.port).join(',')}`));
process.on('SIGTERM', () => { for (const n of nodes) try { process.kill(n.pid); } catch {} process.exit(0); });
