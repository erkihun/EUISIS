<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DomainEvents;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P4/P5 (ADR 0011): the OpenAPI file documents the integration API that
 * exists — every route, no invented one, every event type and every card code
 * the code can answer.
 */
class CoreOpenApiTest extends TestCase
{
    private function spec(): string
    {
        return (string) file_get_contents(base_path('../docs/openapi/core-integration-v1.yaml'));
    }

    public function test_every_integration_route_is_documented_and_nothing_else(): void
    {
        preg_match_all('/^  (\/[^:]+):\n((?:    [a-z]+:\n(?:      .*\n|        .*\n|          .*\n|            .*\n|              .*\n|                .*\n|                  .*\n|                    .*\n|                      .*\n|\n)*)+)/m', $this->spec(), $blocks, PREG_SET_ORDER);
        $documented = [];
        foreach ($blocks as [, $path, $body]) {
            preg_match_all('/^    (get|post|put|patch|delete):/m', $body, $methods);
            foreach ($methods[1] as $method) {
                $documented[] = strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path);
            }
        }
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/integration/'))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')
                ->map(fn ($m) => $m.' '.preg_replace('/\{[^}]+\}/', '{}', substr($r->uri(), strlen('api/v1/integration')))))
            ->values()->all();
        sort($documented);
        sort($routes);
        $this->assertSame($routes, $documented);
    }

    public function test_every_event_type_and_card_code_is_documented(): void
    {
        $spec = $this->spec();
        foreach (array_unique(array_merge(...array_values(DomainEvents::MAP))) as $event) {
            $this->assertStringContainsString($event, $spec, "Event {$event} is not documented.");
        }
        $codes = [];
        foreach (['app/Models/IdCard.php', 'app/Services/CredentialVerifier.php'] as $file) {
            preg_match_all("/'((?:CARD|CREDENTIAL|KEY|EMPLOYEE|ASSIGNMENT)_[A-Z_]+)'/", (string) file_get_contents(base_path($file)), $found);
            $codes = [...$codes, ...$found[1]];
        }
        foreach (array_unique($codes) as $code) {
            $this->assertStringContainsString($code, $spec, "Card code {$code} is not documented.");
        }
    }
}
