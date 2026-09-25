<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password hashing (docs/password-security-policy.md)
    |--------------------------------------------------------------------------
    |
    | Argon2id (memory-hard, OWASP's first choice). bcrypt was the previous
    | default and silently ignores everything after the 72nd byte, which a
    | 64-128 character (or Amharic, 3 bytes per character) password exceeds.
    |
    | `argon2id_migrating` is Argon2id that still VERIFIES existing bcrypt
    | hashes, so no one is locked out. With `rehash_on_login`, a bcrypt hash is
    | replaced by an Argon2id hash the next time its owner signs in.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id_migrating'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    // 64 MiB, 4 passes, 1 lane: above the OWASP minimum (19 MiB, 2 passes).
    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => true,

];
