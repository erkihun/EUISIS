<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\EthiopianCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Letters\Application\Actions\SaveLetterhead;
use Modules\Letters\Application\Services\LetterheadResolver;
use Modules\Letters\Application\Services\LetterLayout;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LetterPdf;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Enums\LetterClassification;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\LetterPriority;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterLetterhead;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Http\Requests\SaveLetterheadRequest;

/**
 * Header and footer layouts: the list of an institution's layouts (or the
 * platform's), the editor for one layout, and a sample letter printed on it.
 */
final class LetterheadController extends Controller
{
    public function __construct(
        private readonly LettersAccess $access,
        private readonly LetterLookups $lookups,
        private readonly LetterheadResolver $resolver,
        private readonly SaveLetterhead $save,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        [$organizations, $platform, $selected] = $this->scope($request);

        $layouts = LetterLetterhead::query()->with('updater:id,name')->withCount('letters')
            ->where(fn ($q) => $selected === '' ? $q->whereNull('organization_id') : $q->where('organization_id', $selected))
            ->orderByDesc('is_default')->orderByDesc('active')->orderBy('name')->get()
            ->map(fn (LetterLetterhead $l): array => [
                'id' => $l->id,
                'name' => $l->name,
                'is_default' => $l->is_default,
                'active' => $l->active,
                'paper' => LetterLayout::page((array) $l->page)['paper'],
                'orientation' => LetterLayout::page((array) $l->page)['orientation'],
                'header_rows' => count((array) ($l->header['rows'] ?? [])),
                'footer_rows' => count((array) ($l->footer['rows'] ?? [])),
                'images' => count($l->imageList()),
                'has_stamp' => $l->stamp_path !== null,
                'letters_count' => $l->letters_count,
                'updated_by' => $l->updater?->name,
                'updated_at' => $l->updated_at?->toIso8601String(),
            ])->all();

        return Inertia::render('Letters/Letterheads/Index', [
            'organizations' => $organizations,
            'platform' => $platform,
            'selected_organization_id' => $selected,
            'layouts' => $layouts,
            'inherits_platform' => $selected !== '' && $layouts === [] && LetterLetterhead::query()->whereNull('organization_id')->where('active', true)->exists(),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        [$organizations, $platform, $selected] = $this->scope($request);
        $organization = $selected !== '' ? Organization::query()->find($selected) : null;
        $defaults = LetterLayout::defaults();

        return $this->form($organizations, $platform, $organization, [
            'id' => null,
            'organization_id' => $selected,
            'name' => '',
            'is_default' => ! LetterLetterhead::query()->where(fn ($q) => $selected === '' ? $q->whereNull('organization_id') : $q->where('organization_id', $selected))->exists(),
            'active' => true,
            'page' => $defaults['page'],
            'header' => $defaults['header'],
            'footer' => $defaults['footer'],
            'images' => [],
            'has_stamp' => false,
            'letters_count' => 0,
        ], []);
    }

    public function store(SaveLetterheadRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $owner = isset($data['organization_id']) && $data['organization_id'] !== '' ? (string) $data['organization_id'] : null;
        $layout = $this->save->execute(null, $owner, $data, $request->layout(), $this->newImages($request), $request->file('stamp'), $request->user());

        return redirect()->route('letters.letterheads.edit', $layout)->with('success', __('letters.flash.letterhead_saved'));
    }

    public function edit(Request $request, LetterLetterhead $letterhead): InertiaResponse
    {
        $this->authorizeLayout($request->user(), $letterhead);
        [$organizations, $platform] = $this->scope($request, $letterhead->organization_id ?? '');
        $organization = $letterhead->organization;

        $previews = [];
        foreach ($letterhead->imageList() as $image) {
            $previews[$image['key']] = $this->resolver->dataUri($image['path'], LetterLetterhead::DISK);
        }
        $previews['stamp'] = $this->resolver->dataUri($letterhead->stamp_path, LetterLetterhead::DISK);

        return $this->form($organizations, $platform, $organization, [
            'id' => $letterhead->id,
            'organization_id' => $letterhead->organization_id ?? '',
            'name' => $letterhead->name,
            'is_default' => $letterhead->is_default,
            'active' => $letterhead->active,
            'page' => LetterLayout::page((array) $letterhead->page),
            'header' => LetterLayout::section($letterhead->header, $letterhead->imageKeys()),
            'footer' => LetterLayout::section($letterhead->footer, $letterhead->imageKeys()),
            'images' => array_map(fn (array $i): array => ['key' => $i['key'], 'name' => $i['name'] ?? $i['key']], $letterhead->imageList()),
            'has_stamp' => $letterhead->stamp_path !== null,
            'letters_count' => $letterhead->letters()->count(),
            'updated_by' => $letterhead->updater?->name,
            'updated_at' => $letterhead->updated_at?->toIso8601String(),
        ], array_filter($previews));
    }

    public function update(SaveLetterheadRequest $request, LetterLetterhead $letterhead): RedirectResponse
    {
        $this->save->execute($letterhead, $letterhead->organization_id, $request->validated(), $request->layout(), $this->newImages($request), $request->file('stamp'), $request->user());

        return redirect()->route('letters.letterheads.edit', $letterhead)->with('success', __('letters.flash.letterhead_saved'));
    }

    public function duplicate(Request $request, LetterLetterhead $letterhead): RedirectResponse
    {
        $this->authorizeLayout($request->user(), $letterhead);
        $copy = $this->save->duplicate($letterhead, __('letters.letterheads.copyName', ['name' => $letterhead->name]), $request->user());

        return redirect()->route('letters.letterheads.edit', $copy)->with('success', __('letters.flash.letterhead_copied'));
    }

    public function makeDefault(Request $request, LetterLetterhead $letterhead): RedirectResponse
    {
        $this->authorizeLayout($request->user(), $letterhead);
        $this->save->makeDefault($letterhead, $request->user());

        return back()->with('success', __('letters.flash.letterhead_default'));
    }

    public function destroy(Request $request, LetterLetterhead $letterhead): RedirectResponse
    {
        $this->authorizeLayout($request->user(), $letterhead);
        $owner = $letterhead->organization_id;
        $this->save->delete($letterhead, $request->user());

        return redirect()->route('letters.letterheads.index', $owner !== null ? ['organization_id' => $owner] : [])->with('success', __('letters.flash.letterhead_deleted'));
    }

    /** A sample letter printed on this layout, as PDF (nothing is saved). */
    public function preview(Request $request, LetterLetterhead $letterhead, LetterPdf $pdf): Response
    {
        $user = $request->user();
        $this->authorizeLayout($user, $letterhead);
        $organization = $letterhead->organization ?? $this->sampleOrganization($user);

        $letter = new Letter([
            'direction' => LetterDirection::Outgoing->value, 'status' => LetterStatus::Draft->value, 'organization_id' => $organization->id,
            'letterhead_id' => $letterhead->id,
            'external_party_name' => __('letters.letterhead.sample_recipient'), 'external_party_address' => __('letters.letterhead.sample_address'),
            'subject' => __('letters.letterhead.sample_subject'), 'body' => '<p>'.e(__('letters.letterhead.sample_body')).'</p><p>'.e(__('letters.letterhead.sample_body')).'</p>',
            'language' => $request->string('language')->toString() === 'en' || ($request->string('language')->toString() === '' && app()->getLocale() === 'en') ? 'en' : 'am',
            'priority' => LetterPriority::Normal->value, 'classification' => LetterClassification::Internal->value,
            'letter_date' => now()->toDateString(), 'signatory_user_id' => $user->id,
        ]);
        $letter->id = '00000000-0000-7000-8000-000000000000';
        // A sample prints as a dispatched letter would: with the stamp, under a sample number.
        $letter->status = LetterStatus::Dispatched;
        $letter->setRelation('organization', $organization);
        $letter->setRelation('signatory', $user instanceof User ? $user : null);
        foreach (['attachments', 'copies'] as $relation) {
            $letter->setRelation($relation, $letter->newCollection());
        }
        $sampleRegistration = new LetterRegistration(['organization_id' => $organization->id, 'direction' => 'outgoing', 'status' => 'registered', 'registry_number' => ($organization->code ?? 'ORG').'/0000/'.EthiopianCalendar::toEthiopian(now())['year']]);
        $letter->setRelation('registrations', $letter->newCollection([$sampleRegistration]));
        foreach (['unit', 'recipientOrganization', 'senderOrganization', 'inReplyTo'] as $relation) {
            $letter->setRelation($relation, null);
        }

        return response($pdf->render($letter), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="letter-layout-preview.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The institutions the user may manage layouts for, whether the platform's layouts are open to them, and the one chosen.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: string}
     */
    private function scope(Request $request, ?string $forced = null): array
    {
        $user = $request->user();
        $organizations = $this->lookups->actingOrganizations($user, 'letters.letterhead.manage');
        $platform = $this->access->canManageLetterhead($user, null);
        abort_if($organizations === [] && ! $platform, 403);

        $requested = $forced ?? (string) $request->string('organization_id');
        $selected = collect($organizations)->contains('id', $requested) ? $requested : ($platform && $requested === '' ? '' : ($organizations[0]['id'] ?? ''));

        return [$organizations, $platform, $selected];
    }

    /**
     * @param  list<array<string, mixed>>  $organizations
     * @param  array<string, mixed>  $layout
     * @param  array<string, string>  $previews
     */
    private function form(array $organizations, bool $platform, ?Organization $organization, array $layout, array $previews): InertiaResponse
    {
        if ($organization !== null) {
            $previews[LetterLayout::ORG_LOGO] = $this->resolver->dataUri($organization->logo_path, 'public');
        }

        return Inertia::render('Letters/Letterheads/Form', [
            'organizations' => $organizations,
            'platform' => $platform,
            'organization' => $organization !== null ? ['id' => $organization->id, 'code' => $organization->code, 'name_en' => $organization->name_en, 'name_am' => $organization->name_am] : null,
            'layout' => $layout,
            'previews' => array_filter($previews),
            'options' => [
                'papers' => LetterLayout::PAPERS,
                'orientations' => LetterLayout::ORIENTATIONS,
                'fonts' => array_map(fn (string $key, array $font): array => ['key' => $key, 'family' => $font['family'], 'bold' => $font['bold'] !== $font['normal']], array_keys(LetterLayout::FONTS), LetterLayout::FONTS),
                'placeholders' => LetterLayout::PLACEHOLDERS,
                'max_rows' => LetterLayout::MAX_ROWS,
                'max_cells' => LetterLayout::MAX_CELLS,
                'max_items' => LetterLayout::MAX_ITEMS,
                'max_images' => LetterLayout::MAX_IMAGES,
                'org_logo' => LetterLayout::ORG_LOGO,
            ],
        ]);
    }

    /** @return array<string, UploadedFile> */
    private function newImages(Request $request): array
    {
        return array_filter((array) $request->file('new_images', []), fn ($f): bool => $f instanceof UploadedFile);
    }

    private function authorizeLayout(?User $user, LetterLetterhead $letterhead): void
    {
        abort_unless($user !== null && $this->access->canManageLetterhead($user, $letterhead->organization_id), 403);
    }

    /** A platform layout previews against the user's first institution, else a sample institution. */
    private function sampleOrganization(?User $user): Organization
    {
        $first = $user !== null ? ($this->lookups->actingOrganizations($user, 'letters.letterhead.manage')[0]['id'] ?? null) : null;
        $organization = $first !== null ? Organization::query()->find($first) : null;

        return $organization ?? new Organization(['code' => 'ORG', 'name_am' => __('letters.letterheads.sampleInstitution', [], 'am'), 'name_en' => __('letters.letterheads.sampleInstitution', [], 'en')]);
    }
}
