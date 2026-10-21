'use strict';
// Warm every php -S node (opcache) through the proxy and report timings.
(async () => {
    const out = [];
    for (let i = 0; i < 18; i++) {
        const t = Date.now();
        const r = await fetch((process.env.BASE_URL || 'http://127.0.0.1:8123') + '/login');
        const body = await r.text();
        out.push(`${r.status}/${Date.now() - t}ms/${body.includes('data-page') ? 'inertia' : 'no-inertia'}`);
    }
    console.log(out.join(' '));
})();
