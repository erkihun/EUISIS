'use strict';
// Aggregates results/roles/*.json into a per-role table and a cross-role issue index.
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'results', 'roles');
const rows = fs.readdirSync(dir).filter((f) => f.endsWith('.json')).map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')))
    .sort((a, b) => a.role.localeCompare(b.role));

const table = [];
const byKind = {};
for (const r of rows) {
    const visits = r.visits || [];
    const okVisits = visits.filter((v) => v.status === 200 && !(v.exceptions || []).length && !v.error).length;
    const probes = r.probes || [];
    const refused = probes.filter((p) => p.status === 403 || p.status === 404 || p.status === 302).length;
    const open = probes.filter((p) => p.status === 200).length;
    const signOut = (r.steps || []).find((s) => s.step === 'sign out');
    const kinds = {};
    for (const i of r.issues) {
        kinds[i.kind] = (kinds[i.kind] || 0) + 1;
        (byKind[i.kind] = byKind[i.kind] || []).push({ role: r.role, ...i });
    }
    table.push({
        role: r.role, scope: r.scope, perms: r.permissionCount, mfa: (r.log || []).some((l) => /mfa/.test(l)),
        landing: r.landing, sidebar: (r.sidebar || []).length, opened: `${okVisits}/${visits.length}`,
        probes: probes.length, refused, open, signOut: signOut ? signOut.ok : false, fatal: r.fatal || null,
        slowest: visits.reduce((m, v) => Math.max(m, v.ms || 0), 0), kinds,
    });
}
const out = { generated: new Date().toISOString(), roles: rows.length, table, byKind };
// unique paths per issue kind
out.uniquePaths = Object.fromEntries(Object.entries(byKind).map(([k, list]) => {
    const m = {};
    for (const i of list) { const key = i.path || '(none)'; (m[key] = m[key] || []).push(i.role); }
    return [k, Object.entries(m).map(([p, roles]) => ({ path: p, roles: [...new Set(roles)], sample: list.find((x) => (x.path || '(none)') === p) }))];
}));
fs.writeFileSync(path.join(__dirname, 'results', 'aggregate.json'), JSON.stringify(out, null, 2));
console.log(`roles ${rows.length}`);
for (const t of table) console.log(`${t.role.padEnd(34)} ${t.scope.padEnd(11)} p=${String(t.perms).padEnd(4)} mfa=${t.mfa ? 'Y' : '-'} land=${(t.landing || '').padEnd(11)} side=${String(t.sidebar).padEnd(3)} ok=${t.opened.padEnd(8)} probes=${t.probes}/${t.refused}r/${t.open}o out=${t.signOut ? 'Y' : 'N'} slow=${t.slowest} ${t.fatal ? 'FATAL ' + t.fatal : ''} ${JSON.stringify(t.kinds)}`);
console.log('\nunique paths per issue kind:');
for (const [k, list] of Object.entries(out.uniquePaths)) {
    console.log(`\n## ${k} (${list.length})`);
    for (const x of list.slice(0, 40)) console.log(`  ${x.path}  <- ${x.roles.length} roles: ${x.roles.slice(0, 6).join(', ')}${x.roles.length > 6 ? ', ...' : ''}  ${JSON.stringify(Object.fromEntries(Object.entries(x.sample).filter(([kk]) => !['role', 'kind', 'path'].includes(kk)))).slice(0, 220)}`);
}
