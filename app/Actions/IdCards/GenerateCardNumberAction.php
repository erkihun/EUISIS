<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\CodeRuleEntityType;
use App\Models\IdCard;
use App\Models\User;
use App\Services\CodeGeneration\CodeRuleResolver;

class GenerateCardNumberAction
{
    public function __construct(
        private readonly GenerateCodeAction $generateCodeAction,
        private readonly CodeRuleResolver $codeRuleResolver,
    ) {}

    public function execute(?User $actor = null, array $context = []): string
    {
        if ($this->codeRuleResolver->resolve(CodeRuleEntityType::IdCard, $context) !== null) {
            return $this->generateCodeAction->execute(
                CodeRuleEntityType::IdCard,
                $context,
                $actor,
                null,
                'card_number',
            );
        }

        $year = now()->format('Y');

        do {
            $seq = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $cardNumber = "CARD-{$year}-{$seq}";
        } while (IdCard::query()->where('card_number', $cardNumber)->exists());

        return $cardNumber;
    }
}
