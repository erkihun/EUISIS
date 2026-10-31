<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\ServiceMonitoring\Application\Actions\AcceptDisciplineSuggestion;
use Modules\ServiceMonitoring\Application\Actions\CaptureSection2Prefill;
use Modules\ServiceMonitoring\Application\Actions\DeleteSection1Entry;
use Modules\ServiceMonitoring\Application\Actions\DeleteSection3Entry;
use Modules\ServiceMonitoring\Application\Actions\SaveCustomSections;
use Modules\ServiceMonitoring\Application\Actions\SaveSection1Entry;
use Modules\ServiceMonitoring\Application\Actions\SaveSection2;
use Modules\ServiceMonitoring\Application\Actions\SaveSection3Entry;
use Modules\ServiceMonitoring\Application\Actions\SaveSection4;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Http\Requests\AcceptSuggestionRequest;
use Modules\ServiceMonitoring\Http\Requests\SaveAccountabilityEntryRequest;
use Modules\ServiceMonitoring\Http\Requests\SaveCustomSectionsRequest;
use Modules\ServiceMonitoring\Http\Requests\SaveDigitalServiceRequest;
use Modules\ServiceMonitoring\Http\Requests\SaveSection2Request;
use Modules\ServiceMonitoring\Http\Requests\SaveSection4Request;

/**
 * The four form sections of a submission. Child rows are bound through
 * their submission (scoped route bindings), so a row of another submission
 * answers 404.
 */
final class SectionController extends Controller
{
    public function storeEntry(SaveAccountabilityEntryRequest $request, Submission $submission, SaveSection1Entry $save): RedirectResponse
    {
        $save->execute($submission, $request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function updateEntry(SaveAccountabilityEntryRequest $request, Submission $submission, AccountabilityEntry $accountabilityEntry, SaveSection1Entry $save): RedirectResponse
    {
        $save->execute($submission, $request->validated(), $request->user(), $accountabilityEntry);

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function destroyEntry(Request $request, Submission $submission, AccountabilityEntry $accountabilityEntry, DeleteSection1Entry $delete): RedirectResponse
    {
        $this->authorize('update', $submission);
        $delete->execute($accountabilityEntry, $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function acceptSuggestion(AcceptSuggestionRequest $request, Submission $submission, AcceptDisciplineSuggestion $accept): RedirectResponse
    {
        $accept->execute($submission, $request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function section2(SaveSection2Request $request, Submission $submission, SaveSection2 $save): RedirectResponse
    {
        $values = [];
        foreach (FormDefinition::section2Keys() as $field) {
            $value = $request->validated("values.{$field}");
            $values[$field] = $value === null ? null : (int) $value;
        }
        $save->execute($submission, $values, $request->validated('narrative'), (array) ($request->validated('override_reasons') ?? []), $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function prefill(Request $request, Submission $submission, CaptureSection2Prefill $capture): RedirectResponse
    {
        $this->authorize('update', $submission);
        $capture->execute($submission, $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function storeService(SaveDigitalServiceRequest $request, Submission $submission, SaveSection3Entry $save): RedirectResponse
    {
        $save->execute($submission, $request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function updateService(SaveDigitalServiceRequest $request, Submission $submission, DigitalService $digitalService, SaveSection3Entry $save): RedirectResponse
    {
        $save->execute($submission, $request->validated(), $request->user(), $digitalService);

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function destroyService(Request $request, Submission $submission, DigitalService $digitalService, DeleteSection3Entry $delete): RedirectResponse
    {
        $this->authorize('update', $submission);
        $delete->execute($digitalService, $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    public function section4(SaveSection4Request $request, Submission $submission, SaveSection4 $save): RedirectResponse
    {
        // Section III saves only the totals and Section IV everything: only fields sent are written.
        $data = $request->validated();
        $int = fn (string $key): ?int => isset($data[$key]) ? (int) $data[$key] : null;
        $performance = [];
        foreach ([...FormDefinition::SECTION_4, 'below_reasons', 'actions_taken'] as $field) {
            if (array_key_exists($field, $data)) {
                $performance[$field] = in_array($field, FormDefinition::SECTION_4, true) ? $int($field) : $data[$field];
            }
        }
        $totals = $submission->serviceTotals;
        $save->execute($submission, [
            'main_total' => array_key_exists('main_total', $data) ? $int('main_total') : $totals?->main_total,
            'sub_total' => array_key_exists('sub_total', $data) ? $int('sub_total') : $totals?->sub_total,
        ], $performance, $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }

    /** The answers to the form template's additional sections (autosaved; required answers are checked at submission). */
    public function custom(SaveCustomSectionsRequest $request, Submission $submission, SaveCustomSections $save): RedirectResponse
    {
        $save->execute($submission, (array) $request->validated('custom'), $request->user());

        return back()->with('success', __('service-monitoring.flash.saved'));
    }
}
