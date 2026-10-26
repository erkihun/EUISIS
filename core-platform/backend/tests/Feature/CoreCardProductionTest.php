<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ChangeCardStatus;
use App\Actions\ManageCardTemplates;
use App\Actions\ManageDigitalCredentials;
use App\Models\CardPrintBatch;
use App\Models\CardTemplate;
use App\Models\CardTemplateVersion;
use App\Models\CoreAuditLog;
use App\Models\DigitalCredential;
use App\Models\IdCard;
use App\Models\User;
use App\Services\CardRenderer;
use App\Services\CredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P3 (ADR 0010): card templates, print batches, the quality check, reprints and card credentials. */
class CoreCardProductionTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function person(string $role): User
    {
        return User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password',
            'role' => $role, 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true,
        ]);
    }

    private function signIn(User $user): User
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /** The fixture's card, back to waiting for its first print. */
    private function pendingCard(): IdCard
    {
        $this->card->forceFill(['status' => IdCard::PENDING_PRINT, 'issued_at' => null, 'activated_at' => null, 'qc_status' => 'not_recorded'])->save();

        return $this->card->fresh();
    }

    public function test_a_template_layout_is_bounded_and_a_second_person_activates_it(): void
    {
        $designer = $this->person('core_admin');
        $templates = app(ManageCardTemplates::class);
        $template = $templates->create(['code' => 'CITY-2026', 'name_en' => 'City card 2026'], $designer);
        $draft = $template->versions()->sole();
        $config = $draft->config;

        foreach ([
            ['fields' => ['full_name', 'photo', 'employee_number', 'card_number']],
            ['colors' => ['primary' => 'red'] + $config['colors']],
            ['header_en' => '<script>alert(1)</script>'],
        ] as $bad) {
            try {
                $templates->saveDraft($draft, array_replace($config, $bad), $designer);
                $this->fail('A bad layout was saved: '.json_encode($bad));
            } catch (ValidationException) {
            }
        }
        $templates->saveDraft($draft, ['header_en' => 'Bureau & Office'] + $config, $designer);

        try {
            $templates->activate($draft, $designer);
            $this->fail('The designer activated their own version.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }
        $templates->activate($draft, $this->person('core_admin'));
        $this->assertSame(1, CardTemplateVersion::query()->where('status', 'active')->count());
        $this->assertSame((string) $draft->id, (string) CardTemplateVersion::active()->id);
        $this->assertSame('retired', CardTemplate::query()->where('code', 'STANDARD')->sole()->versions()->sole()->status);

        $this->signIn($this->person('core_viewer'));
        $response = $this->get('/core/card-templates/versions/'.$draft->id.'/preview')->assertOk();
        $this->assertStringStartsWith('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('Bureau &amp; Office', $response->getContent());
        $this->post('/core/card-templates', ['code' => 'X', 'name_en' => 'X'])->assertForbidden();
    }

    public function test_a_batch_prints_with_its_template_and_each_card_is_checked_by_someone_else(): void
    {
        $card = $this->pendingCard();
        $printer = $this->signIn($this->person('core_officer'));
        $this->post('/print-batches', ['card_ids' => [$card->id]])->assertSessionHasNoErrors();
        $batch = CardPrintBatch::query()->sole();
        $this->assertMatchesRegularExpression('/^PB-\d{4}-00001$/', $batch->batch_number);
        $this->post('/print-batches', ['card_ids' => [$card->id]])->assertSessionHasErrors('card_ids');

        $sheet = $this->get('/print-batches/'.$batch->id.'/sheet')->assertOk();
        $this->assertStringContainsString('<svg', $sheet->getContent());
        $this->assertTrue(CoreAuditLog::query()->where('action', 'card_print_batch.sheet_opened')->exists());

        $this->post('/print-batches/'.$batch->id.'/mark-printed')->assertSessionHasNoErrors();
        $card->refresh();
        $this->assertSame(IdCard::PRINTED, $card->status);
        $this->assertSame((string) $batch->card_template_version_id, (string) $card->card_template_version_id);
        $this->assertSame([1, 'pending', $printer->id], [(int) $card->print_count, $card->qc_status, (int) $card->printed_by]);
        $this->assertNotNull(app(ManageDigitalCredentials::class)->tokenFor($card));

        $this->post('/id-cards/'.$card->id.'/quality-check', ['result' => 'passed'])->assertSessionHasErrors('card');
        $this->signIn($this->person('core_admin'));
        $this->post('/id-cards/'.$card->id.'/issue')->assertSessionHasErrors('card');
        $this->post('/id-cards/'.$card->id.'/quality-check', ['result' => 'failed'])->assertSessionHasErrors('qc_notes');
        $this->post('/id-cards/'.$card->id.'/quality-check', ['result' => 'passed'])->assertSessionHasNoErrors();
        // The card page shows the check (rc2 rehearsal: it broke once qc_at was set).
        $this->get('/id-cards/'.$card->id)->assertOk();
        $this->post('/id-cards/'.$card->id.'/issue')->assertSessionHasNoErrors();
        $this->assertSame(IdCard::ISSUED, $card->fresh()->status);
        $this->assertSame('passed', $batch->items()->sole()->qc_status);

        // A reader whose organization does not cover the batch's cards does not see it.
        $this->signIn(User::query()->create(['name' => 'B', 'email' => 'b@core.test', 'password' => 'secret-password', 'role' => 'core_admin',
            'scope_organization_id' => $this->bureauB->id, 'scope_type' => User::SCOPE_SUBTREE, 'is_active' => true]));
        $this->get('/print-batches/'.$batch->id)->assertNotFound();
    }

    public function test_a_reprint_needs_its_permission_a_reason_and_stays_within_the_limit(): void
    {
        DB::table('core_settings')->insert(['group' => 'id_cards', 'key' => 'max_reprints', 'value' => json_encode(1), 'created_at' => now(), 'updated_at' => now()]);
        $card = $this->pendingCard();
        $officer = $this->person('core_officer');
        app(ChangeCardStatus::class)->execute($card, 'print', null, $officer);
        $this->signIn($this->person('core_officer'));
        $this->post('/id-cards/'.$card->id.'/quality-check', ['result' => 'failed', 'qc_notes' => 'Photo is smudged'])->assertSessionHasNoErrors();
        $this->post('/id-cards/'.$card->id.'/reprint', ['reason' => 'Smudged photo'])->assertForbidden();

        $admin = $this->signIn($this->person('core_admin'));
        $this->post('/id-cards/'.$card->id.'/reprint', ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->post('/id-cards/'.$card->id.'/reprint', ['reason' => 'Smudged photo'])->assertSessionHasNoErrors();
        $this->post('/print-batches', ['card_ids' => [$card->id]])->assertSessionHasNoErrors();
        $batch = CardPrintBatch::query()->sole();
        $this->assertSame('reprint', $batch->items()->sole()->kind);
        $this->post('/print-batches/'.$batch->id.'/mark-printed')->assertSessionHasNoErrors();
        $card->refresh();
        $this->assertSame([2, 'pending', false], [(int) $card->print_count, $card->qc_status, (bool) $card->reprint_required]);
        $this->assertTrue(CoreAuditLog::query()->where('action', 'card.reprinted')->where('subject_id', $card->id)->exists());

        // One reprint is the limit here: the next one is refused (revoke and replace instead).
        $this->post('/id-cards/'.$card->id.'/reprint', ['reason' => 'Smudged again'])->assertSessionHasErrors('card');
        $this->assertSame($admin->id, (int) $card->printed_by);
    }

    public function test_a_credential_verifies_by_its_token_and_stops_after_rotation_or_revocation(): void
    {
        $verifier = app(CredentialVerifier::class);
        // A card from before P3 without a credential row still answers to its printed UUID.
        $this->assertNull($verifier->check((string) $this->card->public_card_uuid)['code']);
        $this->get('/id-checker/'.$this->card->public_card_uuid)->assertInertia(fn ($page) => $page->where('card.status_code', 'active'));

        $credentials = app(ManageDigitalCredentials::class);
        $credentials->issue($this->card, null);
        $token = $credentials->tokenFor($this->card);
        $this->assertSame('CREDENTIAL_UNKNOWN', $verifier->check((string) $this->card->public_card_uuid)['code']);
        $this->get('/id-checker/'.$token)->assertInertia(fn ($page) => $page->where('card.status_code', 'active'));
        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/cards/verify', ['token' => $token])->assertOk()->assertJsonPath('valid', true);
        // Both successful checks count: the public page and the API.
        $this->assertSame(2, DigitalCredential::query()->where('status', 'active')->sole()->verification_count);

        $credentials->rotate($this->card, 'QR photographed by a stranger', $this->person('core_admin'));
        $this->assertSame('CREDENTIAL_SUPERSEDED', $verifier->check($token)['code']);
        $this->get('/id-checker/'.$token)->assertInertia(fn ($page) => $page->where('card.status_code', 'invalid')->where('card.found', false));
        $this->assertTrue($this->card->fresh()->reprint_required);

        $fresh = $credentials->tokenFor($this->card);
        app(ChangeCardStatus::class)->execute($this->card, 'revoke', 'Employee left the service', $this->person('core_admin'));
        $this->assertSame('CREDENTIAL_REVOKED', $verifier->check($fresh)['code']);
    }

    public function test_an_old_key_works_through_its_grace_period_and_rekeying_moves_credentials(): void
    {
        [$old, $new] = ['1:'.str_repeat('a', 40), '2:'.str_repeat('b', 40)];
        config(['core.credential_keys' => $old]);
        $credentials = app(ManageDigitalCredentials::class);
        $credentials->issue($this->card, null);
        $token = $credentials->tokenFor($this->card);

        config(['core.credential_keys' => $new.','.$old.'@'.now()->addDay()->toDateString()]);
        $this->assertNull(app(CredentialVerifier::class)->check($token)['code']);
        config(['core.credential_keys' => $new.','.$old.'@'.now()->subDays(2)->toDateString()]);
        $this->assertSame('KEY_RETIRED', app(CredentialVerifier::class)->check($token)['code']);

        $this->artisan('core:credentials-rekey')->assertSuccessful();
        config(['core.credential_keys' => $new]);
        $this->assertNull(app(CredentialVerifier::class)->check($token)['code']);
        $this->assertSame(2, DigitalCredential::query()->where('status', 'active')->sole()->key_version);
    }

    public function test_the_qr_carries_the_check_address_and_token_only(): void
    {
        $credentials = app(ManageDigitalCredentials::class);
        $credentials->issue($this->card, null);
        $data = app(CardRenderer::class)->dataFor($this->card);

        $this->assertSame(route('id-checker.show', $credentials->tokenFor($this->card)), $data['qr_payload']);
        $this->assertStringNotContainsString($this->employee->employee_number, (string) $data['qr_payload']);
        $this->assertStringNotContainsString((string) $this->card->public_card_uuid, (string) $data['qr_payload']);
        $this->assertArrayNotHasKey('token_encrypted', DigitalCredential::query()->sole()->toArray());
    }
}
