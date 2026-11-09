<?php

declare(strict_types=1);

/*
| The language switch sends users back where they came from. The return URL is
| user input, so it may only point back into this application.
*/

test('the language switch never redirects to another site', function (string $target): void {
    $this->get(route('court.language.switch', ['locale' => 'en', 'return' => $target]))
        ->assertRedirect(route('court.root'));
})->with([
    'absolute foreign url' => 'https://evil.example/phish',
    'protocol-relative url' => '//evil.example/phish',
    'backslash trick' => '/\\evil.example',
    'javascript scheme' => 'javascript:alert(1)',
    'foreign host with our path' => 'https://evil.example'.'/court/applicant/login',
]);

test('the language switch returns to a page in this application', function (): void {
    $this->get(route('court.language.switch', ['locale' => 'am', 'return' => url('/court/applicant/login?x=1')]))
        ->assertRedirect(url('/court/applicant/login?x=1'));

    $this->get(route('court.language.switch', ['locale' => 'en', 'return' => '/court/home']))
        ->assertRedirect(url('/court/home'));
});

test('the language switch still stores the chosen locale', function (): void {
    $this->get(route('court.language.switch', ['locale' => 'am', 'return' => 'https://evil.example']))
        ->assertSessionHas('app_locale', 'am');
});
