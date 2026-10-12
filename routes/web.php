<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/booking/{path?}', function (?string $path = null) {
    $root = realpath(base_path('booking_bage'));
    abort_unless($root !== false, 404);

    $file = realpath($root.DIRECTORY_SEPARATOR.($path ?: 'booking.html'));
    abort_unless($file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    return response()->file($file, [
        'Content-Type' => $contentTypes[$extension] ?? 'application/octet-stream',
    ]);
})->where('path', '.*');
