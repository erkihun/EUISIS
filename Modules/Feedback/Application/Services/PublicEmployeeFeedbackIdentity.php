<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Modules\Feedback\Contracts\Data\PublicFeedbackTargetType;

/**
 * Everything the public feedback page may know about a scanned employee: the
 * full name, the title of the post they hold today (their job designation, in
 * the visitor's language) and a short-lived link to the photo. Nothing else —
 * no employee number, contact, position code, grade, unit, assignment history
 * or HR record — and never the employee's, assignment's or position's id or
 * the photo's storage path.
 *
 * The job designation comes from the current active assignment the resolver
 * found (PublicFeedbackTargetResolver::currentAssignment), so the same QR
 * shows the new title the day a transfer takes effect, and no title at all
 * when the employee holds no active post.
 *
 * The photo link is a signed URL on the scanned token itself
 * (service-feedback.photo). The token decides whose photo it is, so no link
 * can be bent towards another employee, and the route checks the token and the
 * employee again on every request, so a revoked token or an employee leaving
 * service ends the photo at once. The expiry is rounded up to a five-minute window, so every
 * screen of one visit asks for the same URL and the photo downloads once.
 */
final readonly class PublicEmployeeFeedbackIdentity
{
    /** A link stays valid for 10 to 15 minutes: long enough to fill in a form. */
    private const LINK_MINUTES = 10;

    private const WINDOW_SECONDS = 300;

    public function __construct(
        public string $displayName,
        public ?string $positionTitle,
        public ?string $photoUrl,
    ) {}

    public static function fromContext(TargetContext $context, string $token, string $locale): self
    {
        if ($context->targetType !== PublicFeedbackTargetType::Employee) {
            throw new InvalidArgumentException('Only an employee target has a public employee identity.');
        }

        $version = $context->extras['photo_version'] ?? null;

        return new self(
            displayName: (string) $context->publicDisplay($locale)['display_name'],
            positionTitle: $context->localized('position', $locale),
            photoUrl: $version === null ? null : URL::temporarySignedRoute(
                'service-feedback.photo',
                self::expiry(),
                ['token' => $token, 'v' => $version],
                absolute: false,
            ),
        );
    }

    /** @return array{target_type: string, display_name: string, position_title: string|null, photo_url: string|null} */
    public function toArray(): array
    {
        return [
            'target_type' => PublicFeedbackTargetType::Employee->value,
            'display_name' => $this->displayName,
            'position_title' => $this->positionTitle,
            'photo_url' => $this->photoUrl,
        ];
    }

    private static function expiry(): Carbon
    {
        $windowEnd = (intdiv(now()->getTimestamp(), self::WINDOW_SECONDS) + 1) * self::WINDOW_SECONDS;

        return Carbon::createFromTimestamp($windowEnd)->addMinutes(self::LINK_MINUTES);
    }
}
