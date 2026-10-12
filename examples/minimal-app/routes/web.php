<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get(
    '/documents/{document}/approve',
    static fn (string $document): array => [
        'document' => (int) $document,
        'status' => 'approved',
    ],
)->middleware([
    'auth',
    'gatetrail.permission:documents.approve',
]);
