<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\ServiceFeedback\PublicEvidencePolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * One evidence file: a real upload whose content and extension are both on
 * PublicEvidencePolicy's lists, within the size limit for the kind its
 * content is. The content decides — a script renamed photo.jpg is refused.
 */
final class PublicEvidenceFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('service-feedback.validation.attachment_type')->translate();

            return;
        }

        $extension = strtolower((string) $value->getClientOriginalExtension());
        $kind = PublicEvidencePolicy::kindOf($value);

        if ($kind === null || ! in_array($extension, PublicEvidencePolicy::extensions(), true)) {
            $fail('service-feedback.validation.attachment_type')->translate();

            return;
        }

        $maxKb = PublicEvidencePolicy::KINDS[$kind]['max_kb'];

        if ((int) $value->getSize() > $maxKb * 1024) {
            $fail('service-feedback.validation.attachment_size_kind')->translate([
                'kind' => __('service-feedback.evidence_kind.'.$kind),
                'max' => intdiv($maxKb, 1024),
            ]);
        }
    }
}
