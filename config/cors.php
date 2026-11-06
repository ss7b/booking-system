<?php

return [
    'paths' => [
        'api/v1/services',
        'api/v1/locations',
        'api/v1/staff',
        'api/v1/availability',
        'api/v1/availability/calendar',
        'api/v1/booking-holds',
        'api/v1/bookings',
        'api/v1/bookings/*',
    ],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-Business-Slug',
        'X-Booking-Access-Token',
        'X-Booking-Management-Token',
        'Idempotency-Key',
    ],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
