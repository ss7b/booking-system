<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Booking\Services\CreateBooking;
use App\Domain\Booking\Services\IdempotentMutation;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Domain\Booking\Services\TransitionBooking;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BookingController extends Controller
{
    public function hold(Request $request, IdempotentMutation $idempotency, CreateBooking $createBooking): JsonResponse
    {
        return $this->store($request, $idempotency, $createBooking, true);
    }

    public function storeDirect(Request $request, IdempotentMutation $idempotency, CreateBooking $createBooking): JsonResponse
    {
        return $this->store($request, $idempotency, $createBooking, false);
    }

    private function store(Request $request, IdempotentMutation $idempotency, CreateBooking $createBooking, bool $hold): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);

        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            return response()->json([
                'message' => 'A valid Idempotency-Key header is required.',
                'code' => 'VALIDATION_ERROR',
                'errors' => ['Idempotency-Key' => ['A non-empty key of at most 255 characters is required.']],
            ], 422);
        }

        $data = $request->validate([
            'service_id' => ['required', 'ulid'],
            'staff_id' => ['required', 'ulid'],
            'location_id' => ['required', 'ulid'],
            'start' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'customer.name' => ['required', 'string', 'max:200'],
            'customer.phone' => ['required', 'string', 'max:50'],
            'customer.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $location = Location::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->findOrFail($data['location_id']);

        $service = Service::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('locations.is_active', true))
            ->findOrFail($data['service_id']);

        $staff = Staff::query()
            ->where('business_id', $business->getKey())
            ->where('status', 'active')
            ->whereHas('services', fn ($query) => $query->whereKey($service->getKey()))
            ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('staff_locations.is_active', true))
            ->findOrFail($data['staff_id']);

        try {
            return $idempotency->execute(
                $business,
                $key,
                $request->path(),
                $data,
                function () use ($business, $location, $service, $staff, $data, $hold, $createBooking): JsonResponse {
                    $result = $createBooking->execute(
                        $business,
                        $location,
                        $service,
                        $staff,
                        new DateTimeImmutable($data['start']),
                        $data['customer'],
                        $data['notes'] ?? null,
                        $hold,
                    );

                    return response()->json([
                        'data' => $this->bookingData($result->booking, $location->timezone ?: $business->timezone) + [
                            'access_token' => $result->accessToken,
                        ],
                    ], 201);
                },
            );
        } catch (ConflictHttpException $exception) {
            return response()->json(['message' => 'The idempotency key was reused with a different payload.', 'code' => 'IDEMPOTENCY_CONFLICT'], 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => 'The requested time slot is no longer available.', 'code' => 'SLOT_UNAVAILABLE'], 409);
        }
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $booking = $this->authorizedBooking($request, $reference);

        return response()->json(['data' => [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'starts_at' => $booking->starts_at->setTimezone($booking->location->timezone ?: $booking->business->timezone)->format(DATE_ATOM),
            'ends_at' => $booking->ends_at->setTimezone($booking->location->timezone ?: $booking->business->timezone)->format(DATE_ATOM),
            'service' => ['name' => $booking->service_name_snapshot, 'duration_minutes' => $booking->duration_snapshot],
        ]]);
    }

    public function confirm(Request $request, string $reference, IdempotentMutation $idempotency, TransitionBooking $transition): JsonResponse
    {
        return $this->transition($request, $reference, $idempotency, $transition, BookingStatus::Confirmed, null);
    }

    public function cancel(Request $request, string $reference, IdempotentMutation $idempotency, TransitionBooking $transition): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return $this->transition($request, $reference, $idempotency, $transition, BookingStatus::Cancelled, $data['reason'] ?? null);
    }

    public function reschedule(Request $request, string $reference, IdempotentMutation $idempotency, RescheduleBooking $reschedule): JsonResponse
    {
        $data = $request->validate([
            'staff_id' => ['required', 'ulid'],
            'location_id' => ['required', 'ulid'],
            'start' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);
        $booking = $this->authorizedBooking($request, $reference);
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
                ['booking' => $reference, ...$data],
                function () use ($reschedule, $business, $booking, $location, $staff, $data): JsonResponse {
                    $updated = $reschedule->execute(
                        $business,
                        $booking,
                        $location,
                        $staff,
                        new DateTimeImmutable($data['start']),
                        'customer',
                        null,
                        $data['reason'] ?? null,
                    );

                    return response()->json(['data' => $this->bookingData($updated, $location->timezone ?: $business->timezone)]);
                },
            );
        } catch (ConflictHttpException) {
            return response()->json(['message' => 'The idempotency key was reused with a different payload.', 'code' => 'IDEMPOTENCY_CONFLICT'], 409);
        } catch (DomainException $exception) {
            $code = $exception->getMessage() === 'SLOT_UNAVAILABLE' ? 'SLOT_UNAVAILABLE' : 'INVALID_BOOKING_TRANSITION';

            return response()->json(['message' => $exception->getMessage(), 'code' => $code], 409);
        }
    }

    private function transition(
        Request $request,
        string $reference,
        IdempotentMutation $idempotency,
        TransitionBooking $transition,
        BookingStatus $target,
        ?string $reason,
    ): JsonResponse {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);
        $booking = $this->authorizedBooking($request, $reference);
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.', 'code' => 'VALIDATION_ERROR'], 422);
        }

        try {
            return $idempotency->execute($business, $key, $request->path(), ['booking' => $reference, 'reason' => $reason], function () use ($transition, $booking, $target, $reason): JsonResponse {
                $updated = $transition->execute($booking, $target, $reason, 'customer');

                return response()->json(['data' => ['reference' => $updated->reference, 'status' => $updated->status->value]]);
            });
        } catch (ConflictHttpException) {
            return response()->json(['message' => 'The idempotency key was reused with a different payload.', 'code' => 'IDEMPOTENCY_CONFLICT'], 409);
        } catch (DomainException $exception) {
            $code = str_contains($exception->getMessage(), 'expired') ? 'BOOKING_EXPIRED' : 'INVALID_BOOKING_TRANSITION';

            return response()->json(['message' => $exception->getMessage(), 'code' => $code], 409);
        }
    }

    private function authorizedBooking(Request $request, string $reference): Booking
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);
        $token = $request->header('X-Booking-Access-Token');

        $booking = Booking::query()
            ->with(['location', 'business'])
            ->where('business_id', $business->getKey())
            ->where('reference', $reference)
            ->first();

        abort_unless($booking && is_string($token) && Hash::check($token, $booking->access_token_hash ?? ''), 404);

        return $booking;
    }

    private function bookingData(Booking $booking, string $timezone): array
    {
        $zone = new DateTimeZone($timezone);

        return [
            'id' => $booking->getKey(),
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'starts_at' => $booking->starts_at->setTimezone($zone)->format(DATE_ATOM),
            'ends_at' => $booking->ends_at->setTimezone($zone)->format(DATE_ATOM),
            'hold_expires_at' => $booking->hold_expires_at?->setTimezone($zone)->format(DATE_ATOM),
        ];
    }
}