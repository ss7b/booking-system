<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Service;
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

    public function bookingContext(Request $request, string $staff): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        $staffId = $staff;
        $eligibleServices = fn ($query) => $query
            ->where('services.business_id', $business->getKey())
            ->where('services.is_active', true)
            ->whereHas('staff', fn ($staffQuery) => $staffQuery->whereKey($staffId));

        $staff = Staff::query()
            ->where('business_id', $business->getKey())
            ->where('status', 'active')
            ->with(['locations' => function ($query) use ($business, $eligibleServices): void {
                $query
                    ->select(['locations.id', 'locations.name', 'locations.timezone'])
                    ->where('locations.business_id', $business->getKey())
                    ->where('locations.is_active', true)
                    ->wherePivot('is_active', true)
                    ->whereHas('services', $eligibleServices)
                    ->with(['services' => function ($services) use ($eligibleServices): void {
                        $eligibleServices($services->select(['services.id', 'services.name', 'services.duration_minutes']));
                    }])
                    ->orderBy('locations.name');
            }])
            ->findOrFail($staffId);

        $locations = $staff->locations->map(fn ($location) => [
            'id' => $location->getKey(),
            'name' => $location->name,
            'timezone' => $location->timezone,
            'services' => $location->services->map(fn (Service $service) => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
            ])->values(),
        ])->values();

        return response()->json([
            'data' => [
                'staff' => ['id' => $staff->getKey(), 'name' => $staff->name],
                'booking_eligible' => $locations->isNotEmpty(),
                'locations' => $locations,
            ],
        ]);
    }
}