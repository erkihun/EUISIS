<?php

declare(strict_types=1);

use App\Models\ClassificationImportBatch;
use App\Models\ServiceClassification;
use App\Models\User;
use Database\Seeders\AddisAbabaServiceClassificationSeeder as ClassificationSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('Super Admin', 'web');
    User::factory()->create()->assignRole('Super Admin');
});

test('the LOCAL classification loads sector, institution and function levels from the proclamation', function (): void {
    $this->seed(ClassificationSeeder::class);

    $rows = ServiceClassification::query()->where('scheme', 'LOCAL')->where('revision', ClassificationSeeder::REVISION);
    $function = $rows->clone()->where('code', 'AA94-D-31-05')->firstOrFail();

    expect($rows->clone()->where('level', 1)->count())->toBe(6)
        ->and($rows->clone()->where('level', 2)->count())->toBe(46)
        ->and($rows->clone()->where('level', 3)->count())->toBe(877)
        ->and($rows->clone()->where('level', '>', 1)->whereNull('parent_id')->count())->toBe(0)
        ->and($rows->clone()->whereNull('name_am')->count())->toBe(0)
        ->and($function->name_am)->toContain('የእናቶችና ህፃናት ጤና')
        ->and($function->name_en)->toBe('Proclamation 94/2019, Article 31(5)')
        ->and($function->parent?->name_am)->toBe('የጤና ቢሮ')
        ->and($function->parent?->parent?->name_am)->toBe('ማኅበራዊ አገልግሎት');
});

test('a re-run changes nothing, and each run leaves its dry run and import on record', function (): void {
    $this->seed(ClassificationSeeder::class);
    $this->seed(ClassificationSeeder::class);

    $last = ClassificationImportBatch::query()->where('dry_run', false)->orderByDesc('created_at')->get()->last();

    expect(ServiceClassification::query()->where('scheme', 'LOCAL')->count())->toBe(929)
        ->and(ClassificationImportBatch::query()->where('dry_run', true)->count())->toBe(2)
        ->and(ClassificationImportBatch::query()->where('dry_run', false)->count())->toBe(2)
        ->and($last->failed_rows)->toBe(0);
});
