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

    /**
     * The template a card should be rendered with.
     *
     * A template is built for one orientation: its background artwork, its
     * layout boxes and its millimetres all assume that shape. Applying a
     * landscape template to a portrait card puts every element in the wrong
     * place, so an orientation only ever resolves to a template built for it —
     * and falls back to the built-in arrangement when none exists, rather than
     * borrowing the other orientation's.
     *
     * Passing no orientation keeps the old meaning: the default template,
     * whatever shape it is.
     */
    public function active(?string $orientation = null): ?IdCardTemplate
    {
        $query = IdCardTemplate::query()->where('status', 'active');

        if ($orientation === null) {
            return $query->where('is_default', true)->first();
        }

        $query->where('orientation', $orientation);

        // The default for that orientation wins; otherwise the most recently
        // updated one, so a single portrait template is picked up without an
        // administrator having to make it the global default.
        return (clone $query)->where('is_default', true)->first()
            ?? $query->orderByDesc('updated_at')->first();
    }

    public function safePath(?string $path): bool
    {
        return $path !== null && preg_match('#^id-card-templates/[a-zA-Z0-9-]+\\.png$#D', $path) === 1;
    }

    /**
     * Front-header content, with each blank field falling back to the global
     * setting and the logo falling back to the organization's own.
     */
    public function header(?IdCardTemplate $template, IdCardLayoutSettings $layout, ?string $orgLogoDataUri = null): IdCardHeaderContent
    {
        return IdCardHeaderContent::fromArray(
            $template?->header_config,
            [
                'city_name_en' => $layout->cityNameEn,
                'city_name_am' => $layout->cityNameAm,
                'bureau_name_en' => $layout->bureauNameEn,
                'bureau_name_am' => $layout->bureauNameAm,
            ],
            $layout->showOrganizationLogo,
            // Each slot prefers the template's own upload; the organization
            // logo remains the fallback for the secondary mark only.
            $this->dataUri($template?->logo_primary_path),
            $this->dataUri($template?->logo_secondary_path) ?? $orgLogoDataUri,
        );
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
            'employee_fields' => $template->employee_fields,
            'width_mm' => $template->width_mm ?? ($portrait ? 54 : 85.6),
            'height_mm' => $template->height_mm ?? ($portrait ? 85.6 : 54),
            'front_background_url' => $this->url($template, 'front'),
            'back_background_url' => $this->url($template, 'back'),
            'text_style_config' => $this->resolvedStyles($template),
            'layout_config' => $this->resolvedLayout($template),
            // Resolved so the editor previews what the card will actually show,
            // and raw so a blank override stays blank in the form.
            'header_config' => $this->header($template, $this->layoutSettings->get())->toArray(),
            'header_overrides' => $this->headerOverrides($template),
            'logo_primary_url' => $this->logoUrl($template, 'primary'),
            'logo_secondary_url' => $this->logoUrl($template, 'secondary'),
            'seal_url' => $this->markUrl($template, 'seal'),
            'signature_url' => $this->markUrl($template, 'signature'),
            'back_photo_config' => $this->backPhoto($template)->toArray(),
        ];
    }

    /**
     * What the template itself stores, so the editor shows an empty box for a
     * field that is inheriting rather than the inherited text.
     *
     * @return array<string, string>
     */
    public function headerOverrides(?IdCardTemplate $template): array
    {
        $stored = $template?->header_config ?? [];
        $overrides = [];
        foreach (array_keys(IdCardHeaderContent::FIELDS) as $field) {
            $overrides[$field] = trim((string) ($stored[$field] ?? ''));
        }
        $overrides['show_logo'] = ! isset($stored['show_logo']) || (bool) $stored['show_logo'];

        return $overrides;
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

    /** How the back face draws the employee photo, if at all. */
    public function backPhoto(?IdCardTemplate $template): IdCardBackPhoto
    {
        return IdCardBackPhoto::fromArray($template?->back_photo_config);
    }

    /**
     * The template's own seal or signature image, or null when it has none.
     * The seal then falls back to the global `general.seal` setting; the
     * signature simply prints as the ruled line it has always been.
     */
    public function markUrl(?IdCardTemplate $template, string $mark): ?string
    {
        return $template === null
            ? null
            : $this->assetUrl($template, $mark, $template->{$mark.'_path'});
    }

    /** One header logo slot, or null when the template has not uploaded it. */
    public function logoUrl(?IdCardTemplate $template, string $slot): ?string
    {
        return $template === null
            ? null
            : $this->assetUrl($template, 'logo-'.$slot, $template->{'logo_'.$slot.'_path'});
    }

    private function url(IdCardTemplate $template, string $side): ?string
    {
        return $this->assetUrl($template, $side, $template->{$side.'_background_path'});
    }

    private function assetUrl(IdCardTemplate $template, string $side, ?string $path): ?string
    {
        return $this->safePath($path) && Storage::disk('local')->exists($path)
            ? route('id-card-templates.background', [$template, $side, 'v' => hash('sha256', $path)])
            : null;
    }
}
