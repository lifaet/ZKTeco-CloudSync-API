<?php
return [
    // Use bcrypt hash for production: php artisan tinker -> echo Hash::make('your-password')
    // Set DASHBOARD_PASSWORD_HASH in .env (60-char bcrypt). Plain fallback only for dev.
    'password_hash' => env('DASHBOARD_PASSWORD_HASH'),
    'password_plain' => env('DASHBOARD_PASSWORD', 'secret'),
];
