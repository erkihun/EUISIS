// Turns k6 --summary-export files into the markdown rows used in
// docs/production-readiness/load-test-results.md.
//   node loadtest/k6/summarize.mjs <label>=<summary.json> [...]
import { readFileSync } from 'node:fs';

const fmt = (v) => (v === undefined ? '–' : v >= 100 ? Math.round(v).toString() : v.toFixed(1));
const rows = [];
for (const arg of process.argv.slice(2)) {
    const [label, file] = arg.includes('=') ? arg.split('=') : [arg, arg];
    const m = JSON.parse(readFileSync(file, 'utf8')).metrics;
    const d = m.http_req_duration;
    const reqs = m.http_reqs;
    const unexpected = m.api_unexpected_errors;
    const failed = m.http_req_failed;
    rows.push(`| ${label} | ${reqs.count} | ${fmt(reqs.rate)} | ${fmt(d.med)} | ${fmt(d['p(95)'])} | ${fmt(d['p(99)'])} | ${fmt(d.max)} | `
        + `${unexpected ? (unexpected.value * 100).toFixed(2) : '–'} % | ${failed ? (failed.value * 100).toFixed(2) : '–'} % | `
        + `${m.api_not_found?.count ?? 0} | ${m.api_auth_denied?.count ?? 0} | ${m.api_throttled?.count ?? 0} | ${m.api_conflicts?.count ?? 0} | ${m.api_business_denied?.count ?? 0} | ${m.dropped_iterations?.count ?? 0} |`);
    const per = Object.keys(m).filter((k) => k.startsWith('http_req_duration{name:')).sort();
    for (const k of per) {
        const s = m[k];
        if (!s.count) continue;
        rows.push(`|   ↳ ${k.slice(23, -1)} | ${s.count ?? '–'} | | ${fmt(s.med)} | ${fmt(s['p(95)'])} | ${fmt(s['p(99)'])} | ${fmt(s.max)} | | | | | | | | |`);
    }
}
console.log('| Run | Requests | Req/s | p50 ms | p95 ms | p99 ms | max ms | Unexpected (5xx/timeout) | Non-2xx/3xx | 404 (scope) | 401/403 | 429 | 409 | allowed=false | Dropped iterations |');
console.log('| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |');
console.log(rows.join('\n'));
