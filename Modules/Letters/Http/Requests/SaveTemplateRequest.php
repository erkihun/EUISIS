<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use App\Rules\RichTextContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\TemplateCategory;
use Modules\Letters\Domain\Models\LetterTemplate;

/** Creating or editing a template's draft. */
final class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $template = $this->route('template');
        if ($template instanceof LetterTemplate) {
            return $user->can('update', $template);
        }
        $owner = $this->input('owner_organization_id');

        return app(LettersAccess::class)->canManageTemplate($user, is_string($owner) && $owner !== '' ? $owner : null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title_en' => ['required', 'string', 'max:200'],
            'title_am' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', Rule::in(TemplateCategory::values())],
            'language' => ['nullable', Rule::in(['am', 'en'])],
            'subject' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:100000', new RichTextContent(50000)],
            'placeholders' => ['nullable', 'array', 'max:60'],
            'placeholders.*.key' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'placeholders.*.label_en' => ['nullable', 'string', 'max:120'],
            'placeholders.*.label_am' => ['nullable', 'string', 'max:120'],
            'placeholders.*.required' => ['nullable', 'boolean'],
        ];
    }
}
