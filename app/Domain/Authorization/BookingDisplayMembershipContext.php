<?php

namespace App\Domain\Authorization;

use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BookingDisplayMembershipContext
{
    /** @var array{string, string, string}|null */
    private ?array $activeIdentity = null;

    /** @var array<string, array{exists: bool, role: ?BusinessRole, scope: ?BranchAccessScope}> */
    private array $memberships = [];

    private function __construct() {}

    public static function evaluate(Request $request, string $guard, User $user, Business $business, Closure $decision): bool
    {
        $context = $request->attributes->get(self::class);
        if (! $context instanceof self) {
            $context = new self;
            $request->attributes->set(self::class, $context);
        }

        $previousIdentity = $context->activeIdentity;
        $context->activeIdentity = [$guard, (string) $user->getKey(), (string) $business->getKey()];

        try {
            return $decision();
        } finally {
            $context->activeIdentity = $previousIdentity;
        }
    }

    /** @return array{exists: bool, role: ?BusinessRole, scope: ?BranchAccessScope}|null */
    public static function membership(User $user, Business $business): ?array
    {
        if (! app()->bound('request')) {
            return null;
        }

        $context = request()->attributes->get(self::class);
        if (! $context instanceof self || $context->activeIdentity === null) {
            return null;
        }

        $identity = [auth()->getDefaultDriver(), (string) $user->getKey(), (string) $business->getKey()];
        if ($context->activeIdentity !== $identity) {
            return null;
        }

        $key = json_encode($identity, JSON_THROW_ON_ERROR);

        return $context->memberships[$key] ??= self::loadMembership($user, $business);
    }

    /** @return array{exists: bool, role: ?BusinessRole, scope: ?BranchAccessScope} */
    private static function loadMembership(User $user, Business $business): array
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
