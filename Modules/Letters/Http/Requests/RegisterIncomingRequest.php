<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\LetterClassification;
use Modules\Letters\Domain\Enums\LetterPriority;

/** Registering a letter that arrived from outside the system. */
final class RegisterIncomingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(LettersAccess::class)->canActFor($user, 'letters.register', $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', $this->input('organization_id'))],
            'sender_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id'), 'different:organization_id'],
            'external_party_name' => ['nullable', 'string', 'max:255', 'required_without:sender_organization_id'],
            'external_party_address' => ['nullable', 'string', 'max:500'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:500'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'language' => ['nullable', Rule::in(['am', 'en'])],
            'priority' => ['nullable', Rule::in(LetterPriority::values())],
            'classification' => ['nullable', Rule::in(LetterClassification::values())],
            'file_code' => ['nullable', 'string', 'max:60'],
            'file_class_id' => ['nullable', 'uuid', Rule::exists('letter_file_classes', 'id')->where(fn ($q) => $q->where('active', true)->where(fn ($w) => $w->whereNull('organization_id')->orWhere('organization_id', $this->input('organization_id'))))],
            'physical_location' => ['nullable', 'string', 'max:120'],
            'letter_date' => ['required', 'date'],
            'received_on' => ['nullable', 'date', 'after_or_equal:letter_date'],
            'due_on' => ['nullable', 'date'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:15360'],
        ];
    }
}
