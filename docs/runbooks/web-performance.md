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
| Images | Uploads are shrunk and re-encoded on the way in (`App\Support\Images\ImageOptimizer`): logos and seals ≤ 512 px lossless WebP, photos ≤ 1024 px WebP, favicon ≤ 256 px PNG. Anything up to 120 megapixels is decoded, with the memory limit raised for that one image when it has to be (a fixed 20-megapixel cap used to leave the very uploads that mattered, a 6,683 px favicon and a 7,473 px logo, untouched). Lists and trees lazy-load their thumbnails and declare width/height. ID-card artwork is never touched (it is rasterised for print). |
| Permissions | The role and permission catalogue (974 permissions) is cached in the application's cache store (`config/permission.php`, `PERMISSION_CACHE_STORE`), not rebuilt from the database on every request: that rebuild cost about 420 ms of server time on every signed-in page. Model changes flush it; `php artisan permission:cache-reset` after a deploy (already in the deployment runbook). |
| Shell chunks | The sign-in page takes its two icons straight from the icon set (`ThemeToggle`), not through `NavIcons`, whose 180 icons would otherwise travel with it. The authenticated layout imports from the kit (`@euisis/ui`), never from the app barrel `@/Components/ui`, which would pull the table and virtualiser libraries into every signed-in page. |
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
3. Run `php artisan images:optimize` once after this release so the already uploaded logos, favicon and photos are shrunk. Run it again after the 2026-11-08 release: the optimiser now handles the images over 20 megapixels that the first run reported as "kept as is". `--dry-run` first shows what would change.
4. Leave `PERMISSION_CACHE_STORE` unset (the application's cache store) or point it at Redis where there is one. Never `array` outside tests.

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

## Before and after (2026-11-08)

Same method: Lighthouse 12, mobile preset (simulated slow 4G, 4× CPU slowdown),
medians of 3 runs, the committed state rebuilt and served by `php artisan serve`
(no compression) and through a gzip proxy (what a web server with compression
on delivers). Cumulative Layout Shift was 0.000 on both pages before and after.

What was found and changed:

- The favicon, logo and seal uploaded in System Settings were 6,683 × 6,683,
  7,473 × 4,368 and 6,821 × 6,821 pixel PNGs (1.3 MB, 0.4 MB, 1.2 MB), and the
  optimiser's fixed 20-megapixel cap left them as uploaded, so the favicon and
  logo alone were two thirds of the login page's bytes. The cap is now
  memory-based (120 megapixels); `images:optimize` rewrote them to 46 KB, 13 KB
  and 38 KB.
- `config/permission.php` used the `array` cache store, so every signed-in
  request rebuilt the 974-permission catalogue from the database: 420 ms of
  the dashboard's server time. It now uses the application's cache store.
- The sign-in page downloaded all 180 shell icons (182 KB) because the theme
  toggle imported two of them through `NavIcons`; the authenticated layout
  imported one class-name helper from the app barrel, which carried the table
  and virtualiser libraries (160 KB) into every signed-in page.

| Page | Metric | Before | After | Before + gzip | After + gzip |
|---|---|---|---|---|---|
| /login | Performance score | 56 | 56 | 60 | 76 |
| /login | First Contentful Paint | 10.4 s | 10.4 s | 2.6 s | 1.7 s |
| /login | Largest Contentful Paint | 26.1 s | 17.3 s | 14.4 s | 6.2 s |
| /login | Time to Interactive | 26.2 s | 17.3 s | 14.5 s | 6.3 s |
| /login | Total Blocking Time | 37 ms | 37 ms | 448 ms | 39 ms |
| /login | Requests / transfer | 34 / 4.7 MB | 34 / 2.9 MB | 34 / 2.5 MB | 34 / 0.85 MB |
| /login | Main-thread work | 3.7 s | 2.4 s | 3.6 s | 2.5 s |
| /dashboard | Performance score | 52 | 52 | 54 | 64 |
| /dashboard | Server response time | 1.2 s | 0.9 s | 2.3 s | 0.6 s |
| /dashboard | Largest Contentful Paint | 29.0 s | 23.9 s | 14.7 s | 8.6 s |
| /dashboard | Time to Interactive | 29.1 s | 24.1 s | 14.7 s | 9.1 s |
| /dashboard | Total Blocking Time | 207 ms | 223 ms | 455 ms | 194 ms |
| /dashboard | Requests / transfer | 69 / 5.6 MB | 72 / 4.0 MB | 69 / 2.8 MB | 72 / 1.2 MB |
| /dashboard | Main-thread work | 2.2 s | 1.4 s | 2.5 s | 1.3 s |

The uncompressed columns are what `php artisan serve` delivers and are bound
by the 1 MB Amharic dictionary and the 415 KB vendor chunk, which compression
shrinks four- to fivefold; production must serve compressed (see above).

What still weighs on every first load, and is structural rather than a fix:
the active language's dictionary (981 KB, 248 KB compressed, for Amharic)
plus the court module's dictionary (about 100 KB compressed), which travels
with it because the sidebar labels the court group from `cm.*` keys. Splitting
dictionaries per namespace, or loading the court tree only on court pages and
for users who see that group, would be the next step. `app.tsx` itself is
118 KB because `import.meta.glob` lists some 900 pages.

### The court dictionary loads only where it is read (2026-11-08)

The court half of that next step is done. Same method, medians of 3 runs,
served through a gzip proxy in front of `php artisan serve` (the proxy must
pass the browser's own `Host` header through, or Laravel writes the asset URLs
on the upstream port and every module script fails cross-origin).

- The court module's dictionary (`court.*` and `cm.*`, ADR 0020) travelled with
  every first page load: the registry fetched it beside the language bundle
  because the sidebar labels the Tribunal Court group from `cm.*` keys. It is
  12 chunks, 235 KB (65 KB compressed) in Amharic and 169 KB (55 KB compressed)
  in English. It now loads only where it is read: `app.tsx` awaits it with a
  `Court/` page's chunk, and the sidebar asks for it (and shows the court group)
  once a user who may see that group is on the page; a language switch brings it
  along for the new language when it was loaded for the previous one
  (`resources/js/i18n/registry.ts`, `ensureCourtLocale`). The sign-in page and
  every page of a user without court permissions no longer download it.

| Page | Metric | Before + gzip | After + gzip |
|---|---|---|---|
| /login | Performance score | 68 | 70 |
| /login | Largest Contentful Paint | 6.3 s | 5.6 s |
| /login | Requests / transfer | 34 / 846 KB | 22 / 778 KB |
| /login | Script requests / transfer | 25 / 616 KB | 13 / 549 KB |
| /employees (HR Officer) | Performance score | 67 | 68 |
| /employees (HR Officer) | Largest Contentful Paint | 6.9 s | 6.3 s |
| /employees (HR Officer) | Requests / transfer | 54 / 969 KB | 42 / 901 KB |
| /employees (HR Officer) | Script requests / transfer | 43 / 735 KB | 31 / 667 KB |
| /employees (HR Officer) | Main-thread work | 0.88 s | 0.64 s |

Twelve requests and 67 KB of compressed script fewer on both pages. A Super
Admin, who sees the Tribunal Court group, still downloads the dictionary, but
after the page has rendered rather than before it.
