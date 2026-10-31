<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Enums;

/**
 * The Administrative Court's outcome on an appeal against a disciplinary
 * decision (Regulation 201/2018 Arts. 42, 50, 52–54), as the institution
 * receives it.
 */
enum DisciplinaryAppealOutcome: string
{
    // The decision stands: the appeal was dismissed (Art. 42) or the decision confirmed (Art. 52).
    case Upheld = 'upheld';
    // The court changed the decision (Art. 52): the institution implements the changed decision (Art. 54).
    case Modified = 'modified';
    // The court set the decision aside for the appellant (Art. 52): implemented within 10 days (Art. 54).
    case Reversed = 'reversed';
    // Sent back for a new decision within 15 consecutive working days (Art. 53).
    case Remanded = 'remanded';
    // The appellant withdrew the appeal (Art. 50).
    case Withdrawn = 'withdrawn';

    /** The decision as given stands, so its penalty is executed once the appeal ends (Art. 29). */
    public function decisionStands(): bool
    {
        return in_array($this, [self::Upheld, self::Withdrawn], true);
    }

    /** A ruling for the appellant the institution must carry out within 10 days (Art. 54(1)). */
    public function requiresImplementation(): bool
    {
        return in_array($this, [self::Modified, self::Reversed], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
