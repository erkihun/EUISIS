<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Application\Services\RegistryQuery;
use Modules\Letters\Domain\Models\LetterRegistration;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The registry as CSV, with the page's filters (letters.export). Confidential
 * letters' subjects are exported only when the exporter may read them; the
 * export itself is audited with its filters and row count.
 */
final class ExportController extends Controller
{
    public function __invoke(Request $request, RegistryQuery $registry, LettersAccess $access, LettersAudit $audit): StreamedResponse
    {
        abort_unless($request->user()->can('letters.export'), 403);
        $user = $request->user();
        $filters = [
            'direction' => (string) $request->string('direction'), 'status' => (string) $request->string('status'), 'organization_id' => (string) $request->string('organization_id'),
            'q' => trim((string) $request->string('q')), 'from' => (string) $request->string('from'), 'to' => (string) $request->string('to'),
        ];
        $query = $registry->build($user, $filters)->limit(10000);
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';
        $name = static fn ($model) => $model === null ? '' : (string) (($locale === 'am' && $model->name_am) ? $model->name_am : ($model->name_en ?? $model->name_am ?? ''));
        $rows = 0;

        $response = response()->streamDownload(function () use ($query, $access, $user, $locale, $name, &$rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, csv_safe_row(array_map(fn (string $k): string => (string) __("letters.export.{$k}", [], $locale), [
                'number', 'direction', 'status', 'registered_at', 'letter_date', 'received_on', 'due_on', 'subject', 'counterpart', 'institution', 'file_code', 'classification', 'priority', 'language', 'external_reference', 'physical_location', 'dispatch_mode', 'dispatch_reference', 'acknowledged_at', 'closed_at',
            ])));
            $query->chunk(500, function ($registrations) use ($out, $access, $user, $locale, $name, &$rows): void {
                foreach ($registrations as $registration) {
                    /** @var LetterRegistration $registration */
                    $letter = $registration->letter;
                    $readable = $access->canViewContents($user, $letter->loadMissing(['registrations', 'routings']));
                    $counterpart = $letter->direction->value === 'incoming'
                        ? ($letter->senderOrganization !== null ? $name($letter->senderOrganization) : (string) $letter->external_party_name)
                        : ($registration->organization_id !== $letter->organization_id ? $name($letter->organization) : ($letter->recipientOrganization !== null ? $name($letter->recipientOrganization) : (string) $letter->external_party_name));
                    fputcsv($out, csv_safe_row([
                        (string) $registration->registry_number,
                        (string) __('letters.enums.direction.'.$registration->direction->value, [], $locale),
                        (string) __('letters.enums.registration_status.'.$registration->status->value, [], $locale),
                        (string) $registration->registered_at?->toDateString(),
                        (string) $letter->letter_date?->toDateString(),
                        (string) $registration->received_on?->toDateString(),
                        (string) $registration->due_on?->toDateString(),
                        $readable ? (string) $letter->subject : (string) __('letters.enums.classification.'.$letter->classification->value, [], $locale),
                        $readable || $letter->direction->value !== 'incoming' ? $counterpart : '',
                        $name($registration->organization),
                        (string) $letter->file_code,
                        (string) __('letters.enums.classification.'.$letter->classification->value, [], $locale),
                        (string) __('letters.enums.priority.'.$letter->priority->value, [], $locale),
                        (string) __('letters.enums.language.'.$letter->language, [], $locale),
                        $readable ? (string) $letter->external_reference : '',
                        (string) $registration->physical_location,
                        $letter->dispatch_mode !== null ? (string) __('letters.enums.dispatch_mode.'.$letter->dispatch_mode->value, [], $locale) : '',
                        (string) $letter->dispatch_reference,
                        (string) $registration->acknowledged_at?->toDateString(),
                        (string) $registration->closed_at?->toDateString(),
                    ]));
                    $rows++;
                }
            });
            fclose($out);
        }, 'letter-registry-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);

        $audit->record(AuditEventType::LetterRegistryExported, $user, null, $filters['organization_id'] !== '' ? $filters['organization_id'] : null, ['filters' => array_filter($filters)]);

        return $response;
    }
}
