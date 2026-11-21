<?php

namespace Tests\Unit;

use App\Domain\Authorization\BookingDisplayMembershipContext;
use App\Domain\Authorization\BusinessAccess;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\ExpectationInterface;
use RuntimeException;
use Tests\TestCase;

class BookingDisplayMembershipContextTest extends TestCase
{
    public function test_membership_lookup_is_keyed_by_guard_user_and_business_for_the_current_request(): void
    {
        $request = Request::create('/bookings');
        $this->app->instance('request', $request);
        $actor = (new User)->forceFill(['id' => 1]);
        $otherActor = (new User)->forceFill(['id' => 2]);
        $business = (new Business)->forceFill(['id' => 'tenant-a']);
        $otherBusiness = (new Business)->forceFill(['id' => 'tenant-b']);

        $this->expectMembership($actor, $business)->andReturn((object) ['role' => 'manager', 'access_scope' => 'selected']);
        $this->expectMembership($otherActor, $business)->andReturn(null);
        $this->expectMembership($actor, $otherBusiness)->andReturn(null);
        $this->expectMembership($actor, $business)->andReturn((object) ['role' => 'owner', 'access_scope' => 'all']);
        $this->expectMembership($actor, $business)->andReturn(null);

        BookingDisplayMembershipContext::evaluate($request, 'web', $actor, $business, function () use ($actor, $otherActor, $business, $otherBusiness): bool {
            $access = app(BusinessAccess::class);
            $this->assertSame(BusinessRole::Manager, $access->role($actor, $business));
            $this->assertSame(BranchAccessScope::Selected, $access->scope($actor, $business));
            $this->assertSame(BusinessRole::Manager, $access->role($actor, $business));
            $this->assertNull(BookingDisplayMembershipContext::membership($otherActor, $business));
            $this->assertNull(BookingDisplayMembershipContext::membership($actor, $otherBusiness));

            return true;
        });

        BookingDisplayMembershipContext::evaluate($request, 'web', $otherActor, $business, function () use ($otherActor, $business): bool {
            $this->assertSame(['exists' => false, 'role' => null, 'scope' => null], BookingDisplayMembershipContext::membership($otherActor, $business));
            $this->assertFalse(BookingDisplayMembershipContext::membership($otherActor, $business)['exists']);

            return false;
        });

        BookingDisplayMembershipContext::evaluate($request, 'web', $actor, $otherBusiness, function () use ($actor, $otherBusiness): bool {
            $this->assertFalse(BookingDisplayMembershipContext::membership($actor, $otherBusiness)['exists']);

            return false;
        });

        config(['auth.defaults.guard' => 'another-guard']);
        BookingDisplayMembershipContext::evaluate($request, 'another-guard', $actor, $business, function () use ($actor, $business): bool {
            $this->assertSame(BusinessRole::Owner, app(BusinessAccess::class)->role($actor, $business));

            return true;
        });
        config(['auth.defaults.guard' => 'web']);

        $nextRequest = Request::create('/bookings');
        $this->app->instance('request', $nextRequest);
        BookingDisplayMembershipContext::evaluate($nextRequest, 'web', $actor, $business, function () use ($actor, $business): bool {
            $this->assertFalse(BookingDisplayMembershipContext::membership($actor, $business)['exists']);

            return false;
        });
    }

    public function test_display_snapshot_is_deactivated_after_an_exception_and_fresh_checks_remain_fresh(): void
    {
        $request = Request::create('/bookings');
        $this->app->instance('request', $request);
        $actor = (new User)->forceFill(['id' => 1]);
        $business = (new Business)->forceFill(['id' => 'tenant-a']);

        $this->expectMembership($actor, $business)->andReturn((object) ['role' => 'manager', 'access_scope' => 'all']);
        $query = Mockery::mock();
        $query->shouldReceive('where')->with('business_id', 'tenant-a')->once()->andReturnSelf();
        $query->shouldReceive('where')->with('user_id', $actor->getKey())->once()->andReturnSelf();
        $query->shouldReceive('value')->with('role')->once()->andReturn('staff');
        DB::shouldReceive('table')->with('business_user')->once()->andReturn($query);

        $access = app(BusinessAccess::class);
        $exception = new RuntimeException('Policy stopped');
        try {
            BookingDisplayMembershipContext::evaluate($request, 'web', $actor, $business, function () use ($access, $actor, $business, $exception): never {
                $this->assertTrue($access->canManageBookings($actor, $business));

                throw $exception;
            });
            $this->fail('The policy exception should propagate.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertNull(BookingDisplayMembershipContext::membership($actor, $business));
        $this->assertFalse($access->canManageBookings($actor, $business));
    }

    private function expectMembership(User $user, Business $business): ExpectationInterface
    {
        $query = Mockery::mock();
        $query->shouldReceive('where')->with('business_id', $business->getKey())->once()->andReturnSelf();
        $query->shouldReceive('where')->with('user_id', $user->getKey())->once()->andReturnSelf();
        DB::shouldReceive('table')->with('business_user')->once()->andReturn($query);

        return $query->shouldReceive('first')->with(['role', 'access_scope'])->once();
    }
}
