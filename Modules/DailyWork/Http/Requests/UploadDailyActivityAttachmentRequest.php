<?php

declare(strict_types=1);

namespace Modules\DailyWork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\DailyWork\Application\Services\DailyActivitySettings;

/**
 * Evidence upload. Documents, spreadsheets and images only: the `mimes` rule
 * checks the sniffed content type, so a renamed executable is refused, and
 * `extensions` additionally refuses a misleading client file name.
 */
class UploadDailyActivityAttachmentRequest extends FormRequest
{
    public const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = app(DailyActivitySettings::class)->maxAttachmentSizeKb();
        $allowed = implode(',', self::ALLOWED);

        return [
            'file' => ['required', 'file', "mimes:{$allowed}", "extensions:{$allowed}", "max:{$maxKb}"],
            'item_id' => ['nullable', 'uuid'],
        ];
    }
}
