<?php

return [
    'authorization_display_context' => env('BOOKING_AUTHORIZATION_DISPLAY_CONTEXT', false),
    'unified_authorization_display_context' => env('BOOKING_UNIFIED_AUTHORIZATION_DISPLAY_CONTEXT', false),
    'resource_authorization_display_context' => [
        'enabled' => env('BOOKING_RESOURCE_AUTHORIZATION_DISPLAY_CONTEXT', false),
        'disabled_resources' => [
            'calendar_rules' => env('BOOKING_RESOURCE_AUTHORIZATION_DISPLAY_CONTEXT_DISABLE_CALENDAR_RULES', false),
            'customers' => env('BOOKING_RESOURCE_AUTHORIZATION_DISPLAY_CONTEXT_DISABLE_CUSTOMERS', false),
            'services' => env('BOOKING_RESOURCE_AUTHORIZATION_DISPLAY_CONTEXT_DISABLE_SERVICES', false),
            'staff' => env('BOOKING_RESOURCE_AUTHORIZATION_DISPLAY_CONTEXT_DISABLE_STAFF', false),
        ],
    ],
];
