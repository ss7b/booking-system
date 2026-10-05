<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $business = $request->attributes->get('business');

        abort_unless(
            $business instanceof Business
                && $request->user()
                && $business->users()->whereKey($request->user()->getKey())->exists(),
            403,
            'You are not a member of this business.',
        );

        return $next($request);
    }
}