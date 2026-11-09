<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

/**
 * What one anonymous request may do (docs/public-assistant/public-capabilities.md).
 * No user, no grants: capabilities are settings, the page kind comes from the
 * route registry and the target from the server-side QR resolver.
 *
 * @param  array{knowledge: bool, services: bool, support: bool, status: bool}  $capabilities
 * @param  array{kind: string, route: ?string, path: string, form_type: ?string, token: ?string, target: ?array{type: string, name: string, position_title: ?string, organization_id: ?string, phone: ?array{href: string, display: string}, links: array<string, string>}}  $page
 */
final readonly class PublicAssistantContext
{
    public function __construct(
        public string $locale,
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

    public function kind(): string
    {
        return $this->page['kind'];
    }

    public function isQrPage(): bool
    {
        return in_array($this->page['kind'], ['employee_qr', 'organization_qr', 'feedback_form'], true);
    }

    /** @return array<string, mixed> */
    public function pageForClient(): array
    {
        $target = $this->page['target'];

        return [
            'kind' => $this->page['kind'],
            'label' => (string) __('public_assistant.pages.'.$this->page['kind'].'.label', [], $this->locale),
            'form_type' => $this->page['form_type'],
            'target' => $target === null ? null : ['type' => $target['type'], 'name' => $target['name']],
        ];
    }
}
