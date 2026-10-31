<?php

declare(strict_types=1);

namespace Modules\Court\Contracts\Data;

/** The court's answer to a CourtIntegration call: a status, a body (JSON or a file) and its headers. */
final readonly class CourtAnswer
{
    /** @param array<string, string> $headers lower-case names (content-type, content-disposition) */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = ['content-type' => 'application/json'],
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return (array) json_decode($this->body, true);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
