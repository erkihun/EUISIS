<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\User;

/**
 * What one request may do (docs/assistant/context-and-tools.md). Built by the
 * resolver from the user's grants, scope and the page the browser named;
 * never from anything the browser claims about authorization.
 *
 * @param  array{help: bool, knowledge: bool, analytics: bool, diagnostics: bool, incidents: bool, support: bool}  $capabilities
 * @param  array{route: ?string, path: string, module: string, module_label: string, help_available: bool, route_group: string, record: ?array{type: string, id: string, label: string, summary: array<string, string|null>}}  $page
 */
final readonly class AssistantContext
{
    public function __construct(
        public User $user,
        public string $locale,
        public bool $unrestricted,
        public array $capabilities,
        public array $page,
        public bool $aiAvailable,
    ) {}

    public function can(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }

    public function status(): string
    {
        return $this->aiAvailable ? 'ready' : 'limited';
    }

    /** @return array<string, mixed> */
    public function pageForClient(): array
    {
        $page = $this->page;

        return [
            'route' => $page['route'], 'module' => $page['module'], 'module_label' => $page['module_label'], 'help_available' => $page['help_available'],
            'record' => $page['record'] === null ? null : ['type' => $page['record']['type'], 'label' => $page['record']['label']],
        ];
    }
}
