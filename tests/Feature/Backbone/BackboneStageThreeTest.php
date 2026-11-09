<?php

declare(strict_types=1);

use App\Actions\IdCards\GenerateCardTokenAction;
use App\Backbone\Contracts\CredentialLookup;
use App\Backbone\Contracts\ServiceEligibility;
use App\Backbone\Data\CredentialFormat;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\IdCard;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Support\Str;

/*
 * Stage 3 of the master backbone separation: services resolve scanned
 * credentials and pass the service gate through backbone contracts.
 */

/** @return array{0: Employee, 1: IdCard} */
function stageThreeCard(CardStatus $status = CardStatus::Active): array
{
    $employee = Employee::query()->create([
        'employee_number' => 'S3-'.Str::upper(Str::random(8)), 'first_name' => 'Stage', 'last_name' => 'Three',
        'full_name' => 'Stage Three', 'status' => EmployeeStatus::Active,
    ]);
    $card = IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'S3C-'.Str::upper(Str::random(8)), 'status' => $status,
        'expires_at' => now()->addYear(), 'token_version' => 0, 'is_current' => true,
        'public_card_uuid' => (string) Str::uuid(), 'qr_status' => 'active',
    ]);

    return [$employee, $card->fresh()];
}

it('resolves the printed QR, the bare card UUID and the token format only where accepted', function (): void {
    [, $card] = stageThreeCard();
    $raw = app(GenerateCardTokenAction::class)->execute($card);
    $lookup = app(CredentialLookup::class);
    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card->fresh());

    expect($lookup->resolveScan($url, CredentialFormat::PublicReference)?->cardRecordUuid)->toBe((string) $card->id)
        ->and($lookup->resolveScan($card->public_card_uuid, CredentialFormat::PublicReference)?->cardRecordUuid)->toBe((string) $card->id)
        ->and($lookup->resolveScan($card->id.'|'.$raw, CredentialFormat::PublicReference, CredentialFormat::Token)?->cardRecordUuid)->toBe((string) $card->id)
        // Transport accepts only the printed QR: the token format is not tried.
        ->and($lookup->resolveScan($card->id.'|'.$raw, CredentialFormat::PublicReference))->toBeNull()
        ->and($lookup->resolveScan($card->id.'|wrong-token', CredentialFormat::Token))->toBeNull()
        ->and($lookup->resolveScan('', CredentialFormat::PublicReference))->toBeNull();
});

it('accepts the legacy card-id URL only while the operator switch is on', function (): void {
    [, $card] = stageThreeCard();
    $legacy = 'https://old.example/verify/'.$card->id;

    config(['id_cards.accept_legacy_qr' => false]);
    expect(app(CredentialLookup::class)->resolveScan($legacy, CredentialFormat::LegacyCardId))->toBeNull();

    config(['id_cards.accept_legacy_qr' => true]);
    expect(app(CredentialLookup::class)->resolveScan($legacy, CredentialFormat::LegacyCardId)?->cardRecordUuid)->toBe((string) $card->id);
});

it('returns a found card whatever its state, with the backbone verdict', function (): void {
    [, $card] = stageThreeCard(CardStatus::Revoked);

    expect(app(CredentialLookup::class)->resolveScan($card->public_card_uuid, CredentialFormat::PublicReference))
        ->valid->toBeFalse()
        ->status->toBe('revoked');
});

it('gates a presented card, and refuses a card that belongs to someone else', function (): void {
    [$holder, $card] = stageThreeCard();
    [$other] = stageThreeCard();
    $gate = app(ServiceEligibility::class);

    expect($gate->checkCard($card->id, 'cafeteria'))->eligible->toBeTrue()
        ->and($gate->checkCard($card->id, 'cafeteria', $holder->id))->eligible->toBeTrue()
        ->and($gate->checkCard($card->id, 'cafeteria', $other->id))->eligible->toBeFalse()
        ->and($gate->checkCard((string) Str::uuid(), 'cafeteria'))->eligible->toBeFalse();

    $card->update(['status' => CardStatus::Lost]);
    expect($gate->checkCard($card->id, 'transport'))->eligible->toBeFalse()->reasonCode->toBe('id_card_lost');
});
