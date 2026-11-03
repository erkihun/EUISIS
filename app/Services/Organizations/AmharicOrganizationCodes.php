<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Actions\OrganizationTypes\UpdateOrganizationTypeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\CodeRule;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves existing records to the owner's code scheme: an organization type is
 * {its Amharic prefix}-{SEQUENCE} and an organization is
 * {ORG_TYPE_PREFIX}-{SEQUENCE}. New records get these codes when created; this
 * is for records made before the scheme, or left behind when a type's prefix
 * changed. An Amharic prefix already on a type is kept. Idempotent, and archived
 * organizations keep the code they were archived with.
 */
readonly class AmharicOrganizationCodes
{
    public const ORGANIZATION_FORMAT = '{ORG_TYPE_PREFIX}-{SEQUENCE}';

    /** Pads short numbers; a sequence past 99 simply grows a digit. */
    public const SEQUENCE_LENGTH = 2;

    /** Amharic names for the types the system ships without one, by their original code. */
    private const TYPE_NAMES = [
        'CITY_GOVERNMENT' => 'ከተማ አስተዳደር',
        'MAYOR_OFFICE' => 'የከንቲባ ጽህፈት ቤት',
        'BUREAU' => 'ቢሮ',
        'AUTHORITY' => 'ባለሥልጣን',
        'COMMISSION' => 'ኮሚሽን',
        'AGENCY' => 'ኤጀንሲ',
        'SECTOR' => 'ዘርፍ',
        'DIRECTORATE' => 'ዳይሬክቶሬት',
        'DEPARTMENT' => 'ዲፓርትመንት',
        'SUB_CITY' => 'ክፍለ ከተማ',
        'WOREDA' => 'ወረዳ',
        'BRANCH' => 'ቅርንጫፍ',
        'POOL' => 'ፑል',
        'SERVICE_PROVIDER' => 'አገልግሎት ሰጪ',
        'TEAM' => 'ቡድን',
    ];

    public function __construct(
        private GenerateCodeAction $generateCode,
        private UpdateOrganizationTypeAction $updateType,
        private WriteAuditLogAction $audit,
    ) {}

    /** @return array{types: int, organizations: int} How many records received a new code. */
    public function apply(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $this->applyRules();
            $types = $this->applyTypes($actor);

            return ['types' => $types, 'organizations' => $this->applyOrganizations($actor)];
        });
    }

    private function applyRules(): void
    {
        CodeRule::query()
            ->whereIn('entity_type', [CodeRuleEntityType::Organization->value, CodeRuleEntityType::OrganizationType->value])
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->get()
            ->each(function (CodeRule $rule): void {
                $rule->sequence_length = self::SEQUENCE_LENGTH;
                $isOrganizationRule = $rule->entity_type === CodeRuleEntityType::Organization->value
                    || $rule->entity_type === CodeRuleEntityType::Organization;
                if ($isOrganizationRule && $rule->format !== self::ORGANIZATION_FORMAT) {
                    $rule->fill(['format' => self::ORGANIZATION_FORMAT, 'prefix' => null, 'initial_sequence_number' => 1]);
                }
                $rule->save();
            });
    }

    private function applyTypes(User $actor): int
    {
        $changed = 0;

        OrganizationType::query()->orderBy('created_at')->orderBy('code')->get()
            ->each(function (OrganizationType $type) use ($actor, &$changed): void {
                $nameAm = $type->name_am ?? self::TYPE_NAMES[$type->code] ?? null;
                $prefix = preg_match('/^\p{Ethiopic}+$/u', (string) $type->prefix) === 1
                    ? $type->prefix
                    : OrganizationType::prefixFromName($nameAm);
                if ($prefix === null) {
                    return;
                }

                $code = self::followsScheme($type->code) ? $prefix.'-'.self::number($type->code) : $this->generateCode->execute(
                    CodeRuleEntityType::OrganizationType,
                    ['code_prefix' => $prefix],
                    $actor,
                );
                $category = $type->category ?? OrganizationType::DEFAULT_CATEGORIES[$nameAm] ?? null;
                if ($code === $type->code && $type->prefix === $prefix && $type->name_am === $nameAm && $type->category === $category) {
                    return;
                }

                $this->updateType->execute(['code' => $code, 'prefix' => $prefix, 'name_am' => $nameAm, 'category' => $category], $type, $actor);
                $changed++;
            });

        return $changed;
    }

    private function applyOrganizations(User $actor): int
    {
        $changed = 0;

        Organization::query()->with('type')->orderBy('created_at')->orderBy('code')->get()
            ->each(function (Organization $organization) use ($actor, &$changed): void {
                $old = (string) $organization->code;
                $prefix = $organization->type?->prefix;
                if ($prefix === null) {
                    return;
                }

                // The sequence belongs to the prefix, so a new prefix means a new number.
                $code = self::followsScheme($old) && explode('-', $old, 2)[0] === $prefix
                    ? $prefix.'-'.self::number($old)
                    : $this->generateCode->execute(
                        CodeRuleEntityType::Organization,
                        ['organization_type_id' => $organization->organization_type_id],
                        $actor,
                    );
                if ($code === $old) {
                    return;
                }
                $organization->forceFill(['code' => $code])->save();

                $this->audit->execute(AuditEventType::OrganizationUpdated, $actor, $organization, $organization->id,
                    oldValues: ['code' => $old], newValues: ['code' => $code]);
                $changed++;
            });

        return $changed;
    }

    /** The code's number at the current length: ቢ-00014 gives 14. */
    private static function number(string $code): string
    {
        return str_pad((string) (int) explode('-', $code, 2)[1], self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);
    }

    private static function followsScheme(string $code): bool
    {
        return preg_match('/^\p{Ethiopic}+-\d+$/u', $code) === 1;
    }
}
