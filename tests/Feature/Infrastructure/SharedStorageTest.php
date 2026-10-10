<?php

declare(strict_types=1);

use App\Support\Storage\StoredFileChecksum;
use Illuminate\Support\Facades\Storage;

/*
 * Production risk register INF-1: behind a load balancer every app node must
 * read the same files. The private (`local`) and `public` disks can be moved
 * to shared S3-compatible storage by environment alone, and integrity checks
 * stream through the disk instead of hashing a node-local path.
 */

function filesystemsConfigWith(array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        return require base_path('config/filesystems.php');
    } finally {
        foreach ($previous as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}

it('keeps both disks on local storage by default', function (): void {
    $config = filesystemsConfigWith([]);

    expect($config['disks']['local']['driver'])->toBe('local')
        ->and($config['disks']['public']['driver'])->toBe('local');
});

it('moves the private disk to shared object storage by environment', function (): void {
    $config = filesystemsConfigWith([
        'STORAGE_PRIVATE_DRIVER' => 's3',
        'STORAGE_PRIVATE_BUCKET' => 'euisis-private',
    ]);

    expect($config['disks']['local']['driver'])->toBe('s3')
        ->and($config['disks']['local']['bucket'])->toBe('euisis-private')
        ->and($config['disks']['local']['visibility'])->toBe('private');
});

it('moves the public disk to shared object storage by environment', function (): void {
    $config = filesystemsConfigWith([
        'STORAGE_PUBLIC_DRIVER' => 's3',
        'STORAGE_PUBLIC_BUCKET' => 'euisis-public',
        'STORAGE_PUBLIC_URL' => 'https://cdn.example.test',
    ]);

    expect($config['disks']['public']['driver'])->toBe('s3')
        ->and($config['disks']['public']['url'])->toBe('https://cdn.example.test');
});

it('has the S3 adapter installed', function (): void {
    expect(class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class))->toBeTrue();
});

it('checks a stored file checksum by streaming through the disk', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('evidence/a.txt', 'evidence-bytes');

    expect(StoredFileChecksum::sha256('local', 'evidence/a.txt'))->toBe(hash('sha256', 'evidence-bytes'))
        ->and(StoredFileChecksum::matches('local', 'evidence/a.txt', hash('sha256', 'evidence-bytes')))->toBeTrue()
        ->and(StoredFileChecksum::matches('local', 'evidence/a.txt', hash('sha256', 'tampered')))->toBeFalse()
        ->and(StoredFileChecksum::matches('local', 'evidence/missing.txt', hash('sha256', 'x')))->toBeFalse();
});
