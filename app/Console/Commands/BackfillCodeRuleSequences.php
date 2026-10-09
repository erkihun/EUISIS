<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CodeRuleScopeStrategy;
use App\Models\CodeRule;
use App\Models\CodeRuleSequence;
use App\Services\CodeGeneration\SequenceScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfill code_rule_sequences rows from existing generation logs.
 *
 * For each CodeRule the command:
 * 1. Determines the scope strategy (auto / global / custom_tokens).
 * 2. Groups all CodeGenerationLog rows by their resolved scope key.
 * 3. Finds the max sequence_number per scope group.
 * 4. Creates or updates code_rule_sequences rows with next_number = max + 1.
 *
 * With --dry-run nothing is written; the command only prints what it would do.
 */
class BackfillCodeRuleSequences extends Command
{
    protected $signature = 'code-rules:backfill-sequences
                            {--dry-run : Print what would be done without making any changes}
                            {--rule= : Only process the code rule with this UUID}';

    protected $description = 'Backfill per-scope sequence counters from existing code generation logs';

    public function __construct(
        private readonly SequenceScopeResolver $scopeResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $onlyRuleId = $this->option('rule');

        if ($isDryRun) {
            $this->line('<fg=yellow>[DRY RUN] No changes will be written.</>');
        }

        $query = CodeRule::with(['sequences'])
            ->whereNull('deleted_at');

        if ($onlyRuleId !== null) {
            $query->where('id', $onlyRuleId);
        }

        $rules = $query->get();

        if ($rules->isEmpty()) {
            $this->warn('No code rules found.');

            return self::SUCCESS;
        }

        $this->line("Processing {$rules->count()} code rule(s)…");
        $this->newLine();

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach ($rules as $rule) {
            [$created, $updated, $skipped] = $this->processRule($rule, $isDryRun);
            $totalCreated += $created;
            $totalUpdated += $updated;
            $totalSkipped += $skipped;
        }

        $this->newLine();
        $this->line('─────────────────────────────────────────────');

        if ($isDryRun) {
            $this->line("Would create: <fg=green>{$totalCreated}</> | Would update: <fg=yellow>{$totalUpdated}</> | Skipped: {$totalSkipped}");
        } else {
            $this->line("Created: <fg=green>{$totalCreated}</> | Updated: <fg=yellow>{$totalUpdated}</> | Skipped: {$totalSkipped}");
        }

        return self::SUCCESS;
    }

    /**
     * Process a single CodeRule.
     *
     * @return array{int, int, int} [created, updated, skipped]
     */
    private function processRule(CodeRule $rule, bool $isDryRun): array
    {
        $strategy = $rule->sequence_scope_strategy ?? CodeRuleScopeStrategy::Auto;
        $strategyLabel = $strategy instanceof CodeRuleScopeStrategy ? $strategy->value : (string) $strategy;

        $this->line("<fg=cyan>Rule:</> {$rule->name_en} ({$rule->id})  strategy={$strategyLabel}");

        // Determine which tokens define the scope
        $scopeTokens = match (true) {
            $strategy === CodeRuleScopeStrategy::Global => [],
            $strategy === CodeRuleScopeStrategy::CustomTokens => $rule->sequence_scope_tokens ?? [],
            default => $this->scopeResolver->detectAutoScopeTokens($rule->format),
        };

        // Fetch generation logs for this rule
        $logs = DB::table('code_generation_logs')
            ->where('code_rule_id', $rule->id)
            ->orderBy('sequence_number')
            ->get(['sequence_number', 'generated_code', 'metadata']);

        if ($logs->isEmpty()) {
            $this->line('  <fg=gray>No generation logs — nothing to backfill.</>  ');

            return [0, 0, 0];
        }

        // Group logs by scope key
        $scopeGroups = $this->groupLogsByScopeKey($logs, $rule, $scopeTokens);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($scopeGroups as $scopeKey => $group) {
            $maxSeq = $group['max_sequence'];
            $lastCode = $group['last_code'];
            $scopeHash = hash('sha256', $scopeKey);
            $nextNumber = $maxSeq + 1;

            $existing = $rule->sequences->first(
                fn (CodeRuleSequence $s) => $s->sequence_scope_hash === $scopeHash
            );

            if ($existing !== null) {
                if ($existing->next_number >= $nextNumber) {
                    $this->line("  <fg=gray>SKIP  scope={$scopeKey}  existing next_number={$existing->next_number} >= {$nextNumber}</>");
                    $skipped++;

                    continue;
                }

                $this->line("  <fg=yellow>UPDATE</> scope={$scopeKey}  next_number: {$existing->next_number} → {$nextNumber}");

                if (! $isDryRun) {
                    DB::transaction(function () use ($existing, $nextNumber, $maxSeq, $lastCode) {
                        $seq = CodeRuleSequence::lockForUpdate()->find($existing->id);
                        if ($seq !== null && $seq->next_number < $nextNumber) {
                            $seq->next_number = $nextNumber;
                            $seq->last_number = $maxSeq;
                            $seq->last_generated_code = $lastCode;
                            $seq->save();
                        }
                    });
                }

                $updated++;
            } else {
                $scopeValues = $this->parseScopeValues($scopeKey);

                $this->line("  <fg=green>CREATE</> scope={$scopeKey}  next_number={$nextNumber}");

                if (! $isDryRun) {
                    CodeRuleSequence::create([
                        'code_rule_id' => $rule->id,
                        'sequence_scope_key' => $scopeKey,
                        'sequence_scope_hash' => $scopeHash,
                        'sequence_scope_values' => $scopeValues ?: null,
                        'next_number' => $nextNumber,
                        'last_number' => $maxSeq,
                        'last_generated_code' => $lastCode,
                    ]);
                }

                $created++;
            }
        }

        return [$created, $updated, $skipped];
    }

