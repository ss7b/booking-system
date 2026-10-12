<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Availability\Services\AvailabilityService;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function calendar(Request $request, AvailabilityService $availability): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        $filters = $request->validate([
            'service_id' => ['required', 'ulid'],
            'location_id' => ['required', 'ulid'],
            'staff_id' => ['sometimes', 'ulid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d'],
        ]);

        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['from']);
        $until = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['until']);

        if (! $from || ! $until || $until < $from || $from->diff($until)->days > 30) {
            throw ValidationException::withMessages([
                'until' => ['The date range must be between 1 and 31 days.'],
            ]);
        }

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

        $dates = [];

        for ($date = $from; $date <= $until; $date = $date->add(new DateInterval('P1D'))) {
            $dateString = $date->format('Y-m-d');
            $result = $availability->forDate($business, $location, $service, $dateString, $staff);

            $dates[] = ['date' => $dateString, 'available' => $result['slots'] !== []];
        }

        return response()->json(['data' => $dates]);
    }
}