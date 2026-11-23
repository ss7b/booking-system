<?php

namespace App\Domain\Reports;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;

final class BookingReports
{
    public const VIEW = 'reports.view';

    public const PDF_MAX_ROWS = 200;

    public function __construct(private readonly BusinessAccess $access) {}

    /** @return Builder<Location> */
    public function locations(User $user, Business $business): Builder
    {
        Gate::forUser($user)->authorize(self::VIEW, $business);

        return $this->access->locations($user, $business);
    }

    public function currentMonth(User $user, Business $business, string $locationId): array
    {
        return $this->forPeriod($user, $business, $locationId, ReportPeriod::CurrentMonth);
    }

    /**
     * @return array{
     *     start: CarbonImmutable,
     *     end: CarbonImmutable,
     *     statistics: array{total: int, confirmed: int, completed: int, cancelled: int, pending: int, cancellation_rate: float},
     *     trend: array<string, int>,
     *     statuses: array<string, int>,
     *     services: list<array{id: ?string, name: ?string, count: int}>,
     *     staff: list<array{id: ?string, name: ?string, count: int}>,
     *     upcoming: Collection<int, Booking>,
     * }
     */
    public function forPeriod(User $user, Business $business, string $locationId, ReportPeriod $period): array
    {
        return $this->forRange($user, $business, $locationId, ReportRange::preset($period));
    }

    /**
     * @return array{
     *     start: CarbonImmutable,
     *     end: CarbonImmutable,
     *     statistics: array{total: int, confirmed: int, completed: int, cancelled: int, pending: int, cancellation_rate: float},
     *     trend: array<string, int>,
     *     statuses: array<string, int>,
     *     services: list<array{id: ?string, name: ?string, count: int}>,
     *     staff: list<array{id: ?string, name: ?string, count: int}>,
     *     upcoming: Collection<int, Booking>,
     * }
     */
    public function forRange(User $user, Business $business, string $locationId, ReportRange $range): array
    {
        [$bookings, $location, $now, $start, $end] = $this->scopedReport($user, $business, $locationId, $range);
        $confirmedCohort = (clone $bookings)->where($this->whereConfirmedCohort(...));

        return [
            ...$this->aggregateOverview($bookings, $start, $end, $now),
            'services' => $this->countsByRelation($confirmedCohort, 'services', 'service_id', 5),
            'staff' => $this->countsByRelation($confirmedCohort, 'staff', 'staff_id'),
            'upcoming' => $this->upcoming($bookings, $now, $location),
        ];
    }

    public function overview(User $user, Business $business, string $locationId, ReportRange $range): array
    {
        [$bookings, , $now, $start, $end] = $this->scopedReport($user, $business, $locationId, $range);

        return $this->aggregateOverview($bookings, $start, $end, $now);
    }

    public function comparePeriods(
        User $user,
        Business $business,
        string $locationId,
        string $period,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $locations = $this->locations($user, $business)->whereKey($locationId);
        $location = (clone $locations)->first(['locations.id', 'locations.timezone']);
        if ($location === null) {
            throw new AuthorizationException;
        }

        $timezone = $location->timezone ?: ($business->timezone ?: config('app.timezone'));
        $now = now($timezone)->toImmutable();
        $window = PeriodComparisonWindow::fromFilters($period, $startDate, $endDate, $now);
        $currentBookings = $this->bookingsWithin($business, $locations, $window->currentStart, $window->currentEnd);
        $previousBookings = $this->bookingsWithin($business, $locations, $window->previousStart, $window->previousEnd);
        $currentOverview = $this->aggregateOverview(
            $currentBookings,
            $window->currentStart,
            $window->currentEnd,
            $now,
            includeTrend: $window->chartable,
        );
        $previousOverview = $this->aggregateOverview(
            $previousBookings,
            $window->previousStart,
            $window->previousEnd,
            $now,
            includeTrend: $window->chartable,
        );
        $currentServices = $this->topServiceRows($currentBookings, $now);
        $previousServices = $this->topServiceRows($previousBookings, $now);

        return [
            'window' => $window,
            'current' => $currentOverview,
            'previous' => $previousOverview,
            'metrics' => $this->compareStatistics(
                $currentOverview['statistics'],
                $previousOverview['statistics'],
            ),
            'active_held' => [
                'current' => $currentOverview['statistics']['pending'],
                'previous' => $previousOverview['statistics']['pending'],
            ],
            'trend' => $window->chartable
                ? $this->alignedTrend($currentOverview['trend'], $previousOverview['trend'], $window)
                : null,
            'services' => $this->mergeServiceRows($currentServices, $previousServices),
        ];
    }

