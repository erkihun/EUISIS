<?php

declare(strict_types=1);

namespace Tests\Feature\Images;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Support\Images\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OptimizeStoredImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shrinks_existing_logos_and_photos_and_points_records_at_the_new_files(): void
    {
        Storage::fake('public');
        $organization = $this->organizationWithLogo('organizations/logos/x/logo.png');
        $user = User::factory()->create(['profile_photo_path' => 'users/profile-photos/1/photo.png']);
        Storage::disk('public')->put($organization->logo_path, $this->pngBytes(1400, 1400));
        Storage::disk('public')->put($user->profile_photo_path, $this->pngBytes(2000, 1500));

        $this->artisan('images:optimize')->assertSuccessful();

        $expectedExtension = ImageOptimizer::supportsWebp() ? 'webp' : 'png';
        $organization->refresh();
        $user->refresh();

        $this->assertSame('organizations/logos/x/logo.'.$expectedExtension, $organization->logo_path);
        $this->assertSame('users/profile-photos/1/photo.'.$expectedExtension, $user->profile_photo_path);
        Storage::disk('public')->assertExists($organization->logo_path);
        Storage::disk('public')->assertExists($user->profile_photo_path);

        if ($expectedExtension !== 'png') {
            Storage::disk('public')->assertMissing('organizations/logos/x/logo.png');
        }

        [$width, $height] = getimagesize(Storage::disk('public')->path($organization->logo_path));
        $this->assertSame([512, 512], [$width, $height]);
        [$width] = getimagesize(Storage::disk('public')->path($user->profile_photo_path));
        $this->assertSame(1024, $width);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Storage::fake('public');
        $organization = $this->organizationWithLogo('organizations/logos/x/logo.png');
        Storage::disk('public')->put($organization->logo_path, $this->pngBytes(1400, 1400));
        $bytes = Storage::disk('public')->size($organization->logo_path);

        $this->artisan('images:optimize', ['--dry-run' => true])
            ->expectsOutputToContain('would')
            ->assertSuccessful();

        $this->assertSame('organizations/logos/x/logo.png', $organization->refresh()->logo_path);
        $this->assertSame($bytes, Storage::disk('public')->size($organization->logo_path));
    }

    private function organizationWithLogo(string $logoPath): Organization
    {
        $type = OrganizationType::query()->create(['code' => 'dept', 'name_en' => 'Department']);

        return Organization::query()->create([
            'organization_type_id' => $type->id,
            'code' => 'ORG-LOGO',
            'name_en' => 'Logo Bureau',
            'status' => OrganizationStatus::Active,
            'effective_from' => now()->toDateString(),
            'logo_path' => $logoPath,
        ]);
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 18, 33, 112));

        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }
}
