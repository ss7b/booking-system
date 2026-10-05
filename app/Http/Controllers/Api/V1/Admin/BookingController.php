<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Domain\Booking\Services\IdempotentMutation;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Domain\Booking\Services\TransitionBooking;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Staff;
use DateTimeImmutable;
use DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function reschedule(Request $request, string $booking, IdempotentMutation $idempotency, RescheduleBooking $reschedule): JsonResponse
    {
        $data = $request->validate([
            'staff_id' => ['required', 'ulid'],
            'location_id' => ['required', 'ulid'],
            'start' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $business = $this->business($request);
        $record = Booking::query()->where('business_id', $business->getKey())->findOrFail($booking);
        $location = Location::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->findOrFail($data['location_id']);
        $staff = Staff::query()
            ->where('business_id', $business->getKey())
            ->where('status', 'active')
            ->findOrFail($data['staff_id']);
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.', 'code' => 'VALIDATION_ERROR'], 422);
        }

        try {
            return $idempotency->execute(
                $business,
                $key,
                $request->path(),
                ['booking' => $booking, ...$data],
                function () use ($reschedule, $business, $record, $location, $staff, $data, $request): JsonResponse {
                    $updated = $reschedule->execute(
                        $business,
                        $record,
                        $location,
                        $staff,
                        new DateTimeImmutable($data['start']),
                        'user',
                        (string) $request->user()->getAuthIdentifier(),
                        $data['reason'] ?? null,
                    );

                    return response()->json(['data' => [
                        'id' => $updated->getKey(),
                        'reference' => $updated->reference,
                        'status' => $updated->status->value,
                        'starts_at' => $updated->starts_at->format(DATE_ATOM),
                        'ends_at' => $updated->ends_at->format(DATE_ATOM),
                        'staff_id' => $updated->staff_id,
                        'location_id' => $updated->location_id,
                    ]]);
                },
            );
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            return response()->json(['message' => 'The idempotency key was reused with a different payload.', 'code' => 'IDEMPOTENCY_CONFLICT'], 409);
        } catch (DomainException $exception) {
            $code = $exception->getMessage() === 'SLOT_UNAVAILABLE' ? 'SLOT_UNAVAILABLE' : 'INVALID_BOOKING_TRANSITION';

            return response()->json(['message' => $exception->getMessage(), 'code' => $code], 409);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $filters = $request->validate([
            'location_id' => ['sometimes', 'ulid'],
            'staff_id' => ['sometimes', 'ulid'],
            'service_id' => ['sometimes', 'ulid'],
            'status' => ['sometimes', Rule::in(array_column(BookingStatus::cases(), 'value'))],
            'from' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date', 'after_or_equal:from'],
            'search' => ['sometimes', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $bookings = Booking::query()
            ->where('business_id', $business->getKey())
            ->when(isset($filters['location_id']), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when(isset($filters['staff_id']), fn ($query) => $query->where('staff_id', $filters['staff_id']))
            ->when(isset($filters['service_id']), fn ($query) => $query->where('service_id', $filters['service_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['from']), fn ($query) => $query->where(
                'starts_at',
                '>=',
                CarbonImmutable::parse($filters['from'], $business->timezone)->startOfDay()->utc(),
            ))
            ->when(isset($filters['until']), fn ($query) => $query->where(
                'starts_at',
                '<',
                CarbonImmutable::parse($filters['until'], $business->timezone)->addDay()->startOfDay()->utc(),
            ))
            ->when(isset($filters['search']), fn ($query) => $query->where(function ($nested) use ($filters) {
                $nested->where('reference', 'like', '%'.$filters['search'].'%')
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$filters['search'].'%')->orWhere('phone', 'like', '%'.$filters['search'].'%'));
            }))
            ->with(['service', 'staff', 'location', 'customer'])
            ->orderBy('starts_at')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => $bookings->getCollection()->map(fn (Booking $booking) => $this->bookingData($booking))->values(),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
            ],
        ]);
    }

    public function show(Request $request, string $booking): JsonResponse
    {
        $record = Booking::query()
            ->where('business_id', $this->business($request)->getKey())
            ->with(['service', 'staff', 'location', 'customer'])
            ->findOrFail($booking);

        return response()->json(['data' => $this->bookingData($record)]);
    }

    public function transition(Request $request, string $booking, string $action, IdempotentMutation $idempotency, TransitionBooking $transition): JsonResponse
    {
        $targets = [
            'confirm' => BookingStatus::Confirmed,
            'cancel' => BookingStatus::Cancelled,
            'check-in' => BookingStatus::CheckedIn,
            'complete' => BookingStatus::Completed,
            'no-show' => BookingStatus::NoShow,
        ];

        abort_unless(isset($targets[$action]), 404);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $business = $this->business($request);
        $record = Booking::query()
            ->where('business_id', $business->getKey())
            ->findOrFail($booking);
        $actorId = (string) $request->user()->getAuthIdentifier();

        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.', 'code' => 'VALIDATION_ERROR'], 422);
        }

        try {
            return $idempotency->execute(
                $business,
                $key,
                $request->path(),
                ['booking' => $booking, 'action' => $action, 'reason' => $data['reason'] ?? null],
                function () use ($transition, $record, $targets, $action, $data, $actorId): JsonResponse {
                    $updated = $transition->execute(
                        $record,
                        $targets[$action],
                        $data['reason'] ?? null,
                        'user',
                        $actorId,
                    );

                    return response()->json(['data' => ['id' => $updated->getKey(), 'reference' => $updated->reference, 'status' => $updated->status->value]]);
                },
            );
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            return response()->json(['message' => 'The idempotency key was reused with a different payload.', 'code' => 'IDEMPOTENCY_CONFLICT'], 409);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'INVALID_BOOKING_TRANSITION',
            ], 409);
        }
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        return $business;
    }

    private function bookingData(Booking $booking): array
    {
        return [
            'id' => $booking->getKey(),
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'starts_at' => $booking->starts_at->format(DATE_ATOM),
            'ends_at' => $booking->ends_at->format(DATE_ATOM),
            'service' => ['id' => $booking->service_id, 'name' => $booking->service_name_snapshot],
            'staff' => ['id' => $booking->staff_id, 'name' => $booking->staff->name],
            'location' => ['id' => $booking->location_id, 'name' => $booking->location->name],
            'customer' => ['id' => $booking->customer_id, 'name' => $booking->customer->name, 'phone' => $booking->customer->phone],
        ];
    }
}