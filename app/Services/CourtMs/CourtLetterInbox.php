<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Models\CourtMsLetter;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CourtLetterNotification;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Letters the administrative tribunal sends institutions registered here
 * (court-ms Flow 5, ADR 0013). `court-ms:poll` reads court-ms's delivery queue
 * after the last cursor, keeps each letter's PDF for its institution and tells
 * the institution's tribunal officers. A letter whose PDF cannot be read yet
 * stops the run there, so the next poll starts again from it.
 */
final class CourtLetterInbox
{
    public const CURSOR_KEY = 'letters_cursor';

    public const DISK = 'local';

    /** Who reads court letters: the institution's tribunal officers, and anyone with the Court Cases module. */
    public const PERMISSIONS = ['grievances.tribunal', 'court_cases.view'];

    public function __construct(
        private readonly CourtMsClient $client,
        private readonly CourtMsStatusSync $state,
        private readonly OrganizationScopeService $scope,
    ) {}

    /**
     * @return array{read: int, stored: int}
     *
     * @throws CourtMsException
     */
    public function run(int $maxPages = 10): array
    {
        $cursor = (int) ($this->state->state(self::CURSOR_KEY) ?? 0);
        $read = 0;
        $stored = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->client->letterUpdates($cursor);
            foreach ($result['data'] as $row) {
                $read++;
                if ($this->receive($row)) {
                    $stored++;
                }
                $cursor = max($cursor, (int) ($row['cursor'] ?? 0));
                $this->state->remember(self::CURSOR_KEY, (string) $cursor);
            }
            if (! $result['has_more']) {
                break;
            }
        }

        return ['read' => $read, 'stored' => $stored];
    }

    /** The letters a person may read: within their organization scope. */
    public function visibleTo(User $user): Builder
    {
        return $this->scope->applyOrganizationScope(CourtMsLetter::query(), $user, 'organization_id');
    }

    public static function canRead(User $user): bool
    {
        return collect(self::PERMISSIONS)->contains(fn (string $permission): bool => $user->can($permission));
    }

    /**
     * Keeps one delivered letter; false when it is not for an institution here or already kept.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws CourtMsException when its PDF cannot be read (the run stops before the cursor moves past it)
     */
    private function receive(array $row): bool
    {
        $letterId = (int) ($row['letter_id'] ?? 0);
        $organization = Organization::query()->find((string) ($row['organization_uuid'] ?? ''));
        if ($letterId < 1 || $organization === null || CourtMsLetter::query()->where('court_letter_id', $letterId)->exists()) {
            return false;
        }

        $pdf = $this->client->letterPdf($letterId);
        $path = "court-ms/letters/{$organization->getKey()}/{$letterId}.pdf";
        Storage::disk(self::DISK)->put($path, $pdf);

        $letter = CourtMsLetter::query()->create([
            'court_letter_id' => $letterId,
            'organization_id' => $organization->getKey(),
            'case_number' => $this->text($row['case_number'] ?? null, 80),
            'case_title' => $this->text($row['case_title'] ?? null, 255),
            'subject' => $this->text($row['subject'] ?? null, 500),
            'reference_number' => $this->text($row['reference_number'] ?? null, 120),
            'recipient' => $this->text($row['recipient'] ?? null, 500),
            'approved_by' => $this->text($row['approved_by'] ?? null, 255),
            'approved_at' => $this->time($row['approved_at'] ?? null),
            'pdf_disk' => self::DISK,
            'pdf_path' => $path,
            'pdf_sha256' => hash('sha256', $pdf),
            'received_at' => now(),
        ]);

        $this->notify($letter);

        return true;
    }

    /** The institution's tribunal officers; city-wide roles read the list without a notice for every institution. */
    private function notify(CourtMsLetter $letter): void
    {
        $url = route('tribunal-cases.letters.index', absolute: false);
        User::permission('grievances.tribunal')->where('status', 'active')->limit(200)->get()
            ->filter(fn (User $user): bool => ! $this->scope->hasUnrestrictedRole($user)
                && $this->scope->canExercisePermission($user, 'grievances.tribunal', (string) $letter->organization_id))
            ->each(fn (User $user) => $user->notify(new CourtLetterNotification((string) ($letter->case_number ?? ''), $url)));
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(strip_tags((string) $value), 0, $max) : null;
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
