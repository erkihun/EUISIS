<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use Modules\Communication\Domain\Enums\MentionType;

/**
 * Mentions travel as tokens the composer inserts — `<@123>` for a person,
 * `<!channel>` and `<!everyone>` for broadcasts — never as free-text names,
 * so a mention cannot be guessed or spoofed and renaming someone does not
 * break old messages. The text around them is stored as typed and escaped
 * when shown.
 */
final class MentionParser
{
    /** @return array{users: list<int>, channel: bool, everyone: bool} */
    public function parse(?string $body): array
    {
        $body ??= '';
        preg_match_all('/<@(\d{1,19})>/', $body, $m);

        return [
            'users' => array_values(array_unique(array_map('intval', $m[1]))),
            'channel' => str_contains($body, '<!channel>'),
            'everyone' => str_contains($body, '<!everyone>'),
        ];
    }

    /** @return list<MentionType> broadcast mentions used in the body */
    public function broadcasts(?string $body): array
    {
        $parsed = $this->parse($body);

        return array_values(array_filter([
            $parsed['channel'] ? MentionType::Channel : null,
            $parsed['everyone'] ? MentionType::Everyone : null,
        ]));
    }
}
