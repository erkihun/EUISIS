<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use App\Rules\RichTextContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\LetterClassification;
use Modules\Letters\Domain\Enums\LetterPriority;
use Modules\Letters\Domain\Models\Letter;

/** Composing or editing an outgoing or internal letter. */
final class ComposeLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $letter = $this->route('letter');
        if ($letter instanceof Letter) {
            return $user->can('update', $letter);
        }

        return app(LettersAccess::class)->canActFor($user, 'letters.compose', $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $editing = $this->route('letter') instanceof Letter;
        $organizationId = $editing ? $this->route('letter')->organization_id : $this->input('organization_id');

        return [
            'direction' => [$editing ? 'nullable' : 'required', Rule::in(['outgoing', 'internal'])],
            'organization_id' => [$editing ? 'nullable' : 'required', 'uuid', Rule::exists('organizations', 'id')],
            'unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', $organizationId)],
            'recipient_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'external_party_name' => ['nullable', 'string', 'max:255'],
            'external_party_address' => ['nullable', 'string', 'max:500'],
            'external_party_email' => ['nullable', 'email', 'max:160'],
            'file_class_id' => ['nullable', 'uuid', Rule::exists('letter_file_classes', 'id')->where(fn ($q) => $q->where('active', true)->where(fn ($w) => $w->whereNull('organization_id')->orWhere('organization_id', $organizationId)))],
            'letterhead_id' => ['nullable', 'uuid', Rule::exists('letter_letterheads', 'id')->where(fn ($q) => $q->where('active', true)->where(fn ($w) => $w->whereNull('organization_id')->orWhere('organization_id', $organizationId)))],
            'subject' => [$editing ? 'required' : 'required_without:template_version_id', 'nullable', 'string', 'max:500'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'body' => ['nullable', 'string', 'max:100000', new RichTextContent(50000)],
            'language' => ['nullable', Rule::in(['am', 'en'])],
            'priority' => ['nullable', Rule::in(LetterPriority::values())],
            'classification' => ['nullable', Rule::in(LetterClassification::values())],
            'file_code' => ['nullable', 'string', 'max:60'],
            'copies' => ['nullable', 'array', 'max:20'],
            'copies.*.organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'copies.*.external_name' => ['nullable', 'string', 'max:255'],
            'copies.*.external_address' => ['nullable', 'string', 'max:500'],
            'letter_date' => ['nullable', 'date'],
            'template_version_id' => ['nullable', 'uuid', Rule::exists('letter_template_versions', 'id')],
            'template_context' => ['nullable', 'array', 'max:60'],
            'template_context.*' => ['nullable', 'string', 'max:2000'],
            'signatory_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'supersedes_letter_id' => ['nullable', 'uuid', Rule::exists('letters', 'id')],
        ];
    }
}
