// Synthetic webhook receiver for the delivery-throughput and partner-outage
// tests (docs/production-readiness/queue-readiness.md). Never point real
// partners here, and never point benchmarks at real partners.
//
//   node loadtest/rig/webhook-receiver.mjs <port> [mode]
//   mode: ok (200 to everything, default) | down (503 to everything) | slow (200 after DELAY_MS)
//   GET  /__stats  → {received, unique, duplicates, byType, first, last}
//   POST /__mode/<mode> switches mode at runtime (simulates a partner outage and recovery).
import http from 'node:http';

const port = Number(process.argv[2] || 9099);
let mode = process.argv[3] || 'ok';
const delay = Number(process.env.DELAY_MS || 500);
const seen = new Set();
const stats = { received: 0, accepted: 0, rejected: 0, duplicates: 0, byType: {}, first: null, last: null };

http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/__stats') {
        res.writeHead(200, { 'content-type': 'application/json' });
        return res.end(JSON.stringify({ mode, unique: seen.size, ...stats }));
    }
    if (req.method === 'POST' && req.url.startsWith('/__mode/')) {
        mode = req.url.slice(8);
        res.writeHead(204);
        return res.end();
    }
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
        stats.received++;
        const now = Date.now();
        stats.first ??= now;
        stats.last = now;
        if (mode === 'down') {
            stats.rejected++;
            res.writeHead(503);
            return res.end();
        }
        const answer = () => {
            try {
                const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
                // One delivery = one subscription × event; the same event reaches every subscription.
                const id = req.headers['addis-delivery-id'] || `${body.event_id || body.id}-${req.url}` || `${now}-${Math.random()}`;
                if (seen.has(id)) stats.duplicates++; else seen.add(id);
                const type = body.event_type || body.type || 'unknown';
                stats.byType[type] = (stats.byType[type] || 0) + 1;
            } catch { /* count it anyway */ }
            stats.accepted++;
            res.writeHead(200, { 'content-type': 'application/json' });
            res.end('{"ok":true}');
        };
        if (mode === 'slow') setTimeout(answer, delay); else answer();
    });
}).listen(port, '127.0.0.1', () => console.log(`webhook receiver on ${port} (${mode})`));
