<?php

declare(strict_types=1);

namespace App\Services\PublicSite;

use App\Models\PublicAnnouncement;
use App\Models\PublicFaq;
use App\Models\PublicService;
use Illuminate\Validation\Rule;

/** Fixed editor schemas; never accept model names or arbitrary columns from a request. */
final class PublicSiteEditor
{
    public const PAGES = [
        'home' => 'public_home',
        'announcements' => 'public_announcements',
        'services' => 'public_services',
        'support' => 'public_support',
    ];

    public static function collection(string $kind): array
    {
        return match ($kind) {
            'announcements' => [PublicAnnouncement::class, 'public_announcements', 'title'],
            'services' => [PublicService::class, 'public_services', 'name'],
            'faqs' => [PublicFaq::class, 'public_support', 'question'],
            default => abort(404),
        };
    }

    public static function fields(string $kind): array
    {
        $bilingual = match ($kind) {
            'announcements' => ['title' => 255, 'summary' => 500, 'content' => 20000],
            'services' => ['name' => 255, 'short_description' => 500, 'full_description' => 20000, 'eligibility' => 10000, 'requirements' => 10000, 'steps' => 10000],
            'faqs' => ['question' => 500, 'answer' => 10000],
            default => abort(404),
        };
        $fields = [];
        foreach ($bilingual as $name => $max) {
            foreach (['en', 'am'] as $locale) {
                $fields[] = ['key' => "{$name}_{$locale}", 'type' => $max >= 500 ? 'textarea' : 'text', 'max' => $max,
                    'required' => $locale === 'en' && in_array($name, ['title', 'name', 'question', 'answer'], true)];
            }
        }
        foreach (match ($kind) {
            'announcements' => ['slug' => 160, 'category' => 60],
            'services' => ['code' => 40, 'slug' => 160, 'contact_info' => 255, 'action_route' => 120, 'action_url' => 500],
            default => ['category' => 60],
        } as $key => $max) {
            $fields[] = ['key' => $key, 'type' => $key === 'action_route' ? 'route' : 'text', 'max' => $max, 'required' => in_array($key, ['slug', 'code'], true)];
        }
        if ($kind !== 'announcements') {
            $fields[] = ['key' => 'sort_order', 'type' => 'number', 'required' => true, 'max' => 10000];
        }
        $fields[] = ['key' => $kind === 'faqs' ? 'is_published' : 'is_featured', 'type' => 'boolean', 'required' => true];

        return $fields;
    }

    public static function rules(string $kind, ?string $id): array
    {
        [$model] = self::collection($kind);
        $rules = [];
        foreach (self::fields($kind) as $field) {
            $key = $field['key'];
            $rules[$key] = [$field['required'] ? 'required' : 'nullable', ...match ($field['type']) {
                'boolean' => ['boolean'],
                'number' => ['integer', 'min:0', 'max:10000'],
                default => ['string', 'max:'.$field['max']],
            }];
            if (in_array($key, ['slug', 'code'], true)) {
                $rules[$key][] = Rule::unique((new $model)->getTable(), $key)->ignore($id);
            }
            if ($key === 'slug') {
                $rules[$key][] = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
                $rules[$key][] = Rule::notIn(['transfers']);
            }
        }
        if ($kind === 'services') {
            $rules['action_route'][] = Rule::in(array_keys(PublicUrlPolicy::ROUTES));
            $rules['action_url'][] = static function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value && ! PublicUrlPolicy::isAllowedExternalUrl($value)) {
                    $fail(__('validation.public_site.url_not_allowed'));
                }
            };
            $rules['action_url'][] = Rule::prohibitedIf(request()->filled('action_route'));
        }

        return $rules;
    }
}
