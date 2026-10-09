<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

/**
 * What happened to one row's code column: the value the user supplied (if any),
 * the value the row will end up with, and where that value came from.
 *
 * This is what the preview's "Provided Code / Generated Code / Code Rule Used"
 * columns render, and it is also what the confirm step compares against so a
 * code that shifted between preview and confirm can be reported as a conflict
 * instead of silently importing under a different number.
 */
final readonly class CodeAssignment
{
    public const SOURCE_PROVIDED = 'provided';

    public const SOURCE_GENERATED = 'generated_by_code_rule';

    private function __construct(
        public StructureSheet $sheet,
        public int $row,
        public string $name,
        public ?string $providedCode,
        public ?string $code,
        public string $source,
        public ?string $codeRuleName,
        public bool $usesRandomToken,
    ) {}

    public static function provided(StructureSheet $sheet, int $row, string $name, string $code): self
    {
        return new self($sheet, $row, $name, $code, $code, self::SOURCE_PROVIDED, null, false);
    }

    public static function generated(
        StructureSheet $sheet,
        int $row,
        string $name,
        ?string $code,
        ?string $codeRuleName,
        bool $usesRandomToken = false,
    ): self {
        return new self($sheet, $row, $name, null, $code, self::SOURCE_GENERATED, $codeRuleName, $usesRandomToken);
    }

    public function isGenerated(): bool
    {
        return $this->source === self::SOURCE_GENERATED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sheet' => $this->sheet->value,
            'row' => $this->row,
            'name' => $this->name,
            'provided_code' => $this->providedCode,
            'generated_code' => $this->isGenerated() ? $this->code : null,
            'code' => $this->code,
            'source' => $this->source,
            'code_rule' => $this->codeRuleName,
            'uses_random_token' => $this->usesRandomToken,
        ];
    }
}
