<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        $filters = $request->validate([
            'location_id' => ['sometimes', 'ulid'],
            'active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $services = Service::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->when(isset($filters['location_id']), fn ($query) => $query->whereHas(
                'locations', fn ($locations) => $locations->whereKey($filters['location_id'])->where('locations.is_active', true),
            ))
            ->when(isset($filters['search']), fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => $services->getCollection()->map(fn (Service $service) => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'slug' => $service->slug,
                'duration_minutes' => $service->duration_minutes,
                'max_advance_days' => $service->max_advance_days,
                'price' => $service->price_minor === null ? null : [
                    'minor' => $service->price_minor,
                    'currency' => $service->currency ?? $business->default_currency,
                ],
            ])->values(),
            'meta' => [
                'current_page' => $services->currentPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
            ],
        ]);
    }
}