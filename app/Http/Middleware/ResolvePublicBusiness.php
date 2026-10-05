<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Business-Slug');

        if (! is_string($slug) || $slug === '') {
            return response()->json([
                'message' => 'The X-Business-Slug header is required.',
                'code' => 'VALIDATION_ERROR',
                'errors' => ['X-Business-Slug' => ['The X-Business-Slug header is required.']],
            ], 422);
        }

        $business = Business::query()->where('slug', $slug)->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found.', 'code' => 'NOT_FOUND'], 404);
        }

        if (! $business->is_active) {
            return response()->json(['message' => 'Business is inactive.', 'code' => 'BUSINESS_INACTIVE'], 403);
        }

        $request->attributes->set('business', $business);

        return $next($request);
    }
}