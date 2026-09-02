<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Enums\CodeRuleScopeStrategy;
use App\Exceptions\MissingSequenceScopeContextException;
use App\Models\CodeRule;
use Illuminate\Support\Carbon;

class SequenceScopeResolver
{
    /**
     * Grouping tokens per entity category. Ordered by specificity — the
     * first token present in the format that can be resolved wins.
     *
     * @var array<string, list<string>>
     */
    private const AUTO_SCOPE_TOKENS = [
        'Organization' => ['ORG_TYPE_PREFIX', 'ORG_TYPE_CODE', 'ORG_PREFIX', 'ORG_CODE', 'PARENT_ORG_PREFIX', 'PARENT_ORG_CODE'],
        'Employee' => ['ORG_TYPE_PREFIX', 'ORG_TYPE_CODE', 'ORG_CODE', 'EMPLOYEE_STATUS', 'UNIT_CODE', 'POSITION_CODE'],
        'Position' => ['OWNER_ORG_CODE', 'HOST_ORG_CODE', 'ORG_CODE', 'UNIT_CODE', 'POSITION_PREFIX'],
        'Service' => ['SERVICE_TYPE_PREFIX', 'SERVICE_TYPE_CODE', 'PROVIDER_PREFIX', 'PROVIDER_CODE'],
        'Location' => ['CITY_CODE', 'SUB_CITY_CODE', 'WOREDA_CODE'],
        'DateAndTime' => ['YEAR', 'YEAR_SHORT', 'MONTH', 'DAY', 'FISCAL_YEAR'],
        'Unit' => ['UNIT_TYPE_PREFIX', 'UNIT_TYPE_CODE', 'UNIT_PREFIX', 'UNIT_CODE'],
    ];

    /**
     * Tokens that must never be used as scope tokens.
     */
    private const EXCLUDED_TOKENS = ['SEQUENCE', 'SEQUENCE_PADDED', 'RAND_6', 'PREFIX', 'SUFFIX', 'SEPARATOR'];

    /**
     * Scope tokens that may legitimately resolve to an empty value. Instead of
     * failing, an empty optional token is simply omitted from the scope key —
     * e.g. HOST_ORG_CODE is empty for positions not hosted inside another
     * organization, giving hosted and direct positions independent sequences.
     */
    private const OPTIONAL_SCOPE_TOKENS = ['HOST_ORG_CODE'];

    public function __construct(
        private readonly CodeFormatTokenResolver $resolver,
    ) {}

    /**
     * Resolve the sequence scope for the given rule and context.
     *
     * @param  array<string, mixed>  $context
     * @return array{scope_key: string, scope_hash: string, scope_values: array<string, string>}
     *
     * @throws MissingSequenceScopeContextException
     */
    public function resolve(CodeRule $rule, array $context): array
    {
        $strategy = $rule->sequence_scope_strategy ?? CodeRuleScopeStrategy::Auto;

        if ($strategy === CodeRuleScopeStrategy::Global) {
            return $this->buildGlobalScope();
        }

        $scopeTokens = $strategy === CodeRuleScopeStrategy::CustomTokens
            ? ($rule->sequence_scope_tokens ?? [])
            : $this->detectAutoScopeTokens($rule->format);

        if (empty($scopeTokens)) {
            return $this->buildGlobalScope();
        }

        return $this->buildScopeFromTokens($scopeTokens, $rule, $context);
    }

    /**
     * Detect which grouping tokens are present in the format string.
     *
     * @return list<string>
     */
    public function detectAutoScopeTokens(string $format): array
    {
        preg_match_all('/\{([A-Z0-9_]+)\}/', $format, $matches);
        $tokensInFormat = $matches[1] ?? [];

        $candidatePool = [];

        foreach (self::AUTO_SCOPE_TOKENS as $tokens) {
            foreach ($tokens as $token) {
                if (in_array($token, $tokensInFormat, true) && ! in_array($token, self::EXCLUDED_TOKENS, true)) {
                    $candidatePool[] = $token;
                }
            }
        }

        return array_values(array_unique($candidatePool));
    }

    /** @return array{scope_key: string, scope_hash: string, scope_values: array<string, string>} */
    private function buildGlobalScope(): array
    {
        $key = '_global_';

        return [
            'scope_key' => $key,
            'scope_hash' => hash('sha256', $key),
            'scope_values' => [],
        ];
    }

    /**
     * @param  list<string>  $scopeTokens
     * @param  array<string, mixed>  $context
     * @return array{scope_key: string, scope_hash: string, scope_values: array<string, string>}
     *
     * @throws MissingSequenceScopeContextException
     */
    private function buildScopeFromTokens(array $scopeTokens, CodeRule $rule, array $context): array
    {
        $now = Carbon::now();
        $scopeValues = [];

        foreach ($scopeTokens as $token) {
            $isOptional = in_array($token, self::OPTIONAL_SCOPE_TOKENS, true);

            try {
                $value = $this->resolver->resolveToken($token, $rule, $context, $now);
            } catch (\Throwable $e) {
                if ($isOptional) {
                    continue;
                }

                throw new MissingSequenceScopeContextException(
                    __('code-rules.missing_sequence_scope_context', ['token' => $token]),
                    previous: $e,
                );
            }

            if ($value === '') {
                if ($isOptional) {
                    continue;
                }

                throw new MissingSequenceScopeContextException(
                    __('code-rules.missing_sequence_scope_context', ['token' => $token]),
                );
            }

            $scopeValues[$token] = $value;
        }

        if ($scopeValues === []) {
            return $this->buildGlobalScope();
        }

        $parts = [];
        foreach ($scopeValues as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        $scopeKey = implode('|', $parts);

        return [
            'scope_key' => $scopeKey,
            'scope_hash' => hash('sha256', $scopeKey),
            'scope_values' => $scopeValues,
        ];
    }
}
