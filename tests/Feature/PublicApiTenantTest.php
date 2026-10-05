<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicApiTenantTest extends TestCase
{
    public function test_public_endpoints_require_a_business_slug(): void
    {
        $this->getJson('/api/v1/services')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.X-Business-Slug.0', 'The X-Business-Slug header is required.');
    }

    public function test_admin_booking_routes_require_sanctum_authentication(): void
    {
        $this->getJson('/api/v1/admin/bookings')
            ->assertUnauthorized();
    }
}