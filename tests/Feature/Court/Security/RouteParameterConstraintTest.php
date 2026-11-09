<?php

declare(strict_types=1);

/*
| ID-style route parameters are constrained to digits, so a malformed segment no
| longer reaches an int-typed controller argument (which threw a TypeError 500).
*/

test('a non-numeric id never matches an id route', function (string $uri): void {
    $this->get($uri)->assertNotFound();
})->with([
    'admin case' => '/court/admin/cases/abc',
    'admin case assign' => '/court/admin/cases/abc/assign',
    'admin case file download' => '/court/admin/cases/1/files/abc/download',
    'applicant case' => '/court/applicant/cases/abc',
    'applicant case receipt' => '/court/applicant/cases/abc/receipt',
    'applicant evidence download' => '/court/applicant/cases/1/evidences/abc/download',
    'decision' => '/court/admin/decisions/abc',
    'user' => '/court/users/abc',
]);

test('a numeric id still reaches its route', function (): void {
    // Guests are sent to log in, which proves the route matched.
    $this->get('/court/admin/cases/1')->assertRedirect();
    $this->get('/court/applicant/cases/1')->assertRedirect();
});
