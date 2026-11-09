<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CardPrinterProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Saved printer set-ups. A profile is physical calibration for one printer,
 * shared by everyone who prints cards, so managing them takes the print
 * permission rather than any one batch.
 */
class CardPrinterProfileController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeProfiles($request);
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request): void {
            $profile = CardPrinterProfile::query()->create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $this->keepOneDefault($profile);
        });

        return back()->with('success', __('id-cards.printer_profile_saved'));
    }

    public function update(Request $request, CardPrinterProfile $profile): RedirectResponse
    {
        $this->authorizeProfiles($request);
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request, $profile): void {
            $profile->update([...$data, 'updated_by' => $request->user()->id]);
            $this->keepOneDefault($profile);
        });

        return back()->with('success', __('id-cards.printer_profile_saved'));
    }

    public function destroy(Request $request, CardPrinterProfile $profile): RedirectResponse
    {
        $this->authorizeProfiles($request);
        $profile->delete();

        return back()->with('success', __('id-cards.printer_profile_deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', Rule::in(CardPrinterProfile::MODES)],
            'paper' => ['nullable', 'required_if:mode,sheet', Rule::in(array_keys(CardPrinterProfile::PAPERS))],
            'sides' => ['required', Rule::in(CardPrinterProfile::SIDES)],
            'duplex_order' => ['required', Rule::in(CardPrinterProfile::DUPLEX_ORDERS)],
            // Calibration is a nudge; anything larger is a wrong paper setting.
            'offset_x_mm' => ['required', 'numeric', 'between:-20,20'],
            'offset_y_mm' => ['required', 'numeric', 'between:-20,20'],
            'scale_percent' => ['required', 'integer', 'between:80,120'],
            'margin_mm' => ['required', 'numeric', 'between:0,30'],
            'gap_mm' => ['required', 'numeric', 'between:0,20'],
            'cut_guides' => ['required', 'boolean'],
            'mirror_backs' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['mode'] === 'card') {
            $data['paper'] = null;
        }

        return $data;
    }

    private function keepOneDefault(CardPrinterProfile $profile): void
    {
        if ($profile->is_default) {
            CardPrinterProfile::query()->whereKeyNot($profile->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }

    private function authorizeProfiles(Request $request): void
    {
        abort_unless($request->user()->can('id-cards.print') || $request->user()->can('cards.manage'), 403);
    }
}
