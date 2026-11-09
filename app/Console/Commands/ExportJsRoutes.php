<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tighten\Ziggy\Ziggy;

/**
 * Writes the route list the browser's route() helper reads (resources/js/ziggy.js).
 *
 * It is a committed asset, cached by the browser for a year, instead of 440 KB
 * inlined into every HTML response. Run it after adding, renaming or removing a
 * named route; tests/Unit/ZiggyRoutesFileTest fails while the file is stale.
 *
 * Compared with `ziggy:generate` the output is a third of the size: a group-level
 * where() lands on every route in the group, so a route carried the UUID pattern
 * of parameters it does not even have. Only the patterns of a route's own
 * parameters are kept (the ones Laravel and Ziggy actually consult).
 */
class ExportJsRoutes extends Command
{
    protected $signature = 'routes:export-js
        {path=resources/js/ziggy.js : Where to write, relative to the project root}
        {--url=http://localhost : Origin baked into the file; app.tsx replaces it with the page origin at run time}';

    protected $description = 'Export the named routes as the resources/js/ziggy.js module the front end imports';

    public function handle(): int
    {
        $config = (new Ziggy(null, (string) $this->option('url')))->toArray();
        $config['routes'] = array_map([self::class, 'prune'], $config['routes']);

        if ($config['defaults'] === []) {
            $config['defaults'] = (object) [];
        }

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $module = "const Ziggy = {$json};\n"
            ."if (typeof window !== 'undefined' && typeof window.Ziggy !== 'undefined') {\n"
            ."  Object.assign(Ziggy.routes, window.Ziggy.routes);\n"
            ."}\n"
            ."export { Ziggy };\n";

        $path = base_path((string) $this->argument('path'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $module);

        $this->info(sprintf('%s written: %d routes, %s KB.', $this->argument('path'), count($config['routes']), number_format(strlen($module) / 1024, 1)));

        return self::SUCCESS;
    }

    /**
     * Keeps a `wheres` entry only for a parameter that occurs in the route's own URI.
     *
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    public static function prune(array $route): array
    {
        if (! isset($route['wheres']) || ! is_array($route['wheres'])) {
            return $route;
        }

        preg_match_all('/\{(\w+)\??\}/', (string) ($route['uri'] ?? ''), $matches);
        $route['wheres'] = array_intersect_key($route['wheres'], array_flip($matches[1]));

        if ($route['wheres'] === []) {
            unset($route['wheres']);
        }

        return $route;
    }
}
