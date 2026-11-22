<?php

namespace App\Http\Controllers\Filament;

use App\Domain\Reports\BookingReports;
use App\Domain\Reports\ReportExcelExporter;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportRange;
use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportReportController
{
    public function __invoke(
        Request $request,
        string $tenant,
        string $section,
        BookingReports $reports,
        ReportExcelExporter $exporter,
    ): StreamedResponse {
        abort_unless(in_array($section, ['bookings', 'services', 'staff'], true), 404);

        $business = Filament::getTenant();
        $user = Filament::auth()->user();
        abort_unless(
            $business instanceof Business
                && (string) $business->getRouteKey() === $tenant
                && $user instanceof User,
            403,
        );
        Gate::forUser($user)->authorize(BookingReports::VIEW, $business);

        $filters = Validator::make($request->all(), [
            'branch_id' => ['bail', 'required', 'string', 'max:26'],
            'period' => ['bail', 'required', 'string', Rule::in([...array_column(ReportPeriod::cases(), 'value'), 'custom'])],
            'start_date' => ['exclude_unless:period,custom', 'bail', 'required', 'date_format:Y-m-d'],
            'end_date' => ['exclude_unless:period,custom', 'bail', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:150'],
        ], [
            'branch_id.*' => __('reports.filters.invalid_branch'),
            'period.*' => __('reports.filters.invalid_period'),
            'start_date.*' => __('reports.filters.invalid_start'),
            'end_date.*' => __('reports.filters.invalid_end'),
            'search.max' => __('reports.bookings.invalid_search'),
        ])->validate();

        $location = $reports->locations($user, $business)
            ->whereKey($filters['branch_id'])
            ->first(['locations.id', 'locations.name', 'locations.timezone']);
        abort_unless($location !== null, 404);

        try {
            $range = $filters['period'] === 'custom'
                ? ReportRange::custom($filters['start_date'], $filters['end_date'])
                : ReportRange::preset(ReportPeriod::from($filters['period']));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'end_date' => __('reports.filters.invalid_end'),
            ]);
        }

        return $exporter->download(
            $user,
            $business,
            $location,
            $section,
            $range,
            $section === 'bookings' ? ($filters['search'] ?? null) : null,
        );
    }
}