    /**
     * Group generation logs by their scope key.
     *
     * If scope tokens are empty (global strategy), everything maps to `_global_`.
     * Otherwise the command attempts to read scope values from the log's `metadata`
     * field (where the generator stores `sequence_scope_key` since the new code),
     * then falls back to extracting token values by parsing the generated code
     * against the rule's format pattern.
     *
     * @param  Collection<int, object>  $logs
     * @param  list<string>  $scopeTokens
     * @return array<string, array{max_sequence: int, last_code: string|null}>
     */
    private function groupLogsByScopeKey($logs, CodeRule $rule, array $scopeTokens): array
    {
        $groups = [];

        foreach ($logs as $log) {
            if (empty($scopeTokens)) {
                $key = '_global_';
            } else {
                // Try metadata first (logs generated after the new code path)
                $metadata = is_string($log->metadata) ? json_decode($log->metadata, true) : (array) ($log->metadata ?? []);
                $key = $metadata['sequence_scope_key'] ?? null;

                if ($key === null) {
                    // Fall back: parse code against format to extract token values
                    $key = $this->extractScopeKeyFromCode(
                        (string) $log->generated_code,
                        $rule->format,
                        $scopeTokens,
                    );
                }

                if ($key === null) {
                    // Cannot determine scope — treat as global fallback
                    $key = '_global_';
                }
            }

            $seq = (int) $log->sequence_number;

            if (! isset($groups[$key])) {
                $groups[$key] = ['max_sequence' => $seq, 'last_code' => $log->generated_code];
            } elseif ($seq > $groups[$key]['max_sequence']) {
                $groups[$key]['max_sequence'] = $seq;
                $groups[$key]['last_code'] = $log->generated_code;
            }
        }

        return $groups;
    }

    /**
     * Attempt to extract scope token values from a generated code by converting
     * the format string into a regex and capturing named groups.
     *
     * Returns a `TOKEN=VALUE|TOKEN2=VALUE2` scope key, or null on failure.
     *
     * @param  list<string>  $scopeTokens
     */
    private function extractScopeKeyFromCode(string $code, string $format, array $scopeTokens): ?string
    {
        // Build a regex from the format string
        $pattern = $this->formatToRegex($format, $scopeTokens);

        if ($pattern === null) {
            return null;
        }

        if (! preg_match($pattern, $code, $matches)) {
            return null;
        }

        $parts = [];
        foreach ($scopeTokens as $token) {
            $value = $matches[$token] ?? '';
            if ($value === '') {
                return null;
            }
            $parts[] = "{$token}={$value}";
        }

        return implode('|', $parts);
    }

    /**
     * Convert a code-rule format string to a named-capture regex.
     *
     * Returns null if the regex cannot be built reliably.
     *
     * @param  list<string>  $scopeTokens
     */
    private function formatToRegex(string $format, array $scopeTokens): ?string
    {
        // Escape everything except our {TOKEN} placeholders
        $parts = preg_split('/(\{[A-Z0-9_]+\})/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return null;
        }

        $regex = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Z0-9_]+)\}$/', $part, $m)) {
                $token = $m[1];

                if (in_array($token, $scopeTokens, true)) {
                    // Named capture group for scope tokens — match alphanumeric runs
                    $regex .= '(?P<'.$token.'>[A-Z0-9]+)';
                } elseif (in_array($token, ['SEQUENCE', 'SEQUENCE_PADDED'])) {
                    $regex .= '\d+';
                } else {
                    // Generic token — match any non-separator alphanumeric run (possibly empty)
                    $regex .= '[A-Z0-9]*';
                }
            } else {
                $regex .= preg_quote($part, '/');
            }
        }

        return '/^'.$regex.'$/';
    }

    /**
     * Parse a `TOKEN=VALUE|TOKEN2=VALUE2` scope key into an associative array.
     * Returns an empty array for the global key.
     *
     * @return array<string, string>
     */
    private function parseScopeValues(string $scopeKey): array
    {
        if ($scopeKey === '_global_') {
            return [];
        }

        $values = [];

        foreach (explode('|', $scopeKey) as $pair) {
            [$token, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($token !== '') {
                $values[$token] = $value;
            }
        }

        return $values;
    }
}
