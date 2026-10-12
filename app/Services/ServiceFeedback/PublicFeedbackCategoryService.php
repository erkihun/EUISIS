<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceFeedbackType;
use App\Models\PublicFeedbackCategory;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Categories for public submissions: which ones a visitor may pick for a
 * resolved target, and who may manage which (brief §31–33).
 *
 * City-wide categories are managed by city-level users only; a scoped
 * Organizational Admin manages categories of the organizations in their
 * scope. Nothing here lets one institution touch another's list.
 */
class PublicFeedbackCategoryService
{
    public const PERMISSION = 'service_feedback.manage_categories';

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * Active categories a visitor may choose for this target and type:
     * city-wide ones plus the target organization's own, in display order.
     *
     * @return list<array{id: string, name: string}>
     */
    public function optionsFor(TargetContext $target, ServiceFeedbackType $type, string $locale = 'en'): array
    {
        return PublicFeedbackCategory::query()
            ->active()
            ->visibleToOrganization($target->contextOrganizationId)
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get()
            ->filter(fn (PublicFeedbackCategory $category): bool => $category->appliesTo($type, $target->targetType))
            ->map(fn (PublicFeedbackCategory $category): array => [
                'id' => (string) $category->getKey(),
                'name' => $category->localizedName($locale),
            ])
            ->values()
            ->all();
    }

    /** A category the browser named must be one the visitor could have been offered. */
    public function isApplicable(string $categoryId, TargetContext $target, ServiceFeedbackType $type): bool
    {
        $category = PublicFeedbackCategory::query()->active()->find($categoryId);

        if ($category === null || ! $category->appliesTo($type, $target->targetType)) {
            return false;
        }

        return $category->organization_id === null || $category->organization_id === $target->contextOrganizationId;
    }

    // ── Management ────────────────────────────────────────────────────────

    public function canManage(User $user, ?string $organizationId): bool
    {
        if (! $user->hasAnyPermission([self::PERMISSION])) {
            return false;
        }

        if ($organizationId === null) {
            return $this->canManageCityWide($user);
        }

        return $this->scope->canAccessOrganization($user, $organizationId);
    }

    public function canManageCityWide(User $user): bool
    {
        return $user->hasAnyPermission([self::PERMISSION]) && ! $this->scope->isScopedOrganizationalAdmin($user);
    }

    /** The categories this user may see and manage. */
    public function queryFor(User $user): Builder
    {
        $query = PublicFeedbackCategory::query()->with('organization:id,code,name_en,name_am');

        if ($this->scope->isScopedOrganizationalAdmin($user)) {
            $allowed = $this->scope->allowedOrganizationIds($user);

            $query->where(function (Builder $scope) use ($allowed): void {
                $scope->whereNull('organization_id')->orWhereIn('organization_id', $allowed);
            });
        }

        return $query->orderByRaw('CASE WHEN organization_id IS NULL THEN 0 ELSE 1 END')->orderBy('sort_order')->orderBy('name_en');
    }

    /** @param  array<string, mixed>  $data */
    public function store(User $user, array $data, ?Request $request = null): PublicFeedbackCategory
    {
        $this->assertUniqueCode($data['code'], $data['organization_id'] ?? null, null);

        $category = PublicFeedbackCategory::query()->create([
            ...$this->attributes($data),
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);

        $this->auditChange($user, $category, 'created', $request);

        return $category;
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $user, PublicFeedbackCategory $category, array $data, ?Request $request = null): PublicFeedbackCategory
    {
        $this->assertUniqueCode($data['code'], $data['organization_id'] ?? null, $category);

        $category->fill([...$this->attributes($data), 'updated_by' => $user->getKey()])->save();

        $this->auditChange($user, $category, 'updated', $request);

        return $category;
    }

    /**
     * Delete an unreferenced category; retire a referenced one. Historical
     * submissions keep their category either way (brief §33).
     *
     * @return 'deleted'|'retired'
     */
    public function destroy(User $user, PublicFeedbackCategory $category, ?Request $request = null): string
    {
        if ($category->isReferenced()) {
            $category->forceFill(['is_active' => false, 'updated_by' => $user->getKey()])->save();
            $this->auditChange($user, $category, 'retired', $request);

            return 'retired';
        }

        $this->auditChange($user, $category, 'deleted', $request);
        $category->delete();

        return 'deleted';
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name_en' => trim((string) $data['name_en']),
            'name_am' => $this->trimmedOrNull($data['name_am'] ?? null),
            'description_en' => $this->trimmedOrNull($data['description_en'] ?? null),
            'description_am' => $this->trimmedOrNull($data['description_am'] ?? null),
            'submission_types' => array_values(array_unique((array) $data['submission_types'])),
            'target_types' => array_values(array_unique((array) $data['target_types'])),
            'organization_id' => $data['organization_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    private function assertUniqueCode(string $code, ?string $organizationId, ?PublicFeedbackCategory $except): void
    {
        $taken = PublicFeedbackCategory::query()
            ->where('code', strtoupper(trim($code)))
            ->when($organizationId === null, fn (Builder $query) => $query->whereNull('organization_id'), fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => __('service-feedback.category_code_taken')]);
        }
    }

    private function auditChange(User $user, PublicFeedbackCategory $category, string $action, ?Request $request): void
    {
        $this->audit->execute(
            eventType: AuditEventType::ServiceFeedbackSettingsUpdated,
            actor: $user,
            auditable: $category,
            organizationId: $category->organization_id,
            reason: 'Public feedback category '.$action.': '.$category->code,
            request: $request,
        );
    }

    private function trimmedOrNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
