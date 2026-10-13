<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ExportJsRoutes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The route list the browser uses is a committed asset (resources/js/ziggy.js)
 * rather than 440 KB inlined into every HTML response. It therefore has to be
 * regenerated whenever a named route is added, renamed or removed.
 */
class ZiggyRoutesFileTest extends TestCase
{
    public function test_committed_route_list_matches_the_application_routes(): void
    {
        $relative = 'storage/framework/testing/ziggy-fresh-'.getmypid().'.js';

        Artisan::call('routes:export-js', ['path' => $relative, '--url' => 'http://localhost']);

        $generated = File::get(base_path($relative));
        File::delete(base_path($relative));

        $this->assertSame(
            $generated,
            File::get(resource_path('js/ziggy.js')),
            'resources/js/ziggy.js is out of date. Run `php artisan routes:export-js` and commit the result.',
        );
    }

    public function test_a_route_keeps_only_the_patterns_of_its_own_parameters(): void
    {
        $uuid = '[\da-fA-F]{8}';
        $route = [
            'uri' => 'grievances/cases/{grievance}/letters/{letter}/{page?}',
            'methods' => ['GET', 'HEAD'],
            'wheres' => ['grievance' => $uuid, 'letter' => $uuid, 'page' => '\d+', 'evidence' => $uuid, 'hearing' => $uuid],
            'parameters' => ['grievance', 'letter', 'page'],
        ];

        $pruned = ExportJsRoutes::prune($route);

        $this->assertSame(['grievance' => $uuid, 'letter' => $uuid, 'page' => '\d+'], $pruned['wheres']);
        $this->assertArrayNotHasKey('wheres', ExportJsRoutes::prune(['uri' => 'dashboard', 'methods' => ['GET'], 'wheres' => ['grievance' => $uuid]]));
        $this->assertSame(['uri' => 'login', 'methods' => ['GET']], ExportJsRoutes::prune(['uri' => 'login', 'methods' => ['GET']]));
    }
}
