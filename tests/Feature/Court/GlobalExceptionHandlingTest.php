<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

test('web requests receive a generic 500 message without internal details', function () {
    Route::get('/__test/boom-web', function () {
        throw new RuntimeException('SQLSTATE[HY000] Sensitive DB failure at /var/www/secret.php');
    });

    $response = $this->get('/__test/boom-web');

    $response->assertStatus(500);
    $response->assertSeeText('Something went wrong. Please try again later.');
    $response->assertDontSeeText('SQLSTATE');
    $response->assertDontSeeText('/var/www/secret.php');
});


test('validation error responses remain unchanged', function () {
    Route::post('/__test/validation', function (Request $request) {
        $request->validate([
            'name' => ['required', 'string'],
        ]);

        return response()->json(['ok' => true]);
    });

    $response = $this->postJson('/__test/validation', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['name']);
    $response->assertJsonPath('message', 'The name field is required.');
});
