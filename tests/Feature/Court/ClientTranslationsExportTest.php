<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('the React screens\' dictionary matches lang/*.php', function (): void {
    expect(Artisan::call('court:client-lang', ['--check' => true]))
        ->toBe(0, 'resources/js/court/i18n/lang is out of date: run `php artisan court:client-lang` and commit the result.');
});