    public function services(
        User $user,
        Business $business,
        string $locationId,
        ReportRange $range,
        string $sort = 'total',
        string $direction = 'desc',
        int $perPage = 15,
        int $page = 1,
        ?string $search = null,
    ): LengthAwarePaginator {
        [$bookings, , $now] = $this->scopedReport($user, $business, $locationId, $range);

        return $this->paginatedBreakdown(
            $this->reportableBookings($bookings, $now), 'services', 'service_id', $sort, $direction, $perPage, $page, $search,
        );
    }

    public function staff(
        User $user,
        Business $business,
        string $locationId,
        ReportRange $range,
        string $sort = 'total',
        string $direction = 'desc',
        int $perPage = 15,
        int $page = 1,
        ?string $search = null,
    ): LengthAwarePaginator {
        [$bookings, , $now] = $this->scopedReport($user, $business, $locationId, $range);

        return $this->paginatedBreakdown(
            $this->reportableBookings($bookings, $now), 'staff', 'staff_id', $sort, $direction, $perPage, $page, $search,
        );
    }

    /** @return LengthAwarePaginator<int, Booking> */
    public function bookings(
        User $user,
        Business $business,
        string $locationId,
        ReportRange $range,
        ?string $search = null,
        string $sort = 'starts_at',
        string $direction = 'desc',
        int $perPage = 15,
        int $page = 1,
        ?BookingStatus $status = null,
    ): LengthAwarePaginator {
        [$bookings, $location, $now] = $this->scopedReport($user, $business, $locationId, $range);

        return $this->paginatedBookings($bookings, $location, $now, $search, $sort, $direction, $perPage, $page, $status);
    }

    /** @return array{overview: array, bookings: LengthAwarePaginator<int, Booking>} */
    public function bookingReport(
        User $user,
        Business $business,
        string $locationId,
        ReportRange $range,
        ?string $search = null,
        string $sort = 'starts_at',
        string $direction = 'desc',
        int $perPage = 15,
        int $page = 1,
        ?BookingStatus $status = null,
    ): array {
        [$bookings, $location, $now, $start, $end] = $this->scopedReport($user, $business, $locationId, $range);

        return [
            'overview' => $this->aggregateOverview($bookings, $start, $end, $now),
            'bookings' => $this->paginatedBookings($bookings, $location, $now, $search, $sort, $direction, $perPage, $page, $status),
        ];
    }

    /** @return LazyCollection<int, Booking> */
    public function bookingRowsForExport(
        User $user,
        Business $business,
        string $locationId,
        ReportRange $range,
        ?string $search = null,
    ): LazyCollection {
        [$bookings, , $now] = $this->scopedReport($user, $business, $locationId, $range);

        return $this->bookingExportQuery($bookings, $now, $search)
            ->lazyById(500, 'bookings.id', 'id');
    }

    /**
     * @return LazyCollection<int, array{id: ?string, name: ?string, total: int, confirmed: int, checked_in: int, completed: int, cancelled: int, no_show: int, pending: int, cancellation_rate: float}>
     */
    public function breakdownRowsForExport(
        User $user,
        Business $business,
        string $locationId,
        ReportRange $range,
        string $dimension,
    ): LazyCollection {
        [$bookings, , $now] = $this->scopedReport($user, $business, $locationId, $range);

        return $this->breakdownExportQuery($bookings, $now, $dimension)
            ->lazy(500)
            ->map(fn (object $row): array => $this->formatBreakdownRow($row));
    }

