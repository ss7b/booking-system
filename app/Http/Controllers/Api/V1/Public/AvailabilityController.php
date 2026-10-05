<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Availability\Services\AvailabilityService;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __invoke(Request $request, AvailabilityService $availability): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        $filters = $request->validate([
            'service_id' => ['required', 'ulid'],
            'location_id' => ['required', 'ulid'],
            'staff_id' => ['sometimes', 'ulid'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $location = Location::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->findOrFail($filters['location_id']);

        $service = Service::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('locations.is_active', true))
            ->findOrFail($filters['service_id']);

        $staff = null;

        if (isset($filters['staff_id'])) {
            $staff = Staff::query()
                ->where('business_id', $business->getKey())
                ->where('status', 'active')
                ->whereHas('services', fn ($query) => $query->whereKey($service->getKey()))
                ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('staff_locations.is_active', true))
                ->findOrFail($filters['staff_id']);
        }

        return response()->json([
            'data' => $availability->forDate($business, $location, $service, $filters['date'], $staff),
        ]);
    }
}