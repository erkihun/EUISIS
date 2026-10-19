<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Models\DesignDocumentSuggestion;
use App\Models\InstitutionMandate;
use App\Models\LegalSource;
use App\Services\OrganizationDesign\DesignSuggestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Document-assisted capture on a mandate (ADR-LB-006). Asking needs the
 * suggestion grant; applying needs it too and the right to manage the
 * mandate's functions, because an applied item becomes that mandate's record.
 */
class DesignSuggestionController extends Controller
{
    public function __construct(private readonly DesignSuggestionService $suggestions) {}

    public function store(Request $request, InstitutionMandate $mandate): RedirectResponse
    {
        abort_unless($request->user()->can('design-suggestions.apply') && $request->user()->can('manageFunctions', $mandate), 403);
        $data = $request->validate([
            'passage' => ['required', 'string', 'max:'.DesignSuggestionService::MAX_PASSAGE],
            'legal_source_id' => ['nullable', 'uuid', Rule::exists('legal_sources', 'id')->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $mandate->organization_id))],
        ]);
        $suggestion = $this->suggestions->suggestFunctions($request->user(), $mandate, $data['passage'], $data['legal_source_id'] ?? null, app()->getLocale() === 'am' ? 'am' : 'en');

        return back()->with('flash', [
            'message' => __('organization-design.suggestion_'.$suggestion->status),
            'type' => $suggestion->status === DesignDocumentSuggestion::STATUS_READY ? 'success' : 'warning',
        ]);
    }

    public function apply(Request $request, DesignDocumentSuggestion $suggestion, int $index): RedirectResponse
    {
        $mandate = $suggestion->mandate;
        abort_unless($request->user()->can('design-suggestions.apply') && $request->user()->can('manageFunctions', $mandate), 403);
        $kind = ($suggestion->items[$index]['kind'] ?? 'function') === 'power' ? 'organization_powers' : 'organization_functions';
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', Rule::unique($kind, 'code')->where('organization_id', $mandate->organization_id)->whereNull('deleted_at')],
            'title_en' => ['nullable', 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'legal_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $this->suggestions->apply($request->user(), $suggestion, $index, $data);

        return back()->with('flash', ['message' => __('organization-design.suggestion_applied'), 'type' => 'success']);
    }

    public function reject(Request $request, DesignDocumentSuggestion $suggestion, int $index): RedirectResponse
    {
        abort_unless($request->user()->can('design-suggestions.apply') && $request->user()->can('manageFunctions', $suggestion->mandate), 403);
        $this->suggestions->reject($request->user(), $suggestion, $index);

        return back()->with('flash', ['message' => __('organization-design.suggestion_rejected'), 'type' => 'success']);
    }

    /** @return list<array<string, mixed>> the latest suggestions of a mandate, for its page */
    public static function forMandate(InstitutionMandate $mandate): array
    {
        return DesignDocumentSuggestion::query()->where('mandate_id', $mandate->getKey())->with('creator:id,name')->latest('created_at')->limit(5)->get()
            ->map(fn (DesignDocumentSuggestion $s) => [
                'id' => $s->id, 'status' => $s->status, 'items' => $s->items ?? [], 'notes' => $s->notes, 'passage_chars' => $s->passage_chars,
                'legal_source' => $s->legal_source_id ? LegalSource::query()->whereKey($s->legal_source_id)->value('code') : null,
                'created_by' => $s->creator?->name, 'created_at' => $s->created_at?->toISOString(),
            ])->all();
    }
}