    /**
     * @return array{
     *     location: Location,
     *     start: CarbonImmutable,
     *     end: CarbonImmutable,
     *     generated_at: CarbonImmutable,
     *     statistics: array{total: int, confirmed: int, completed: int, cancelled: int, pending: int, cancellation_rate: float},
     *     rows: list<Booking>|list<array{id: ?string, name: ?string, total: int, confirmed: int, checked_in: int, completed: int, cancelled: int, no_show: int, pending: int, cancellation_rate: float}>,
     *     detailsOmitted: bool,
     * }
     */
    public function forPdf(
        User $user,
        Business $business,
        string $locationId,
        ReportRange $range,
        string $section,
        ?string $search = null,
    ): array {
        [$bookings, $location, $now, $start, $end] = $this->scopedReport($user, $business, $locationId, $range);

        // Read one extra row to distinguish a complete table from a summary-only export.
        $rows = ($section === 'bookings'
            ? $this->bookingExportQuery(clone $bookings, $now, $search)
            : $this->breakdownExportQuery(clone $bookings, $now, $section))
            ->limit(self::PDF_MAX_ROWS + 1)
            ->get();
        $detailsOmitted = $rows->count() > self::PDF_MAX_ROWS;

        return [
            'location' => $location,
            'start' => $start,
            'end' => $end,
            'generated_at' => $now,
            'statistics' => $this->aggregateOverview($bookings, $start, $end, $now, includeTrend: false)['statistics'],
            'rows' => match (true) {
                $detailsOmitted => [],
                $section === 'bookings' => $rows->all(),
                default => $rows->map(fn (object $row): array => $this->formatBreakdownRow($row))->all(),
            },
            'detailsOmitted' => $detailsOmitted,
        ];
    }

    /** @param Builder<Booking> $bookings */
    private function bookingExportQuery(Builder $bookings, CarbonImmutable $now, ?string $search): Builder
    {
        return $this->bookingDetailsQuery($bookings, $now, $search, 'starts_at', 'asc')
            ->reorder('bookings.id');
    }

    /** @param Builder<Booking> $bookings */
    private function breakdownExportQuery(Builder $bookings, CarbonImmutable $now, string $dimension): QueryBuilder
    {
        [$table, $foreignKey] = match ($dimension) {
            'services' => ['services', 'service_id'],
            'staff' => ['staff', 'staff_id'],
            default => throw new \InvalidArgumentException('Unsupported report dimension.'),
        };

        return $this->breakdownQuery($this->reportableBookings($bookings, $now), $table, $foreignKey, 'total', 'desc');
    }

    /**
     * @param  Builder<Booking>  $bookings
     * @return LengthAwarePaginator<int, Booking>
     */
    private function paginatedBookings(
        Builder $bookings,
        Location $location,
        CarbonImmutable $now,
        ?string $search,
        string $sort,
        string $direction,
        int $perPage,
        int $page,
        ?BookingStatus $status,
    ): LengthAwarePaginator {
        return $this->bookingDetailsQuery($bookings, $now, $search, $sort, $direction)
            ->when($status !== null, fn (Builder $query) => $query->where('bookings.status', $status))
            ->paginate(min(100, max(1, $perPage)), page: min(intdiv(PHP_INT_MAX, 100), max(1, $page)))
            ->through(fn (Booking $booking): Booking => $booking
                ->setAttribute('location_timezone', $now->getTimezone()->getName())
                ->setRelation('location', $location));
    }

