<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use App\Rules\RichTextContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Communication\Application\Services\AnnouncementTargeting;
use Modules\Communication\Domain\Enums\AnnouncementPriority;
use Modules\Communication\Domain\Enums\AnnouncementTargetType;

/**
 * An announcement draft and what to do with it: keep as draft, publish now,
 * or schedule (authorization and scope: SaveAnnouncementAction and
 * PublishAnnouncementAction).
 */
class SaveAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'title_am' => ['nullable', 'string', 'max:200'],
            'body' => [RichTextContent::required(maxLength: 20000)],
            'body_am' => ['nullable', new RichTextContent(maxLength: 20000)],
            'priority' => ['required', Rule::in(AnnouncementPriority::values())],
            'acknowledgement_required' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'targets' => ['required', 'array', 'min:1', 'max:'.AnnouncementTargeting::MAX_TARGETS],
            'targets.*.type' => ['required', Rule::in(AnnouncementTargetType::values())],
            'targets.*.id' => ['nullable', 'string', 'max:64'],
            'intent' => ['nullable', Rule::in(['draft', 'publish', 'schedule'])],
            'publish_at' => ['nullable', 'required_if:intent,schedule', 'date', 'after:now'],
        ];
    }
}
