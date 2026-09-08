<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Models\IdCardTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class IdCardTemplateService
{
    public function __construct(private readonly IdCardLayoutSettingsService $layoutSettings) {}

    public function managementData(User $user, int $uploadLimitMb): array
    {
        return [
            'templates' => IdCardTemplate::query()->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (IdCardTemplate $template): array => [...$template->toArray(), ...$this->presentation($template)]),
            'uploadLimitMb' => $uploadLimitMb,
            'can' => collect(['create', 'update', 'delete', 'set_default'])->mapWithKeys(
                fn (string $ability): array => [$ability => $user->can('id_card_templates.'.$ability)],
            ),
        ];
    }

    public function active(): ?IdCardTemplate
    {
        return IdCardTemplate::query()->where('is_default', true)->where('status', 'active')->first();
    }

    public function safePath(?string $path): bool
    {
        return $path !== null && preg_match('#^id-card-templates/[a-zA-Z0-9-]+\\.png$#D', $path) === 1;
    }

    public function dataUri(?string $path): ?string
    {
        if (! $this->safePath($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    public function presentation(IdCardTemplate $template): array
    {
        $portrait = $template->orientation === 'portrait';

        return [
            'id' => $template->id,
            'orientation' => $template->orientation,
            'width_mm' => $template->width_mm ?? ($portrait ? 54 : 85.6),
            'height_mm' => $template->height_mm ?? ($portrait ? 85.6 : 54),
            'front_background_url' => $this->url($template, 'front'),
            'back_background_url' => $this->url($template, 'back'),
            'text_style_config' => $this->resolvedStyles($template),
            'layout_config' => $this->resolvedLayout($template),
        ];
    }

    /**
     * Where one element sits, resolved against the built-in arrangement.
     */
    public function box(?IdCardTemplate $template, string $side, string $element): IdCardLayoutElement
    {
        $default = IdCardLayoutElement::ELEMENTS[$side][$element]
            ?? ['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0];

        return IdCardLayoutElement::fromArray(
            $template?->layout_config[$side][$element] ?? null,
            $default,
        );
    }

    /**
     * Every element as a box object, for the server-side SVG renderer.
     *
     * @return array<string, array<string, IdCardLayoutElement>>
     */
    public function layoutBoxes(?IdCardTemplate $template): array
    {
        $boxes = [];
        foreach (IdCardLayoutElement::ELEMENTS as $side => $elements) {
            foreach (array_keys($elements) as $element) {
                $boxes[$side][$element] = $this->box($template, $side, $element);
            }
        }

        return $boxes;
    }

    /**
     * Every element resolved to plain percentages, for the client-side renderer
     * and the layout designer.
     *
     * @return array<string, array<string, array{x: float, y: float, w: float, h: float}>>
     */
    public function resolvedLayout(?IdCardTemplate $template): array
    {
        $resolved = [];
        foreach (IdCardLayoutElement::ELEMENTS as $side => $elements) {
            foreach (array_keys($elements) as $element) {
                $resolved[$side][$element] = $this->box($template, $side, $element)->toArray();
            }
        }

        return $resolved;
    }

    /**
     * Resolve one stored style for a side/role pair.
     */
    public function style(?IdCardTemplate $template, string $side, string $role, IdCardLayoutSettings $layout): IdCardTextStyle
    {
        $default = IdCardTextStyle::ROLES[$side][$role] ?? ['size' => '10px', 'weight' => '400', 'color' => 'primary'];

        return IdCardTextStyle::fromArray(
            $template?->text_style_config[$side][$role] ?? null,
            match ($default['color']) {
                'secondary' => $layout->frontTextSecondary,
                'back' => $layout->backTextColor,
                default => $layout->frontTextPrimary,
            },
            $default['size'],
            $default['weight'],
        );
    }

    /**
     * Every role as a style object, for the server-side SVG renderer.
     *
     * @return array<string, array<string, IdCardTextStyle>>
     */
    public function styleObjects(?IdCardTemplate $template, IdCardLayoutSettings $layout): array
    {
        $styles = [];
        foreach (IdCardTextStyle::ROLES as $side => $roles) {
            foreach (array_keys($roles) as $role) {
                $styles[$side][$role] = $this->style($template, $side, $role, $layout);
            }
        }

        return $styles;
    }

    /**
     * Every role resolved to a concrete style, for the client-side renderers.
     *
     * @return array<string, array<string, array{color: string, font_size: string, font_weight: string}>>
     */
    public function resolvedStyles(?IdCardTemplate $template): array
    {
        $layout = $this->layoutSettings->get();
        $resolved = [];
        foreach (IdCardTextStyle::ROLES as $side => $roles) {
            foreach (array_keys($roles) as $role) {
                $resolved[$side][$role] = $this->style($template, $side, $role, $layout)->toArray();
            }
        }

        return $resolved;
    }

    private function url(IdCardTemplate $template, string $side): ?string
    {
        $path = $template->{$side.'_background_path'};

        return $this->safePath($path) && Storage::disk('local')->exists($path)
            ? route('id-card-templates.background', [$template, $side, 'v' => hash('sha256', $path)])
            : null;
    }
}