    /** @param Builder<Booking> $bookings */
    private function bookingDetailsQuery(
        Builder $bookings,
        CarbonImmutable $now,
        ?string $search,
        string $sort,
        string $direction,
    ): Builder {
        $search = trim($search ?? '');
        $sortColumn = match ($sort) {
            'reference' => 'bookings.reference',
            'service' => 'bookings.service_name_snapshot',
            'staff' => 'staff.name',
            'status' => 'bookings.status',
            'customer' => 'customers.name',
            default => 'bookings.starts_at',
        };

        return $this->reportableBookings($bookings, $now)
            ->leftJoin('staff', fn (JoinClause $join) => $join
                ->on('staff.id', '=', 'bookings.staff_id')
                ->on('staff.business_id', '=', 'bookings.business_id'))
            ->leftJoin('customers', fn (JoinClause $join) => $join
                ->on('customers.id', '=', 'bookings.customer_id')
                ->on('customers.business_id', '=', 'bookings.business_id'))
            ->select([
                'bookings.id', 'bookings.location_id', 'bookings.staff_id', 'bookings.reference', 'bookings.starts_at',
                'bookings.service_name_snapshot', 'bookings.status', 'staff.name as staff_name', 'customers.name as customer_name',
            ])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('bookings.reference', "%{$search}%")
                ->orWhereLike('bookings.service_name_snapshot', "%{$search}%")
                ->orWhereLike('staff.name', "%{$search}%")
                ->orWhereLike('customers.name', "%{$search}%")))
            ->orderBy($sortColumn, $direction === 'asc' ? 'asc' : 'desc')
            ->orderBy('bookings.id');
    }

    /** @return array{Builder<Booking>, Location, CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function scopedReport(User $user, Business $business, string $locationId, ReportRange $range): array
    {
        $locations = $this->locations($user, $business)->whereKey($locationId);
        $location = (clone $locations)->first(['locations.id', 'locations.name', 'locations.timezone']);
        if ($location === null) {
            throw new AuthorizationException;
        }

        $timezone = $location->timezone ?: ($business->timezone ?: config('app.timezone'));
        $now = now($timezone)->toImmutable();
        [$start, $end] = $range->bounds($now);

        $bookings = $this->bookingsWithin($business, $locations, $start, $end);

        return [$bookings, $location, $now, $start, $end];
    }

    /** @param Builder<Location> $locations
     * @return Builder<Booking>
     */
    private function bookingsWithin(
        Business $business,
        Builder $locations,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): Builder {
        return Booking::query()
            ->where('bookings.business_id', $business->getKey())
            ->whereIn('bookings.location_id', $locations->select('locations.id'))
            ->where('bookings.starts_at', '>=', $start->utc())
            ->where('bookings.starts_at', '<', $end->utc());
    }

    /** @param Builder<Booking> $bookings */
    private function whereConfirmedCohort(Builder $bookings): Builder
    {
        return $bookings
            ->whereIn('bookings.status', [
                BookingStatus::Confirmed,
                BookingStatus::CheckedIn,
                BookingStatus::Completed,
                BookingStatus::NoShow,
            ])
            // A held slot can be cancelled before it is confirmed.
            ->orWhere(fn (Builder $cancelled) => $cancelled
                ->where('bookings.status', BookingStatus::Cancelled)
                ->whereNotNull('bookings.confirmed_at'));
    }

    /** @param Builder<Booking> $bookings */
    private function reportableBookings(Builder $bookings, CarbonImmutable $now): Builder
    {
        return $bookings->where(fn (Builder $query) => $this->whereConfirmedCohort($query)
            ->orWhere(fn (Builder $pending) => $pending
                ->where('bookings.status', BookingStatus::Held)
                ->where('bookings.hold_expires_at', '>', $now->utc()->format('Y-m-d H:i:s.u'))));
    }

    /** @param Builder<Booking> $bookings */
    private function aggregateOverview(
        Builder $bookings,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $now,
        bool $includeTrend = true,
    ): array {
        $trend = [];

        // Keep quiet days visible for short ranges and keep long ranges sparse.
        if ($includeTrend && $end <= $start->addDays(366)->startOfDay()) {
            for ($day = $start; $day < $end; $day = $day->addDay()->startOfDay()) {
                $trend[$day->toDateString()] = 0;
            }
        }

        $query = $this->reportableBookings(clone $bookings, $now)
            ->toBase()
            ->select('bookings.status');
        if ($includeTrend) {
            [$bookingDaySql, $bindings] = $this->bookingDaySql($start, $end);
            $query->selectRaw($bookingDaySql.' AS booking_day', $bindings)
                ->groupBy('booking_day')
                ->orderBy('booking_day');
        }
        $query->selectRaw('COUNT(*) AS booking_count')->groupBy('bookings.status');

        $statuses = array_fill_keys(array_column(BookingStatus::cases(), 'value'), 0);
        unset($statuses[BookingStatus::Expired->value]);
        foreach ($query->get() as $row) {
            $count = (int) $row->booking_count;
            if ($includeTrend && $row->status !== BookingStatus::Held->value) {
                $trend[$row->booking_day] = ($trend[$row->booking_day] ?? 0) + $count;
            }
            $statuses[$row->status] += $count;
        }

        $pending = $statuses[BookingStatus::Held->value];
        $total = array_sum($statuses) - $pending;
        $cancelled = $statuses[BookingStatus::Cancelled->value];

        return [
            'start' => $start,
            'end' => $end,
            'statistics' => [
                'total' => $total,
                'confirmed' => $statuses[BookingStatus::Confirmed->value],
                'completed' => $statuses[BookingStatus::Completed->value],
                'cancelled' => $cancelled,
                'pending' => $pending,
                'cancellation_rate' => $total === 0 ? 0.0 : round(100 * $cancelled / $total, 2),
            ],
            'trend' => $trend,
            'statuses' => $statuses,
        ];
    }

    /** @return array{string, list<string|int>} */
    private function bookingDaySql(CarbonImmutable $start, CarbonImmutable $end): array
    {
        // UTC offset segments preserve local dates without MySQL timezone tables.
        $transitions = $start->getTimezone()->getTransitions($start->getTimestamp(), $end->getTimestamp());
        $offset = $start->getOffset();
        $cases = [];
        $bindings = [];
        foreach ($transitions ?: [] as $transition) {
            if ($transition['offset'] === $offset) {
                continue;
            }

            $cases[] = 'WHEN bookings.starts_at < ? THEN ?';
            $bindings[] = CarbonImmutable::createFromTimestampUTC($transition['ts'])->toDateTimeString();
            $bindings[] = $offset;
            $offset = $transition['offset'];
        }

        $offsetSql = $cases === [] ? '?' : 'CASE '.implode(' ', $cases).' ELSE ? END';
        $bindings[] = $offset;

        return ['DATE(TIMESTAMPADD(SECOND, '.$offsetSql.', bookings.starts_at))', $bindings];
    }

    /**
     * @param  Builder<Booking>  $bookings
     * @return LengthAwarePaginator<int, array{id: ?string, name: ?string, total: int, confirmed: int, checked_in: int, completed: int, cancelled: int, no_show: int, pending: int, cancellation_rate: float}>
     */
    private function paginatedBreakdown(
        Builder $bookings,
        string $table,
        string $foreignKey,
        string $sort,
        string $direction,
        int $perPage,
        int $page,
        ?string $search,
    ): LengthAwarePaginator {
        return $this->breakdownQuery($bookings, $table, $foreignKey, $sort, $direction, $search)
            ->paginate(min(100, max(1, $perPage)), page: min(intdiv(PHP_INT_MAX, 100), max(1, $page)))
            ->through(fn (object $row): array => $this->formatBreakdownRow($row));
    }

    /** @param Builder<Booking> $bookings */
    private function breakdownQuery(
        Builder $bookings,
        string $table,
        string $foreignKey,
        string $sort,
        string $direction,
        ?string $search = null,
    ): QueryBuilder {
        $id = "bookings.$foreignKey";
        $name = "$table.name";
        $search = trim($search ?? '');
        $statuses = [
            'confirmed' => BookingStatus::Confirmed->value,
            'checked_in' => BookingStatus::CheckedIn->value,
            'completed' => BookingStatus::Completed->value,
            'cancelled' => BookingStatus::Cancelled->value,
            'no_show' => BookingStatus::NoShow->value,
            'pending' => BookingStatus::Held->value,
        ];
        $sortColumn = match ($sort) {
            'name' => $name,
            'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show', 'pending', 'cancellation_rate' => $sort,
            default => 'total',
        };
        $query = $bookings->toBase()
            ->leftJoin($table, fn (JoinClause $join) => $join
                ->on("$table.id", '=', $id)
                ->on("$table.business_id", '=', 'bookings.business_id'))
            ->select("$id as dimension_id", $name)
            ->selectRaw('SUM(CASE WHEN bookings.status <> ? THEN 1 ELSE 0 END) AS total', [BookingStatus::Held->value])
            ->selectRaw(
                'COALESCE(ROUND(100 * SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN bookings.status <> ? THEN 1 ELSE 0 END), 0), 2), 0) AS cancellation_rate',
                [BookingStatus::Cancelled->value, BookingStatus::Held->value],
            )
            ->when($search !== '', fn ($query) => $query->whereLike($name, "%{$search}%"))
            ->groupBy($id, $name)
            ->orderBy($sortColumn, $direction === 'asc' ? 'asc' : 'desc')
            ->orderBy($name)
            ->orderBy($id);

        foreach ($statuses as $alias => $value) {
            $query->selectRaw("SUM(CASE WHEN bookings.status = ? THEN 1 ELSE 0 END) AS $alias", [$value]);
        }

        return $query;
    }

    /** @return array{id: ?string, name: ?string, total: int, confirmed: int, checked_in: int, completed: int, cancelled: int, no_show: int, pending: int, cancellation_rate: float} */
    private function formatBreakdownRow(object $row): array
    {
        return [
            'id' => $row->dimension_id,
            'name' => $row->name,
            'total' => (int) $row->total,
            'confirmed' => (int) $row->confirmed,
            'checked_in' => (int) $row->checked_in,
            'completed' => (int) $row->completed,
            'cancelled' => (int) $row->cancelled,
            'no_show' => (int) $row->no_show,
            'pending' => (int) $row->pending,
            'cancellation_rate' => (float) $row->cancellation_rate,
        ];
    }

    /** @param Builder<Booking> $bookings
     * @return list<array{id: string, name: string, total: int}>
     */
    private function topServiceRows(Builder $bookings, CarbonImmutable $now): array
    {
        return $this->breakdownQuery(
            $this->reportableBookings($bookings, $now),
            'services',
            'service_id',
            'total',
            'desc',
        )
            ->havingRaw('SUM(CASE WHEN bookings.status <> ? THEN 1 ELSE 0 END) > 0', [BookingStatus::Held->value])
            ->limit(10)
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->dimension_id,
                'name' => (string) ($row->name ?? ''),
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function mergeServiceRows(array $current, array $previous): array
    {
        $rows = [];
        foreach (['current' => $current, 'previous' => $previous] as $period => $services) {
            foreach ($services as $service) {
                $id = $service['id'];
                $rows[$id] ??= [
                    'id' => $id,
                    'name' => $service['name'],
                    'current' => 0,
                    'previous' => 0,
                ];
                $rows[$id][$period] = $service['total'];
            }
        }

        foreach ($rows as &$row) {
            $row['difference'] = $row['current'] - $row['previous'];
        }
        unset($row);

        $rows = array_values($rows);
        usort($rows, static fn (array $left, array $right): int => ($right['current'] + $right['previous'])
            <=> ($left['current'] + $left['previous']));

        return $rows;
    }

    private function compareStatistics(array $current, array $previous): array
    {
        $metrics = [];
        foreach (['total', 'confirmed', 'completed', 'cancelled'] as $metric) {
            $metrics[$metric] = $this->countComparison($current[$metric], $previous[$metric]);
        }

        $metrics['cancellation_rate'] = [
            'current' => $current['cancellation_rate'],
            'previous' => $previous['cancellation_rate'],
            'difference' => round($current['cancellation_rate'] - $previous['cancellation_rate'], 2),
        ];

        return $metrics;
    }

    private function countComparison(int $current, int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'difference' => $current - $previous,
            'change_percent' => match (true) {
                $previous > 0 => round(100 * ($current - $previous) / $previous, 2),
                $current > 0 => null,
                default => 0.0,
            },
            'change_state' => match (true) {
                $previous > 0 => 'changed',
                $current > 0 => 'no_previous',
                default => 'both_zero',
            },
        ];
    }

    private function alignedTrend(
        array $current,
        array $previous,
        PeriodComparisonWindow $window,
    ): array {
        $labels = $currentCounts = $previousCounts = [];
        for ($offset = 0; $offset < $window->currentDays; $offset++) {
            $labels[] = $offset + 1;
            $currentDate = $window->currentStart->addDays($offset)->toDateString();
            $previousDate = $window->previousStart->addDays($offset)->toDateString();
            $currentCounts[] = $current[$currentDate] ?? 0;
            $previousCounts[] = $previous[$previousDate] ?? 0;
        }

        return [
            'labels' => $labels,
            'current' => $currentCounts,
            'previous' => $previousCounts,
        ];
    }

    /**
     * @param  Builder<Booking>  $bookings
     * @return list<array{id: ?string, name: ?string, count: int}>
     */
    private function countsByRelation(Builder $bookings, string $table, string $foreignKey, ?int $limit = null): array
    {
        $column = "bookings.$foreignKey";

        return (clone $bookings)->toBase()
            ->leftJoin($table, fn (JoinClause $join) => $join
                ->on("$table.id", '=', $column)
                ->on("$table.business_id", '=', 'bookings.business_id'))
            ->select("$column as dimension_id", "$table.name")
            ->selectRaw('COUNT(*) AS booking_count')
            ->groupBy($column, "$table.name")
            ->orderByDesc('booking_count')
            ->orderBy("$table.name")
            ->orderBy($column)
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->dimension_id,
                'name' => $row->name,
                'count' => (int) $row->booking_count,
            ])
            ->all();
    }

    /**
     * @param  Builder<Booking>  $bookings
     * @return Collection<int, Booking>
     */
    private function upcoming(Builder $bookings, CarbonImmutable $now, Location $location): Collection
    {
        return (clone $bookings)
            ->where('bookings.starts_at', '>=', $now->utc()->format('Y-m-d H:i:s.u'))
            ->whereIn('bookings.status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
            ->orderBy('bookings.starts_at')
            ->orderBy('bookings.id')
            ->limit(10)
            ->get(['id', 'location_id', 'reference', 'starts_at', 'service_name_snapshot', 'status'])
            ->each(fn (Booking $booking) => $booking->setRelation('location', $location));
    }
}
