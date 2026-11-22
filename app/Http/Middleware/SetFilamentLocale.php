<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetFilamentLocale
{
    public const SUPPORTED_LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $userLocale = $request->user()?->preferred_locale;
        $sessionLocale = $request->session()->get('filament_locale');
        $locale = in_array($userLocale, self::SUPPORTED_LOCALES, true)
            ? $userLocale
            : (in_array($sessionLocale, self::SUPPORTED_LOCALES, true) ? $sessionLocale : 'ar');
        $previousLocale = App::getLocale();
        $previousCarbonLocale = Carbon::getLocale();

        App::setLocale($locale);
        Carbon::setLocale($locale);

        try {
            return $next($request);
        } finally {
            App::setLocale($previousLocale);
            Carbon::setLocale($previousCarbonLocale);
        }
    }
}
