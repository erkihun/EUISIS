<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/**
 * The copy from EUISIS keeps every identifier: the Core snapshot after the
 * import matches the snapshot of the source. A second in-memory database stands
 * in for EUISIS.
 */
class CoreImportTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.euisis' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('euisis');
        Artisan::call('migrate', ['--database' => 'euisis', '--path' => 'database/migrations/2026_10_25_120000_create_golden_path_tables.php', '--force' => true]);
        $this->snapshot = tempnam(sys_get_temp_dir(), 'core-snapshot-');
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshot);
        parent::tearDown();
    }

    /** Builds the Golden Path in the stand-in EUISIS database and fingerprints it there. */
    private function seedSource(): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('euisis');
        try {
            $this->buildGoldenPath();
            $this->artisan('core:snapshot', ['--output' => $this->snapshot])->assertSuccessful();
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    public function test_the_import_copies_every_row_with_identifiers_unchanged(): void
    {
        $this->seedSource();

        $this->artisan('core:import-from-euisis')->assertSuccessful();

        $this->assertSame(3, DB::table('organizations')->count());
        $this->assertSame($this->employee->id, DB::table('employees')->where('employee_number', 'EMP-0001')->value('id'));
        $this->assertSame($this->card->public_card_uuid, DB::table('id_cards')->value('public_card_uuid'));
        $this->artisan('core:snapshot', ['--compare' => $this->snapshot])->assertSuccessful();
    }

    public function test_imported_cards_get_their_legacy_credential_so_printed_qr_codes_keep_verifying(): void
    {
        $this->seedSource();
        $this->assertSame(0, DB::table('digital_credentials')->count());

        $this->artisan('core:import-from-euisis')->assertSuccessful();

        $credential = DB::table('digital_credentials')->where('id_card_id', $this->card->id)->first();
        $this->assertNotNull($credential);
        $this->assertSame('legacy_qr', $credential->kind);
        $check = app(CredentialVerifier::class)->check((string) $this->card->public_card_uuid);
        $this->assertSame((string) $this->card->id, (string) $check['card']?->id);
        // Repeat-safe: a second run (here after a fresh re-import) gives each card one credential.
        $this->artisan('core:import-from-euisis', ['--fresh-tables' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('digital_credentials')->where('id_card_id', $this->card->id)->count());
    }

    public function test_the_snapshot_notices_a_changed_row(): void
    {
        $this->seedSource();
        $this->artisan('core:import-from-euisis')->assertSuccessful();

        DB::table('id_cards')->update(['status' => 'suspended']);

        $this->artisan('core:snapshot', ['--compare' => $this->snapshot])->assertFailed();
    }

    public function test_the_import_refuses_a_core_database_that_already_holds_data(): void
    {
        $this->seedSource();
        $this->artisan('core:import-from-euisis')->assertSuccessful();

        $this->artisan('core:import-from-euisis')->assertFailed();
        $this->artisan('core:import-from-euisis', ['--fresh-tables' => true])->assertSuccessful();
        $this->artisan('core:snapshot', ['--compare' => $this->snapshot])->assertSuccessful();
    }

    public function test_create_user_makes_an_account_with_a_valid_role_only(): void
    {
        $this->artisan('core:create-user', ['email' => 'admin@core.test', 'name' => ['Core', 'Admin'], '--role' => 'core_admin'])->assertSuccessful();
        $this->artisan('core:create-user', ['email' => 'other@core.test', 'name' => ['Other'], '--role' => 'root'])->assertFailed();

        $this->assertDatabaseHas('users', ['email' => 'admin@core.test', 'role' => 'core_admin', 'is_active' => true]);
    }
}
