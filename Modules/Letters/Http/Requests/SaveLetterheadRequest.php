<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Letters\Application\Services\LetterLayout;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\LetterLetterhead;

/**
 * One header-and-footer layout: its name, default and active flags, the
 * composed page, header and footer (sent as one JSON document, `layout`), the
 * images it adds or removes and the official stamp. The owner of an existing
 * layout never changes; a new one names its institution (empty for a
 * platform layout, which needs city-wide reach).
 */
final class SaveLetterheadRequest extends FormRequest
{
    /** @var array<string, mixed>|null */
    private ?array $decoded = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $layout = $this->route('letterhead');
        $owner = $layout instanceof LetterLetterhead ? $layout->organization_id : $this->input('organization_id');

        return app(LettersAccess::class)->canManageLetterhead($user, is_string($owner) && $owner !== '' ? $owner : null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'layout' => ['required', 'string', 'max:200000'],
            'new_images' => ['nullable', 'array', 'max:'.LetterLayout::MAX_IMAGES],
            'new_images.*' => ['file', 'max:2048'],
            'image_names' => ['nullable', 'array'],
            'image_names.*' => ['nullable', 'string', 'max:120'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['string', 'max:24'],
            'stamp' => ['nullable', 'file', 'max:2048'],
            'remove_stamp' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $decoded = json_decode((string) $this->input('layout'), true);
            if (! is_array($decoded)) {
                $validator->errors()->add('layout', __('letters.validation.layout_invalid'));

                return;
            }
            foreach (array_keys((array) $this->file('new_images', [])) as $key) {
                if (! is_string($key) || preg_match('/^[a-z0-9_]{1,24}$/', $key) !== 1 || $key === LetterLayout::ORG_LOGO) {
                    $validator->errors()->add('new_images', __('letters.validation.layout_invalid'));

                    return;
                }
            }
            $unknown = [];
            foreach (['header', 'footer'] as $section) {
                foreach ((array) ($decoded[$section]['rows'] ?? []) as $row) {
                    foreach ((array) ($row['cells'] ?? []) as $cell) {
                        foreach ((array) ($cell['items'] ?? []) as $item) {
                            if (is_array($item) && ($item['type'] ?? 'text') === 'text') {
                                $unknown = [...$unknown, ...LetterLayout::unknownPlaceholders((string) ($item['text'] ?? ''))];
                            }
                        }
                    }
                }
            }
            if ($unknown !== []) {
                $validator->errors()->add('layout', __('letters.validation.layout_placeholders', ['keys' => implode(', ', array_map(fn ($k) => '{{'.$k.'}}', array_unique($unknown)))]));

                return;
            }
            $this->decoded = $decoded;
        });
    }

    /** @return array<string, mixed> the decoded page, header and footer */
    public function layout(): array
    {
        return $this->decoded ?? (array) json_decode((string) $this->input('layout'), true);
    }
}
