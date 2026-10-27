<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Employee documents, photos and signatures (ADR 0009): never public, read through the authorized download.
        'evidence' => [
            'driver' => 'local',
            'root' => env('CORE_EVIDENCE_ROOT', storage_path('app/private/employee-evidence')),
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        // Business import source files (ADR 0009): private, kept for the batch's audit trail.
        // Report exports (ADR 0011): private, downloaded only by the person who asked, removed after their retention.
        'exports' => [
            'driver' => 'local',
            'root' => env('CORE_EXPORTS_ROOT', storage_path('app/private/exports')),
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        // Audit seal heads off the database server (ADR 0012): the root is a mount of another host,
        // or set CORE_AUDIT_HEAD_DISK=s3 to an object-locked bucket instead.
        'audit_heads' => [
            'driver' => 'local',
            'root' => env('CORE_AUDIT_HEAD_ROOT', storage_path('app/private/audit-heads')),
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'imports' => [
            'driver' => 'local',
            'root' => env('CORE_IMPORTS_ROOT', storage_path('app/private/imports')),
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
