# Web performance

How the front end stays fast, what the server must do, and how to measure it.

## What the application does

| Concern | Mechanism |
|---|---|
| JavaScript per page | One shared chunk plus the page's own chunk. Each language's translations are a separate chunk; only the language shown is fetched (`resources/js/i18n/registry.ts`). No whole-bundle prefetch: `Vite::prefetch()` used to pull all 546 chunks (4.3 MB) on every full page load. |
| Route list | `resources/js/ziggy.js`, a committed asset cached for a year, instead of 440 KB inlined into every HTML response. Regenerate after changing named routes: `php artisan routes:export-js` (`tests/Unit/ZiggyRoutesFileTest` fails while it is stale). The command keeps only each route's own parameter patterns, which makes the file a third of Ziggy's default output. |
| Always-loaded code | Components in the chunk every page loads import from the kit (`@euisis/ui`), not the app barrel, and the kit is marked side-effect free, so the table, command palette and toaster stay out of the login page. |
| Charts | The chart library (300 KB) loads after the dashboard's first paint, into a placeholder of the chart's height (`resources/js/Components/dashboard/charts`). |
| Fonts | Self-hosted woff2, `font-display: swap`, metric-matched fallback for Inter. The two first-paint Inter weights (and the Ethiopic face when Amharic leads) are preloaded from the HTML head. |
| Images | Uploads are shrunk and re-encoded on the way in (`App\Support\Images\ImageOptimizer`): logos and seals ≤ 512 px lossless WebP, photos ≤ 1024 px WebP, favicon ≤ 256 px PNG. Lists and trees lazy-load their thumbnails and declare width/height. ID-card artwork is never touched (it is rasterised for print). |
| Existing uploads | `php artisan images:optimize --dry-run`, then without the flag. Rewrites the system logo, favicon and seal, institution logos, user and employee photos, and points the records at the new files. Safe to repeat. |
| Cache headers | `public/.htaccess`: `/build` files (content-hashed) are immutable for a year, uploads for a week, text responses deflated. |

## What the server must do

1. After every deploy: `php artisan optimize` (config, routes, views and events cached) and make sure OPcache is on for the PHP-FPM pool.
2. Compression and caching only work if the web server honours them:
   - **Apache** (Plesk default behind nginx): `mod_headers`, `mod_expires` and `mod_deflate` must be enabled; `public/.htaccess` does the rest.
   - **nginx serving static files directly** (Plesk → Apache & nginx settings → "Serve static files directly by nginx"): `.htaccess` is bypassed for those files, so add under *Additional nginx directives*:

     ```nginx
     gzip on;
     gzip_comp_level 5;
     gzip_min_length 1024;
     gzip_types text/css text/javascript application/javascript application/json image/svg+xml text/plain;

     location ^~ /build/ {
         add_header Cache-Control "public, max-age=31536000, immutable";
         try_files $uri =404;
     }
     location ^~ /storage/ {
         add_header Cache-Control "public, max-age=604800";
         try_files $uri =404;
     }
     ```
     Enable brotli as well when the Plesk nginx build offers it (`brotli on; brotli_types ...` with the same types).
3. Run `php artisan images:optimize` once after this release so the already uploaded logos, favicon and photos are shrunk.

## Measuring

```bash
# Build, then serve the built assets on a free port (never the port the test suite uses).
npm run build
php artisan serve --port=8129

npx lighthouse@12 http://127.0.0.1:8129/login --preset=perf --form-factor=mobile \
  --screenEmulation.mobile --throttling-method=simulate --only-categories=performance \
  --output=html --output-path=./lighthouse-login.html --chrome-flags="--headless=new"
```

For a signed-in page, log in once in a browser, copy the `addis_one_session` and
`XSRF-TOKEN` cookies and pass them with `--extra-headers='{"Cookie":"..."}'`.
Run each page three to five times and compare medians: single runs vary by
hundreds of milliseconds on a developer machine.

## Before and after (2026-10-13)

Lighthouse 12, mobile preset (simulated slow 4G, 4× CPU slowdown), medians of
3–4 runs. "Before" is the committed state served from a worktree; "after" is
this change. Both are served by `php artisan serve`, which sends no
compression, so the third column adds a gzip proxy to show what a production
web server with compression on delivers.

| Page | Metric | Before | After | After + gzip |
|---|---|---|---|---|
| /login | Performance score | 55 | 58 | 81 |
| /login | Largest Contentful Paint | 26.4 s | 12.5 s | 4.8 s |
| /login | Time to Interactive | 39.0 s | 12.5 s | 4.8 s |
| /login | Requests / transfer | 549 / 7.1 MB | 19 / 2.0 MB | 19 / 0.7 MB |
| /dashboard | Performance score | 47 | 40 | 54 |
| /dashboard | Largest Contentful Paint | 19.9 s | 13.8 s | 5.4 s |
| /dashboard | Time to Interactive | 36.6 s | 13.8 s | 5.8 s |
| /dashboard | Requests / transfer | 570 / 7.7 MB | 41 / 2.3 MB | 41 / 0.8 MB |
| /dashboard | Main-thread script evaluation | 1.76 s | 1.01 s | 1.0 s |
| /dashboard | Total Blocking Time (simulated) | 352 ms | 641 ms | 752 ms |
| /dashboard | Cumulative Layout Shift | 0.014 | 0.004 | 0.004 |

The dashboard's simulated Total Blocking Time rose although its measured
main-thread work fell by 45 %: in the "before" trace the page's main render
task lands after the modelled Time to Interactive (the 546 prefetches push it
to 36 s), so the simulator does not count it; after the change the same task
sits inside the much shorter window and is counted in full. The total work,
which is what a phone's CPU actually spends, is in the row above it.

Uploaded images on the development copy: 3.3 MB → 0.7 MB (`images:optimize`).
