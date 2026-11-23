<?php

namespace App\Http\Controllers\Filament;

use App\Domain\Reports\BookingReports;
use App\Domain\Reports\ReportExcelExporter;
use App\Domain\Reports\ReportPdfExporter;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportRange;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        [$user, $business, $location, $range, $search] = $this->exportContext($request, $tenant, $section, $reports);

        return $exporter->download($user, $business, $location, $section, $range, $search);
    }

    public function pdf(
        Request $request,
        string $tenant,
        string $section,
        BookingReports $reports,
        ReportPdfExporter $exporter,
    ): Response {
        [$user, $business, $location, $range, $search] = $this->exportContext($request, $tenant, $section, $reports);
        $timezone = $location->timezone ?: ($business->timezone ?: config('app.timezone'));
        $filename = 'reports-'.$section.'-'.now($timezone)->format('Ymd-His').'.pdf';
        $pdf = $exporter->render($user, $business, $location, $section, $range, $search);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Language' => app()->getLocale(),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{User, Business, Location, ReportRange, ?string} */
    private function exportContext(Request $request, string $tenant, string $section, BookingReports $reports): array
    {
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
        abort_unless($location instanceof Location, 404);

        try {
            $range = $filters['period'] === 'custom'
                ? ReportRange::custom($filters['start_date'], $filters['end_date'])
                : ReportRange::preset(ReportPeriod::from($filters['period']));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'end_date' => __('reports.filters.invalid_end'),
            ]);
        }

        return [
            $user,
            $business,
            $location,
            $range,
            $section === 'bookings' ? ($filters['search'] ?? null) : null,
        ];
    }
}
