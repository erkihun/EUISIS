<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Application\Services\LetterStandards;
use Modules\Letters\Domain\Models\LetterFileClass;
use Modules\Letters\Domain\Models\LetterStandard;
use Modules\Letters\Http\Requests\SaveFileClassRequest;
use Modules\Letters\Http\Requests\SaveStandardsRequest;

/**
 * Letter standards per institution (or the platform defaults): the reply-time
 * standard and the file classification scheme, on one page.
 */
final class StandardsController extends Controller
{
    public function __construct(
        private readonly LettersAccess $access,
        private readonly LetterLookups $lookups,
        private readonly LetterStandards $standards,
        private readonly LettersAudit $audit,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $organizations = $this->lookups->actingOrganizations($user, 'letters.standards.manage');
        $platform = $this->access->canManageStandards($user, null);
        abort_if($organizations === [] && ! $platform, 403);

        $requested = (string) $request->string('organization_id');
        $selected = collect($organizations)->contains('id', $requested) ? $requested : ($platform && $requested === '' ? '' : ($organizations[0]['id'] ?? ''));
        $own = LetterStandard::query()->where(fn ($q) => $selected === '' ? $q->whereNull('organization_id') : $q->where('organization_id', $selected))->first();
        $classes = LetterFileClass::query()->where(fn ($q) => $selected === '' ? $q->whereNull('organization_id') : $q->where('organization_id', $selected))
            ->orderBy('position')->orderBy('code')->get()
            ->map(fn (LetterFileClass $c): array => ['id' => $c->id, 'parent_id' => $c->parent_id, 'code' => $c->code, 'title_am' => $c->title_am, 'title_en' => $c->title_en, 'active' => $c->active, 'position' => $c->position])->all();

        return Inertia::render('Letters/Standards', [
            'organizations' => $organizations,
            'platform' => $platform,
            'selected_organization_id' => $selected,
            'own' => $own?->only(array_keys(LetterStandard::DEFAULTS)),
            'effective' => $this->standards->for($selected !== '' ? $selected : null),
            'defaults' => LetterStandard::DEFAULTS,
            'file_classes' => $classes,
            'inherited_classes' => $selected !== '' && $classes === [] ? $this->standards->fileClassesFor(null) : [],
        ]);
    }

    public function update(SaveStandardsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $owner = isset($data['organization_id']) && $data['organization_id'] !== '' ? (string) $data['organization_id'] : null;
        $setting = LetterStandard::query()->where(fn ($q) => $owner === null ? $q->whereNull('organization_id') : $q->where('organization_id', $owner))->first()
            ?? new LetterStandard(['organization_id' => $owner]);
        $old = $setting->exists ? $setting->only(array_keys(LetterStandard::DEFAULTS)) : null;
        $setting->fill(array_intersect_key($data, LetterStandard::DEFAULTS));
        $setting->updated_by = $request->user()->id;
        $setting->save();
        $this->audit->record(AuditEventType::LetterStandardsSaved, $request->user(), $setting, $owner, array_intersect_key($data, LetterStandard::DEFAULTS), null, $old);

        return redirect()->route('letters.standards.edit', $owner !== null ? ['organization_id' => $owner] : [])->with('success', __('letters.flash.standards_saved'));
    }

    public function storeClass(SaveFileClassRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $owner = isset($data['organization_id']) && $data['organization_id'] !== '' ? (string) $data['organization_id'] : null;
        $this->saveClass(null, $owner, $data, $request);

        return redirect()->route('letters.standards.edit', $owner !== null ? ['organization_id' => $owner] : [])->with('success', __('letters.flash.file_class_saved'));
    }

    public function updateClass(SaveFileClassRequest $request, LetterFileClass $fileClass): RedirectResponse
    {
        $this->saveClass($fileClass, $fileClass->organization_id, $request->validated(), $request);

        return redirect()->route('letters.standards.edit', $fileClass->organization_id !== null ? ['organization_id' => $fileClass->organization_id] : [])->with('success', __('letters.flash.file_class_saved'));
    }

    /** @param array<string, mixed> $data */
    private function saveClass(?LetterFileClass $class, ?string $owner, array $data, Request $request): void
    {
        $code = strtoupper(trim((string) $data['code']));
        $duplicate = LetterFileClass::query()->where('code', $code)
            ->where(fn ($q) => $owner === null ? $q->whereNull('organization_id') : $q->where('organization_id', $owner))
            ->when($class !== null, fn ($q) => $q->whereKeyNot($class->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['code' => __('letters.validation.file_class_code_taken')]);
        }
        $parent = isset($data['parent_id']) && $data['parent_id'] !== '' ? LetterFileClass::query()->find($data['parent_id']) : null;
        if ($parent !== null && ($parent->organization_id !== $owner || ($class !== null && $parent->id === $class->id))) {
            throw ValidationException::withMessages(['parent_id' => __('letters.validation.file_class_parent')]);
        }

        $class ??= new LetterFileClass(['organization_id' => $owner]);
        $old = $class->exists ? $class->only(['code', 'title_am', 'title_en', 'active', 'position', 'parent_id']) : null;
        $class->fill([
            'parent_id' => $parent?->id,
            'code' => $code,
            'title_am' => isset($data['title_am']) && trim((string) $data['title_am']) !== '' ? trim((string) $data['title_am']) : null,
            'title_en' => isset($data['title_en']) && trim((string) $data['title_en']) !== '' ? trim((string) $data['title_en']) : null,
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'position' => (int) ($data['position'] ?? 0),
            'updated_by' => $request->user()->id,
        ])->save();
        $this->audit->record(AuditEventType::LetterFileClassSaved, $request->user(), $class, $owner, ['code' => $code, 'active' => $class->active], null, $old);
    }
}
