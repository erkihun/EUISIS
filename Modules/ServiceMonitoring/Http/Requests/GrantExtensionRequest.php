<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** A time-boxed extension with a reason of at least 20 characters (D6). */
final class GrantExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $participant = Participant::query()->find($this->input('participant_id'));

        return $participant !== null && $this->user()->can('grantExtension', $participant);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'uuid'],
            'due_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }
}
