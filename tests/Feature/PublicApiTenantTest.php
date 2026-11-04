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

    public function test_public_api_supports_cross_origin_booking_requests(): void
    {
        $this->withHeaders([
            'Origin' => 'https://wordpress.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,idempotency-key,x-business-slug,x-booking-access-token',
        ])->options('/api/v1/bookings')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderContains('Access-Control-Allow-Methods', 'POST')
            ->assertHeaderContains('Access-Control-Allow-Headers', 'idempotency-key')
            ->assertHeaderContains('Access-Control-Allow-Headers', 'x-business-slug')
            ->assertHeaderContains('Access-Control-Allow-Headers', 'x-booking-access-token')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }
}