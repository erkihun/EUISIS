<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Support\EthiopianCalendar;
use Carbon\CarbonImmutable;
use Modules\Letters\Domain\Models\Letter;

/**
 * Fills a template's `{{ key }}` placeholders (ADR 0037 §7). Built-in keys
 * come from the letter (letter.*, sender.*, recipient.*, signatory.*, today.*);
 * the template's own placeholders come from the composer's answers. Every
 * value is HTML-escaped; an unknown key renders as nothing, so a template
 * never shows a raw token to the reader.
 */
class TemplateRenderer
{
    public const TOKEN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /** @var list<string> */
    public const BUILT_IN = [
        'letter.number', 'letter.date', 'letter.date_ec', 'letter.date_gc', 'letter.subject', 'letter.external_reference',
        'sender.name', 'sender.name_en', 'sender.name_am', 'sender.code', 'sender.unit',
        'recipient.name', 'recipient.name_en', 'recipient.name_am', 'recipient.address',
        'signatory.name', 'today.date_ec', 'today.date_gc',
    ];

    /** @param array<string, string|int|float|null> $context */
    public function render(?string $template, array $context): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        return (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($context): string {
            $value = $context[$m[1]] ?? '';

            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template);
    }

    /** @return list<string> the keys a text asks for */
    public function keysIn(?string $template): array
    {
        if ($template === null || preg_match_all(self::TOKEN, $template, $m) === 0) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /**
     * The letter's built-in values in the given language, merged with the
     * composer's answers (which never override a built-in key).
     *
     * @return array<string, string>
     */
    public function context(Letter $letter, string $locale): array
    {
        $letter->loadMissing(['organization', 'unit', 'recipientOrganization', 'senderOrganization', 'signatory', 'registrations']);
        $name = static fn ($org, string $which = 'any'): string => $org === null ? '' : match ($which) {
            'en' => (string) ($org->name_en ?? ''),
            'am' => (string) ($org->name_am ?? $org->name_en ?? ''),
            default => (string) ($locale === 'am' ? ($org->name_am ?? $org->name_en ?? '') : ($org->name_en ?? $org->name_am ?? '')),
        };
        $date = $letter->letter_date instanceof \DateTimeInterface ? CarbonImmutable::instance($letter->letter_date) : CarbonImmutable::now();
        $today = CarbonImmutable::now();
        $sender = $letter->direction->value === 'incoming' ? $letter->senderOrganization : $letter->organization;
        $recipient = $letter->direction->value === 'incoming' ? $letter->organization : $letter->recipientOrganization;
        $externalName = (string) ($letter->external_party_name ?? '');

        $builtIn = [
            'letter.number' => (string) ($letter->referenceNumber() ?? ''),
            'letter.date' => $locale === 'am' ? EthiopianCalendar::format($date, 'dd/mm/yyyy') : $date->format('d/m/Y'),
            'letter.date_ec' => EthiopianCalendar::format($date, 'dd/mm/yyyy'),
            'letter.date_gc' => $date->format('d/m/Y'),
            'letter.subject' => (string) $letter->subject,
            'letter.external_reference' => (string) ($letter->external_reference ?? ''),
            'sender.name' => $sender !== null ? $name($sender) : ($letter->direction->value === 'incoming' ? $externalName : ''),
            'sender.name_en' => $name($sender, 'en'),
            'sender.name_am' => $name($sender, 'am'),
            'sender.code' => (string) ($sender?->code ?? ''),
            'sender.unit' => $letter->unit !== null ? $name($letter->unit) : '',
            'recipient.name' => $recipient !== null ? $name($recipient) : ($letter->direction->value === 'incoming' ? '' : $externalName),
            'recipient.name_en' => $name($recipient, 'en'),
            'recipient.name_am' => $name($recipient, 'am'),
            'recipient.address' => (string) ($letter->external_party_address ?? ''),
            'signatory.name' => (string) ($letter->signatory?->name ?? ''),
            'today.date_ec' => EthiopianCalendar::format($today, 'dd/mm/yyyy'),
            'today.date_gc' => $today->format('d/m/Y'),
        ];

        $custom = [];
        foreach ((array) ($letter->template_context ?? []) as $key => $value) {
            if (is_string($key) && ! isset($builtIn[$key]) && (is_scalar($value) || $value === null)) {
                $custom[$key] = (string) ($value ?? '');
            }
        }

        return [...$custom, ...$builtIn];
    }
}
