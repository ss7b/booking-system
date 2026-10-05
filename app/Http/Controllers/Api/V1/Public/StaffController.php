<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        $filters = $request->validate([
            'service_id' => ['required', 'ulid'],
            'location_id' => ['required', 'ulid'],
        ]);

        $staff = Staff::query()
            ->where('business_id', $business->getKey())
            ->where('status', 'active')
            ->whereHas('services', fn ($query) => $query
                ->whereKey($filters['service_id'])
                ->where('services.business_id', $business->getKey())
                ->where('services.is_active', true))
            ->whereHas('locations', fn ($query) => $query
                ->whereKey($filters['location_id'])
                ->where('locations.business_id', $business->getKey())
                ->where('locations.is_active', true)
                ->where('staff_locations.is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        return response()->json(['data' => $staff]);
    }
}