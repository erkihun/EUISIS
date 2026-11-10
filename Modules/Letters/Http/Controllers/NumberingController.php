<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Actions\SaveNumberingRule;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LetterNumbering;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\ResetPolicy;
use Modules\Letters\Domain\Models\LetterNumberingRule;
use Modules\Letters\Http\Requests\SaveNumberingRuleRequest;

/** Numbering rules per institution and direction, with a live preview of the next number. */
final class NumberingController extends Controller
{
    public function __construct(
        private readonly LettersAccess $access,
        private readonly LetterLookups $lookups,
        private readonly LetterNumbering $numbering,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LetterNumberingRule::class);
        $user = $request->user();
        $organizations = $this->lookups->actingOrganizations($user, 'letters.numbering.manage');
        $platform = $this->access->canManageNumbering($user, null);

        $rules = LetterNumberingRule::query()->with('organization:id,code,name_en,name_am', 'updater:id,name')
            ->where(fn ($q) => $q->whereIn('organization_id', array_column($organizations, 'id'))->when($platform, fn ($w) => $w->orWhereNull('organization_id')))
            ->orderByRaw('organization_id is null desc')->orderBy('direction')->orderByRaw('language is null desc')->get()
            ->map(fn (LetterNumberingRule $r): array => [
                'id' => $r->id, 'organization_id' => $r->organization_id, 'direction' => $r->direction->value, 'language' => $r->language, 'pattern' => $r->pattern,
                'reset_policy' => $r->reset_policy->value, 'padding' => $r->padding, 'direction_code' => $r->direction_code, 'language_code' => $r->language_code,
                'organization' => $r->organization !== null ? ['code' => $r->organization->code, 'name_en' => $r->organization->name_en, 'name_am' => $r->organization->name_am] : null,
                'updated_by' => $r->updater?->name, 'updated_at' => $r->updated_at?->toIso8601String(),
            ])->values()->all();

        return Inertia::render('Letters/Numbering', [
            'rules' => $rules,
            'organizations' => $organizations,
            'platform' => $platform,
            'directions' => LetterDirection::values(),
            'reset_policies' => ResetPolicy::values(),
            'tokens' => LetterNumberingRule::TOKENS,
            'default_pattern' => LetterNumberingRule::DEFAULT_PATTERN,
            'default_pattern_en' => LetterNumberingRule::DEFAULT_PATTERN_EN,
        ]);
    }

    public function save(SaveNumberingRuleRequest $request, SaveNumberingRule $save): RedirectResponse
    {
        $save->execute($request->validated(), $request->user());

        return back()->with('success', __('letters.flash.numbering_saved'));
    }

    public function preview(SaveNumberingRuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $rule = new LetterNumberingRule([
            'direction' => $data['direction'], 'pattern' => $data['pattern'], 'reset_policy' => $data['reset_policy'] ?? 'ethiopian_year',
            'padding' => (int) ($data['padding'] ?? 4), 'direction_code' => $data['direction_code'] ?? null, 'language_code' => $data['language_code'] ?? null,
        ]);
        $organizationId = $data['organization_id'] ?? null;
        // A rule for both languages previews the Amharic sequence; the page shows which.
        $language = LetterNumbering::language($data['language'] ?? null);
        $sample = $organizationId !== null ? $this->numbering->preview($rule, $organizationId, $language) : $this->numbering->format($rule, 'ORG', 1, now()->toImmutable(), $language);

        return response()->json(['preview' => $sample]);
    }
}
