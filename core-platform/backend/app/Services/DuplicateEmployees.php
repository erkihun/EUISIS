<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One person, one employee record. Before a registration the Core looks for
 * the person among every employee of the city, past ones included:
 *  - the same national ID (its keyed hash) is a confirmed duplicate: refused;
 *  - the same full name and date of birth is a strong match;
 *  - the same full name, phone or e-mail is a possible match.
 * Matches are never merged automatically: an officer either stops, or records
 * that it is a different person with a reason (audited and kept for review).
 * A match outside the officer's organizations shows only its number and name.
 */
final readonly class DuplicateEmployees
{
    public const STRONG = 'strong_match';

    public const POSSIBLE = 'possible_match';

    public function __construct(private OrganizationScope $scope) {}

    /** The national ID lookup hash, compatible with EUISIS when NATIONAL_ID_HASH_KEY is shared. */
    public static function nationalIdHash(?string $nationalId): ?string
    {
        $normalized = $nationalId === null ? '' : preg_replace('/\s+/', '', $nationalId);
        if ($normalized === '' || $normalized === null) {
            return null;
        }
        $key = (string) config('core.national_id_hash_key', '');
        if ($key === '') {
            $appKey = (string) config('app.key');
            if (str_starts_with($appKey, 'base64:')) {
                $appKey = (string) base64_decode(substr($appKey, 7), true);
            }
            $key = hash_hmac('sha256', 'euisis:national-id-hash:v1', $appKey, true);
        }

        return hash_hmac('sha256', $normalized, $key);
    }

    public static function normalizedName(string $fullName): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $fullName)));
    }

    /**
     * @param  array{full_name: string, date_of_birth?: ?string, phone?: ?string, email?: ?string}  $person
     * @return list<array{employee_id: string, employee_number: string, full_name: string, status: string, level: string, reasons: list<string>, in_scope: bool}>
     */
    public function matches(array $person, User $actor): array
    {
        $name = self::normalizedName($person['full_name']);
        $dob = $person['date_of_birth'] ?? null;
        $phone = trim((string) ($person['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($person['email'] ?? '')));

        /** @var Collection<int, Employee> $candidates */
        $candidates = Employee::query()->with('currentAssignment:id,organization_id')
            ->where(function ($q) use ($name, $phone, $email): void {
                $q->whereRaw('lower(full_name) = ?', [$name]);
                if ($phone !== '') {
                    $q->orWhere('phone', $phone);
                }
                if ($email !== '') {
                    $q->orWhereRaw('lower(email) = ?', [$email]);
                }
            })
            ->limit(20)
            ->get(['id', 'employee_number', 'full_name', 'status', 'date_of_birth', 'phone', 'email', 'current_assignment_id']);

        return $candidates->map(function (Employee $employee) use ($name, $dob, $phone, $email, $actor): array {
            $reasons = [];
            $sameName = self::normalizedName((string) $employee->full_name) === $name;
            $sameBirth = $dob !== null && $employee->date_of_birth?->toDateString() === $dob;
            if ($sameName && $sameBirth) {
                $reasons[] = 'name_and_date_of_birth';
            } elseif ($sameName) {
                $reasons[] = 'name';
            }
            if ($phone !== '' && $employee->phone === $phone) {
                $reasons[] = 'phone';
            }
            if ($email !== '' && mb_strtolower((string) $employee->email) === $email) {
                $reasons[] = 'email';
            }

            return [
                'employee_id' => (string) $employee->id,
                'employee_number' => (string) $employee->employee_number,
                'full_name' => (string) $employee->full_name,
                'status' => (string) $employee->status,
                'level' => in_array('name_and_date_of_birth', $reasons, true) ? self::STRONG : self::POSSIBLE,
                'reasons' => $reasons,
                'in_scope' => $this->scope->allows($actor, $employee->currentAssignment?->organization_id),
            ];
        })->sortBy(fn (array $match): int => $match['level'] === self::STRONG ? 0 : 1)->values()->all();
    }
}
