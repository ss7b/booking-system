<?php

namespace App\Http\Controllers\Filament;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetFilamentLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SetLocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->input('locale');
        abort_unless(is_string($locale) && in_array($locale, SetFilamentLocale::SUPPORTED_LOCALES, true), 422);

        $request->session()->put('filament_locale', $locale);

        if ($user = $request->user()) {
            $user->forceFill(['preferred_locale' => $locale])->save();
        }

        return redirect()->back();
    }
}
