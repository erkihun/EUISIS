<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Documents;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Reporting\Application\Data\ReportDocument;
use Modules\Reporting\Domain\Calendar\EthiopianDate;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Consolidation;
use Modules\Reporting\Infrastructure\Persistence\Models\ConsolidationSource;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Draft;
use Modules\Reporting\Infrastructure\Persistence\Models\MetricCapture;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateSection;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateVersion;

/**
 * Builds the document model from the module's records. A submitted version is
 * always rendered from its immutable snapshot (content, section titles as
 * they were, the names and times the workflow recorded), never from the live
 * draft or the current template.
 */
final readonly class ReportDocumentFactory
{
    private const ZONE = 'Africa/Addis_Ababa';

    /** @param string $label submitted or approved */
    public function forSubmission(Submission $submission, string $label, string $language): ReportDocument
    {
        $obligation = Obligation::query()->findOrFail($submission->obligation_id);
        $cycle = Cycle::query()->findOrFail($obligation->cycle_id);
        $type = ReportType::query()->findOrFail($obligation->report_type_id);
        $am = $language === 'am';

        $signoff = [['role' => $this->t('prepared_by', $language), 'name' => $submission->submitted_by_name, 'at' => $this->moment($submission->submitted_at, $language)]];
        $steps = ReviewStep::query()->where('submission_id', $submission->id)->where('decision', 'approve')->orderBy('step_no')->get();
        foreach ($steps as $step) {
            $final = $submission->status === 'approved' && $step->is($steps->last());
            $signoff[] = [
                'role' => $this->t($final ? 'approved_by' : 'reviewed_by', $language).' ('.($am ? $step->name_am : $step->name_en).')',
                'name' => (string) $step->decided_by_name, 'at' => $this->moment($step->decided_at, $language),
            ];
        }

        return new ReportDocument(
            language: $language,
            label: $label,
            title: (string) ($am ? $type->name_am : $type->name_en),
            institutionName: (string) ($am ? ($obligation->organization_name_am ?: $obligation->organization_name_en) : ($obligation->organization_name_en ?: $obligation->organization_name_am)),
            receivingName: $am ? ($cycle->receiving_name_am ?: $cycle->receiving_name_en) : ($cycle->receiving_name_en ?: $cycle->receiving_name_am),
            periodLabel: (string) ($am ? $cycle->period_label_am : $cycle->period_label_en),
            referenceNo: $submission->reference_no,
            versionNo: $submission->version_no,
            identifier: $submission->id,
            sections: $submission->is_nil ? [] : array_map(fn (array $section): array => [
                'title' => (string) ($am ? $section['title_am'] : $section['title_en']),
                'html' => (string) ($submission->content[$section['key']] ?? ''),
            ], $submission->mode === 'upload' ? [] : $submission->sections_snapshot),
            summary: $submission->summary,
            nilReason: $submission->is_nil ? (string) $submission->nil_reason : null,
            annexes: Attachment::query()->where('submission_id', $submission->id)->orderBy('created_at')->pluck('original_name')->all(),
            signoff: $signoff,
            facts: array_values(array_filter([
                ['label' => $this->t('submitted_at', $language), 'value' => (string) $this->moment($submission->submitted_at, $language)],
                $submission->mode === 'upload' ? ['label' => $this->t('submission_mode', $language), 'value' => $this->t('mode_upload', $language)] : null,
                ...MetricCapture::query()->where('owner_type', 'submission')->where('owner_id', $submission->id)->get()
                    ->map(fn (MetricCapture $m): array => ['label' => $m->definition, 'value' => trim(rtrim(rtrim((string) $m->value, '0'), '.').' '.$m->unit).' ('.$m->source.', '.$this->moment($m->captured_at, $language).')'])->all(),
            ])),
            logoPath: $this->logo(),
            generatedAt: $this->moment(CarbonImmutable::now('UTC'), $language),
        );
    }

    /** The current, unsubmitted draft, labelled DRAFT. */
    public function forDraft(Draft $draft, string $language): ReportDocument
    {
        $obligation = Obligation::query()->findOrFail($draft->obligation_id);
        $cycle = Cycle::query()->findOrFail($obligation->cycle_id);
        $type = ReportType::query()->findOrFail($obligation->report_type_id);
        $am = $language === 'am';

        return new ReportDocument(
            language: $language, label: 'draft', title: (string) ($am ? $type->name_am : $type->name_en),
            institutionName: (string) ($am ? ($obligation->organization_name_am ?: $obligation->organization_name_en) : ($obligation->organization_name_en ?: $obligation->organization_name_am)),
            receivingName: $am ? ($cycle->receiving_name_am ?: $cycle->receiving_name_en) : ($cycle->receiving_name_en ?: $cycle->receiving_name_am),
            periodLabel: (string) ($am ? $cycle->period_label_am : $cycle->period_label_en),
            referenceNo: null, versionNo: $draft->version_no, identifier: null,
            sections: $draft->is_nil ? [] : $this->templateSections($obligation->template_version_id, $am, $draft->content ?? []),
            summary: $draft->summary, nilReason: $draft->is_nil ? (string) $draft->nil_reason : null,
            annexes: Attachment::query()->where('obligation_id', $obligation->id)->where('version_no', $draft->version_no)->pluck('original_name')->all(),
            logoPath: $this->logo(), generatedAt: $this->moment(CarbonImmutable::now('UTC'), $language),
        );
    }

    /** The empty template an institution downloads to prepare its report offline in Word. */
    public function forTemplate(TemplateVersion $version, ReportType $type, string $language, ?Obligation $obligation = null, ?Cycle $cycle = null): ReportDocument
    {
        $am = $language === 'am';

        return new ReportDocument(
            language: $language, label: 'template', title: (string) ($am ? $type->name_am : $type->name_en),
            institutionName: $obligation === null ? $this->t('institution_placeholder', $language) : (string) ($am ? ($obligation->organization_name_am ?: $obligation->organization_name_en) : $obligation->organization_name_en),
            receivingName: $cycle === null ? null : ($am ? $cycle->receiving_name_am : $cycle->receiving_name_en),
            periodLabel: $cycle === null ? $this->t('period_placeholder', $language) : (string) ($am ? $cycle->period_label_am : $cycle->period_label_en),
            referenceNo: null, versionNo: null, identifier: null,
            sections: $this->templateSections($version->id, $am, []),
            signoff: array_map(fn (string $role): array => ['role' => $this->t($role, $language), 'name' => '', 'at' => ''], ['prepared_by', 'reviewed_by', 'approved_by']),
            logoPath: $this->logo(),
        );
    }

    public function forConsolidation(Consolidation $consolidation, string $language): ReportDocument
    {
        $cycle = Cycle::query()->findOrFail($consolidation->cycle_id);
        $type = ReportType::query()->findOrFail($cycle->report_type_id);
        $am = $language === 'am';
        $name = fn (?string $en, ?string $amharic): string => (string) ($am ? ($amharic ?: $en) : ($en ?: $amharic));

        $sections = [['title' => $this->t('executive_summary', $language), 'html' => (string) $consolidation->executive_summary]];
        $sourceRows = [];
        // Institution by institution, in the order of their codes; each approved version in full.
        $sources = ConsolidationSource::query()->where('consolidation_id', $consolidation->id)->get();
        $obligations = Obligation::query()->whereKey($sources->pluck('obligation_id'))->orderBy('organization_code')->get();
        foreach ($obligations as $obligation) {
            $source = $sources->firstWhere('obligation_id', $obligation->id);
            $submission = Submission::query()->find($source->submission_id);
            if ($submission === null) {
                continue;
            }
            $institution = $obligation->organization_unit_id === null ? $name($obligation->organization_name_en, $obligation->organization_name_am) : $name($obligation->unit_name_en, $obligation->unit_name_am);
            $sourceRows[] = [$institution, $source->reference_no, (string) $source->version_no, $source->submission_id];
            $body = $submission->is_nil
                ? '<p><em>'.e($this->t('nil_report', $language)).':</em> '.e((string) $submission->nil_reason).'</p>'
                : ($submission->mode === 'upload'
                    ? '<p>'.e((string) $submission->summary).'</p><p><em>'.e($this->t('mode_upload', $language)).'</em></p>'
                    : implode('', array_map(fn (array $section): string => trim(strip_tags((string) ($submission->content[$section['key']] ?? ''))) === '' ? ''
                        : '<h3>'.e((string) ($am ? $section['title_am'] : $section['title_en'])).'</h3>'.$submission->content[$section['key']], $submission->sections_snapshot)));
            $sections[] = ['title' => $institution, 'html' => $body];
        }

        $indicators = $consolidation->indicators ?? [];
        $tables = [[
            'heading' => $this->t('indicators', $language),
            'header' => [$this->t('indicator', $language), $this->t('value', $language)],
            'rows' => [
                ...array_map(fn (string $key): array => [$this->t("indicator_{$key}", $language), (string) ($indicators[$key] ?? 0)], ['expected', 'included', 'missing', 'exempted_or_cancelled', 'nil_reports', 'submitted_on_time', 'submitted_late']),
                ...MetricCapture::query()->where('owner_type', 'consolidation')->where('owner_id', $consolidation->id)->get()
                    ->map(fn (MetricCapture $m): array => [$m->definition.' — '.$m->source.', '.$this->moment($m->captured_at, $language), trim(rtrim(rtrim((string) $m->value, '0'), '.').' '.$m->unit)])->all(),
            ],
        ], [
            // Nobody is silently left out: every institution without an approved report is named with its status.
            'heading' => $this->t('missing_institutions', $language),
            'header' => [$this->t('institution', $language), $this->t('status', $language)],
            'rows' => ($consolidation->missing ?? []) === [] ? [[$this->t('none', $language), '—']]
                : array_map(fn (array $row): array => [$name($row['name_en'] ?? null, $row['name_am'] ?? null), $this->t('status_'.$row['status'], $language)], $consolidation->missing),
        ], [
            'heading' => $this->t('sources', $language),
            'header' => [$this->t('institution', $language), $this->t('reference_no', $language), $this->t('version_label', $language), $this->t('identifier', $language)],
            'rows' => $sourceRows === [] ? [[$this->t('none', $language), '—', '—', '—']] : $sourceRows,
        ]];

        return new ReportDocument(
            language: $language, label: 'consolidated', title: $consolidation->title,
            institutionName: $name($consolidation->parent_name_en, $consolidation->parent_name_am), receivingName: null,
            periodLabel: $name($type->name_en, $type->name_am).' · '.$name($cycle->period_label_en, $cycle->period_label_am),
            referenceNo: null, versionNo: null, identifier: $consolidation->id, sections: $sections, tables: $tables,
            signoff: [['role' => $this->t('compiled_by', $language), 'name' => (string) $consolidation->created_by_name, 'at' => $this->moment($consolidation->compiled_at, $language)]],
            logoPath: $this->logo(), generatedAt: $this->moment(CarbonImmutable::now('UTC'), $language),
        );
    }

    /**
     * @param  array<string, ?string>  $content
     * @return list<array{title: string, html: string, guidance: ?string}>
     */
    private function templateSections(string $templateVersionId, bool $am, array $content): array
    {
        return TemplateSection::query()->where('template_version_id', $templateVersionId)->orderBy('position')->get()
            ->map(fn (TemplateSection $s): array => ['title' => (string) ($am ? $s->title_am : $s->title_en), 'html' => (string) ($content[$s->key] ?? ''), 'guidance' => $am ? $s->guidance_am : $s->guidance_en])->all();
    }

    /** Local business time, explicitly labelled; in Amharic the Ethiopian date is given first. */
    private function moment(?DateTimeInterface $at, string $language): ?string
    {
        if ($at === null) {
            return null;
        }
        $local = CarbonImmutable::instance($at)->setTimezone(self::ZONE);

        return $language === 'am'
            ? EthiopianDate::fromIso($local->toDateString())->format('am').' '.$local->format('H:i').' (EAT) / '.$local->format('Y-m-d')
            : $local->format('Y-m-d H:i').' EAT (UTC+3)';
    }

    private function t(string $key, string $language): string
    {
        return (string) trans("reporting::document.{$key}", [], $language);
    }

    private function logo(): ?string
    {
        $path = config('reporting.logo_path');

        return is_string($path) && $path !== '' && is_file($path) ? $path : null;
    }
}
