<?php

use Illuminate\Support\Facades\Route;

// The backend is an API only; the user interface is core-platform/frontend.
Route::get('/', fn () => response()->json(['service' => config('app.name'), 'api' => url('/api/v1'), 'health' => url('/up')]));
