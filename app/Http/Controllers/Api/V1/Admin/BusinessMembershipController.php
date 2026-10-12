<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Authorization\ManageBusinessMemberships;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessMembershipController extends Controller
{
    public function index(Request $request, BusinessAccess $access): JsonResponse
    {
        $business = $this->business($request);
        abort_unless($access->canManageMemberships($request->user(), $business), 403);

        $members = $business->users()->with('assignedLocations')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role->value,
                'access_scope' => $user->pivot->access_scope->value,
                'location_ids' => $user->pivot->access_scope === BranchAccessScope::All
                    ? []
                    : $user->assignedLocations()->wherePivot('business_id', $business->getKey())->pluck('locations.id')->all(),
            ])->values();

        return response()->json(['data' => $members]);
    }

    public function store(Request $request, BusinessAccess $access, ManageBusinessMemberships $memberships): JsonResponse
    {
        $business = $this->business($request);
        abort_unless($access->canManageMemberships($request->user(), $business), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(BusinessRole::class)],
            'access_scope' => ['required', Rule::enum(BranchAccessScope::class)],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['required', 'ulid', 'distinct', Rule::exists('locations', 'id')->where('business_id', $business->getKey())],
        ]);

        $memberships->save(
            $business,
            User::query()->findOrFail($data['user_id']),
            BusinessRole::from($data['role']),
            BranchAccessScope::from($data['access_scope']),
            $data['location_ids'] ?? [],
        );

        return response()->json(['data' => ['status' => 'saved']], 201);
    }

    public function destroy(Request $request, string $user, BusinessAccess $access, ManageBusinessMemberships $memberships): JsonResponse
    {
        $business = $this->business($request);
        abort_unless($access->canManageMemberships($request->user(), $business), 403);
        $memberships->remove($business, User::query()->findOrFail($user));

        return response()->json(['data' => ['status' => 'removed']]);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        return $business;
    }
}
