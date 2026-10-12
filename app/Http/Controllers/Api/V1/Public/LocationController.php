<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        $locations = Location::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'timezone']);

        return response()->json(['data' => $locations]);
    }
}