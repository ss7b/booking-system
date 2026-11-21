<?php

namespace App\Domain\Authorization;

use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\CalendarRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class BusinessMembershipRequestContext
{
    private ?array $identity = null;

    /** @var array{exists: bool, role: ?BusinessRole, scope: ?BranchAccessScope}|null */
    private ?array $membership = null;

    private ?array $allowedLocationIds = null;

    private bool $allowedLocationIdsLoaded = false;

    private ?string $linkedStaffId = null;

    private bool $linkedStaffIdLoaded = false;

    private function __construct() {}

    public static function beginForModel(string $model, User $user, Business $business): void
    {
        $resource = match ($model) {
            CalendarRule::class => 'calendar_rules',
            Customer::class => 'customers',
            Service::class => 'services',
            Staff::class => 'staff',
            default => null,
        };

        if ($resource !== null) {
            self::beginForResource($resource, $user, $business);
        }
    }

    public static function beginForResource(string $resource, User $user, Business $business): void
    {
        if (! self::isEnabled($resource)) {
            return;
        }

        self::begin($user, $business);
    }

    public static function beginForBooking(User $user, Business $business): void
    {
        if (! config('booking.unified_authorization_display_context', false)) {
            return;
        }

        self::begin($user, $business);
    }

    private static function begin(User $user, Business $business): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $request = request();
        $guard = Filament::getAuthGuard();
        $authenticatedUser = Auth::guard($guard)->user();

        if (! $authenticatedUser instanceof User || (string) $authenticatedUser->getKey() !== (string) $user->getKey()) {
            return;
        }

        $context = $request->attributes->get(self::class);
        if (! $context instanceof self) {
            $context = new self;
            $request->attributes->set(self::class, $context);
        }

        $identity = [$guard, (string) $user->getKey(), (string) $business->getKey()];
        if ($context->identity !== $identity) {
            $context->identity = $identity;
            $context->membership = null;
            $context->allowedLocationIds = null;
            $context->allowedLocationIdsLoaded = false;
            $context->linkedStaffId = null;
            $context->linkedStaffIdLoaded = false;
        }
    }

    /**
     * @return array{exists: bool, role: ?BusinessRole, scope: ?BranchAccessScope}|null
     */
    public static function membership(User $user, Business $business): ?array
    {
        $context = self::current($user, $business);
        if ($context === null) {
            return null;
        }

        return $context->membership ??= $context->loadMembership($user, $business);
    }

    /**
     * @return list<string>|null Null means no active resource context.
     */
    public static function allowedLocationIds(User $user, Business $business): ?array
    {
        $context = self::current($user, $business);
        if ($context === null) {
            return null;
        }

        if (! $context->allowedLocationIdsLoaded) {
            $context->allowedLocationIds = DB::table('business_user_locations')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->pluck('location_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
            $context->allowedLocationIdsLoaded = true;
        }

        return $context->allowedLocationIds;
    }

    public static function linkedStaffId(User $user, Business $business): ?string
    {
        $context = self::current($user, $business);
        if ($context === null) {
            return null;
        }

        if (! $context->linkedStaffIdLoaded) {
            $id = Staff::query()
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->value('id');
            $context->linkedStaffId = $id === null ? null : (string) $id;
            $context->linkedStaffIdLoaded = true;
        }

        return $context->linkedStaffId;
    }

    public static function isActiveFor(User $user, Business $business): bool
    {
        return self::current($user, $business) !== null;
    }

    private static function isEnabled(string $resource): bool
    {
        return (bool) config('booking.resource_authorization_display_context.enabled', false)
            && ! (bool) config("booking.resource_authorization_display_context.disabled_resources.$resource", false);
    }

    private static function current(User $user, Business $business): ?self
    {
        if (! app()->bound('request')) {
            return null;
        }

        $context = request()->attributes->get(self::class);
        if (! $context instanceof self || $context->identity === null) {
            return null;
        }

        [$guard, $userId, $businessId] = $context->identity;
        $authenticatedUser = Auth::guard($guard)->user();

        if (
            Filament::getAuthGuard() !== $guard
            || $userId !== (string) $user->getKey()
            || $businessId !== (string) $business->getKey()
            || ! $authenticatedUser instanceof User
            || (string) $authenticatedUser->getKey() !== $userId
        ) {
            return null;
        }

        return $context;
    }

    /**
     * @return array{exists: bool, role: ?BusinessRole, scope: ?BranchAccessScope}
     */
    private function loadMembership(User $user, Business $business): array
    {
        $membership = DB::table('business_user')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->first(['role', 'access_scope']);

        return [
            'exists' => $membership !== null,
            'role' => is_string($membership?->role) ? BusinessRole::tryFrom($membership->role) : null,
            'scope' => is_string($membership?->access_scope) ? BranchAccessScope::tryFrom($membership->access_scope) : null,
        ];
    }
}
