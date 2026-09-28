<?php

namespace App\Services\Backup\Infrastructure;

use JsonException;

/**
 * Validates `pgbackrest info --output=json` (pgBackRest 2.33+, which reports per-repository status)
 * before indexing anything, and keeps only allowlisted fields. Unknown formats fail closed.
 */
final class PgBackRestInfoParser
{
    public const LABEL = '/\A\d{8}-\d{6}F(?:_\d{8}-\d{6}[DI])?\z/';

    private const MAX_OUTPUT_BYTES = 8 * 1024 * 1024;

    /**
     * @param  list<int>  $repositoryIds
     * @return list<array{id: int, status: string, encrypted: ?bool, backups: list<array{reference: string, type: string, completed_at: int, size: int}>, errored_backups: int, usage_percent: null, wal_max: ?string}>
     *
     * @throws InvalidCommandOutput
     */
    public function parse(string $output, string $stanza, array $repositoryIds): array
    {
        if (trim($output) === '' || strlen($output) > self::MAX_OUTPUT_BYTES) {
            throw new InvalidCommandOutput('EMPTY_OR_OVERSIZED_OUTPUT');
        }
        try {
            $data = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidCommandOutput('INVALID_JSON');
        }
        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidCommandOutput('UNEXPECTED_SHAPE');
        }
        $repositories = [];
        foreach ($repositoryIds as $id) {
            $repositories[$id] = ['id' => $id, 'status' => 'STANZA_NOT_FOUND', 'encrypted' => null, 'backups' => [],
                'errored_backups' => 0, 'usage_percent' => null, 'wal_max' => null];
        }
        $entry = null;
        foreach ($data as $item) {
            if (is_array($item) && ($item['name'] ?? null) === $stanza) {
                $entry = $item;
            }
        }
        // An empty list, or a list without the configured stanza: nothing to report on.
        if ($entry === null) {
            return array_values($repositories);
        }
        if (! is_int($entry['status']['code'] ?? null) || ! is_array($entry['repo'] ?? null) || ! array_is_list($entry['repo'])) {
            throw new InvalidCommandOutput('UNEXPECTED_SHAPE');
        }
        foreach ($repositoryIds as $id) {
            $repo = null;
            foreach ($entry['repo'] as $candidate) {
                if (is_array($candidate) && ($candidate['key'] ?? null) === $id) {
                    $repo = $candidate;
                }
            }
            if ($repo === null) {
                $repositories[$id]['status'] = 'REPOSITORY_NOT_CONFIGURED';

                continue;
            }
            $code = $repo['status']['code'] ?? null;
            $message = $repo['status']['message'] ?? '';
            if (! is_int($code) || ! is_string($message)) {
                throw new InvalidCommandOutput('UNEXPECTED_SHAPE');
            }
            // The tool's message may contain paths; it is classified here and never returned.
            $repositories[$id]['status'] = match ($code) {
                0, 2 => 'AVAILABLE',
                1, 3 => 'STANZA_NOT_FOUND',
                5 => 'INVALID_CONFIGURATION',
                99 => str_contains(strtolower($message), 'permission denied') ? 'PERMISSION_DENIED' : 'REPOSITORY_UNAVAILABLE',
                default => throw new InvalidCommandOutput('UNKNOWN_STATUS_CODE'),
            };
            $cipher = $repo['cipher'] ?? null;
            $repositories[$id]['encrypted'] = is_string($cipher) ? $cipher !== 'none' : null;
        }
        $backups = $entry['backup'] ?? [];
        if (! is_array($backups) || ! array_is_list($backups)) {
            throw new InvalidCommandOutput('UNEXPECTED_SHAPE');
        }
        foreach ($backups as $backup) {
            $label = is_array($backup) ? ($backup['label'] ?? null) : null;
            $type = is_array($backup) ? ($backup['type'] ?? null) : null;
            $stop = is_array($backup) ? ($backup['timestamp']['stop'] ?? null) : null;
            $size = is_array($backup) ? ($backup['info']['size'] ?? null) : null;
            $repoKey = is_array($backup) ? ($backup['database']['repo-key'] ?? null) : null;
            if (! is_string($label) || ! preg_match(self::LABEL, $label) || ! in_array($type, ['full', 'diff', 'incr'], true)
                || ! is_int($stop) || $stop < 1 || ! is_int($size) || $size < 0 || ! is_int($repoKey)) {
                throw new InvalidCommandOutput('INVALID_BACKUP_ENTRY');
            }
            if (! isset($repositories[$repoKey])) {
                continue;
            }
            // A backup that recorded errors (for example page checksum failures) is not a recovery point.
            if (($backup['error'] ?? false) === true) {
                $repositories[$repoKey]['errored_backups']++;

                continue;
            }
            $repositories[$repoKey]['backups'][] = ['reference' => $label, 'type' => $type, 'completed_at' => $stop, 'size' => $size];
        }
        foreach (is_array($entry['archive'] ?? null) ? $entry['archive'] : [] as $archive) {
            $repoKey = is_array($archive) ? ($archive['database']['repo-key'] ?? null) : null;
            $max = is_array($archive) ? ($archive['max'] ?? null) : null;
            if (is_int($repoKey) && isset($repositories[$repoKey]) && is_string($max) && preg_match('/\A[0-9A-F]{24}\z/', $max)
                && strcmp($max, (string) $repositories[$repoKey]['wal_max']) > 0) {
                $repositories[$repoKey]['wal_max'] = $max;
            }
        }
        foreach ($repositories as &$repository) {
            usort($repository['backups'], fn ($a, $b) => $b['completed_at'] <=> $a['completed_at']);
        }

        return array_values($repositories);
    }
}
