<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Services\OrganizationDesign\ClassificationImportService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Imports the LOCAL service classification drawn from proclamation 94/2019 through
 * the classification importer: sector, then institution or article, then each
 * numbered function. Names of institutions and functions are the proclamation's;
 * the six sectors are a working grouping the proclamation does not state, and this
 * is not an officially approved classification list. Idempotent, as the importer is,
 * so names edited on the page are overwritten by a re-run. Not part of the default seeding.
 */
class AddisAbabaServiceClassificationSeeder extends Seeder
{
    public const REVISION = 'AA-94-2019';

    public function run(ClassificationImportService $importer): void
    {
        $actor = User::role('Super Admin')->orderBy('id')->first()
            ?? throw new RuntimeException('A Super Admin account must exist before importing a classification.');
        $path = __DIR__.'/data/local-service-classification-94-2019.csv';
        $file = new UploadedFile($path, basename($path), 'text/csv', null, true);

        $preview = $importer->import($actor, 'LOCAL', self::REVISION, $file, true);
        if ($preview->failed_rows > 0) {
            throw new RuntimeException('The classification dataset has errors: '.json_encode($preview->error_report, JSON_UNESCAPED_UNICODE));
        }

        $importer->import($actor, 'LOCAL', self::REVISION, $file, false);
    }
}
