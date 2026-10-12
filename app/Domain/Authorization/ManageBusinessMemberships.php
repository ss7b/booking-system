<?php

namespace App\Domain\Authorization;

use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageBusinessMemberships
{
    public function save(
        Business $business,
        User $user,
        BusinessRole $role,
        BranchAccessScope $scope,
        array $locationIds,
    ): void {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['user_id' => 'Inactive user accounts cannot receive business memberships.']);
        }

        $locationIds = array_values(array_unique(array_map('strval', $locationIds)));
        $validLocationCount = $business->locations()->whereIn('id', $locationIds)->count();

        if ($validLocationCount !== count($locationIds)) {
            throw ValidationException::withMessages([
                'location_ids' => 'Every assigned branch must belong to this business.',
            ]);
        }

        if ($role === BusinessRole::Owner) {
            $scope = BranchAccessScope::All;
            $locationIds = [];
        }

        DB::transaction(function () use ($business, $user, $role, $scope, $locationIds): void {
            $business = $business->newQuery()->lockForUpdate()->findOrFail($business->getKey());
            $membership = DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership && $membership->role === BusinessRole::Owner->value && $role !== BusinessRole::Owner) {
                $ownerCount = DB::table('business_user')
                    ->where('business_id', $business->getKey())
                    ->where('role', BusinessRole::Owner->value)
                    ->count();

                if ($ownerCount <= 1) {
                    throw ValidationException::withMessages(['role' => 'A business must retain at least one owner.']);
                }
            }

            DB::table('business_user')->updateOrInsert(
                ['business_id' => $business->getKey(), 'user_id' => $user->getKey()],
                [
                    'role' => $role->value,
                    'access_scope' => $scope->value,
                    'created_at' => $membership?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('business_user_locations')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->delete();

            if ($scope === BranchAccessScope::Selected && $locationIds !== []) {
                $timestamp = now();
                DB::table('business_user_locations')->insert(array_map(
                    fn (string $locationId): array => [
                        'business_id' => $business->getKey(),
                        'user_id' => $user->getKey(),
                        'location_id' => $locationId,
                        'created_at' => $timestamp,
                    ],
                    $locationIds,
                ));
            }
        });
    }

    public function remove(Business $business, User $user): void
    {
        DB::transaction(function () use ($business, $user): void {
            $business = $business->newQuery()->lockForUpdate()->findOrFail($business->getKey());
            $membership = DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $membership) {
                return;
            }

            if ($membership->role === BusinessRole::Owner->value) {
                $ownerCount = DB::table('business_user')
                    ->where('business_id', $business->getKey())
                    ->where('role', BusinessRole::Owner->value)
                    ->count();

                if ($ownerCount <= 1) {
                    throw ValidationException::withMessages(['user_id' => 'A business must retain at least one owner.']);
                }
            }

            DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->delete();
        });
    }
}
