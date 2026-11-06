<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Booking\Services\BookingManagementLink;
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
use Illuminate\Http\Exceptions\HttpResponseException;
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
            if ($exception->getMessage() === 'CUSTOMER_CONTACT_CONFLICT') {
                return response()->json([
                    'message' => 'The customer contact details match multiple customer records.',
                    'code' => 'CUSTOMER_CONTACT_CONFLICT',
                ], 409);
            }

            if ($exception->getMessage() === 'CUSTOMER_BOOKING_OVERLAP') {
                return response()->json([
                    'message' => 'The customer already has a booking during this time.',
                    'code' => 'CUSTOMER_BOOKING_OVERLAP',
                ], 409);
            }

            if ($exception->getMessage() === 'CUSTOMER_SERVICE_DAILY_LIMIT') {
                return response()->json([
                    'message' => 'You already have an active booking for this service on the selected date.',
                    'code' => 'CUSTOMER_SERVICE_DAILY_LIMIT',
                ], 409);
            }

            return response()->json(['message' => 'The requested time slot is no longer available.', 'code' => 'SLOT_UNAVAILABLE'], 409);
        }
    }

    public function show(Request $request, string $reference, BookingManagementLink $managementLinks): JsonResponse
    {
        [$booking] = $this->authorizedBooking($request, $reference, $managementLinks);

        return response()->json(['data' => [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'service_id' => $booking->service_id,
            'staff_id' => $booking->staff_id,
            'location_id' => $booking->location_id,
            'timezone' => $booking->location->timezone ?: $booking->business->timezone,
            'starts_at' => $booking->starts_at->setTimezone($booking->location->timezone ?: $booking->business->timezone)->format(DATE_ATOM),
            'ends_at' => $booking->ends_at->setTimezone($booking->location->timezone ?: $booking->business->timezone)->format(DATE_ATOM),
            'service' => ['name' => $booking->service_name_snapshot, 'duration_minutes' => $booking->duration_snapshot],
        ]]);
    }

    public function confirm(
        Request $request,
        string $reference,
        IdempotentMutation $idempotency,
        TransitionBooking $transition,
        BookingManagementLink $managementLinks,
    ): JsonResponse {
        return $this->transition($request, $reference, $idempotency, $transition, $managementLinks, BookingStatus::Confirmed, null, false);
    }

    public function cancel(
        Request $request,
        string $reference,
        IdempotentMutation $idempotency,
        TransitionBooking $transition,
        BookingManagementLink $managementLinks,
    ): JsonResponse {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return $this->transition($request, $reference, $idempotency, $transition, $managementLinks, BookingStatus::Cancelled, $data['reason'] ?? null);
    }

    public function reschedule(
        Request $request,
        string $reference,
        IdempotentMutation $idempotency,
        RescheduleBooking $reschedule,
        BookingManagementLink $managementLinks,
    ): JsonResponse {
        [$booking, $viaManagementLink] = $this->authorizedBooking($request, $reference, $managementLinks);
        if ($viaManagementLink && ($response = $this->managementMutationError($booking))) {
            return $response;
        }

        $rules = [
            'staff_id' => $viaManagementLink ? ['prohibited'] : ['required', 'ulid'],
            'location_id' => $viaManagementLink ? ['prohibited'] : ['required', 'ulid'],
            'start' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
        if ($viaManagementLink) {
            $rules['service_id'] = ['prohibited'];
        }
        $data = $request->validate($rules);
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);
        $location = Location::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->findOrFail($viaManagementLink ? $booking->location_id : $data['location_id']);
        $staff = Staff::query()
            ->where('business_id', $business->getKey())
            ->where('status', 'active')
            ->findOrFail($viaManagementLink ? $booking->staff_id : $data['staff_id']);
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
                function () use ($reschedule, $business, $booking, $location, $staff, $data, $viaManagementLink, $managementLinks): JsonResponse {
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

                    $responseData = $this->bookingData($updated, $location->timezone ?: $business->timezone);
                    if ($viaManagementLink) {
                        $responseData['management_url'] = $managementLinks->createUrl($updated);
                    }

                    return response()->json(['data' => $responseData]);
                },
            );
        } catch (ConflictHttpException) {
            return response()->json(['message' => 'The idempotency key was reused with a different payload.', 'code' => 'IDEMPOTENCY_CONFLICT'], 409);
        } catch (DomainException $exception) {
            $code = match ($exception->getMessage()) {
                'SLOT_UNAVAILABLE' => 'SLOT_UNAVAILABLE',
                'CUSTOMER_BOOKING_OVERLAP' => 'CUSTOMER_BOOKING_OVERLAP',
                'CUSTOMER_SERVICE_DAILY_LIMIT' => 'CUSTOMER_SERVICE_DAILY_LIMIT',
                default => 'INVALID_BOOKING_TRANSITION',
            };
            $message = match ($exception->getMessage()) {
                'CUSTOMER_BOOKING_OVERLAP' => 'The customer already has a booking during this time.',
                'CUSTOMER_SERVICE_DAILY_LIMIT' => 'You already have an active booking for this service on the selected date.',
                default => $exception->getMessage(),
            };

            return response()->json(['message' => $message, 'code' => $code], 409);
        }
    }

    private function transition(
        Request $request,
        string $reference,
        IdempotentMutation $idempotency,
        TransitionBooking $transition,
        BookingManagementLink $managementLinks,
        BookingStatus $target,
        ?string $reason,
        bool $allowManagementLink = true,
    ): JsonResponse {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);
        [$booking, $viaManagementLink] = $this->authorizedBooking($request, $reference, $managementLinks, $allowManagementLink);
        if ($viaManagementLink && ($response = $this->managementMutationError($booking))) {
            return $response;
        }
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

    /**
     * @return array{Booking, bool}
     */
    private function authorizedBooking(
        Request $request,
        string $reference,
        BookingManagementLink $managementLinks,
        bool $allowManagementLink = true,
    ): array {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 500);
        $token = $request->header('X-Booking-Access-Token');

        $booking = Booking::query()
            ->with(['location', 'business'])
            ->where('business_id', $business->getKey())
            ->where('reference', $reference)
            ->first();

        abort_unless($booking, 404);

        if (is_string($token) && Hash::check($token, $booking->access_token_hash ?? '')) {
            return [$booking, false];
        }

        $managementToken = $request->header('X-Booking-Management-Token');
        abort_unless($allowManagementLink && is_string($managementToken) && $managementToken !== '', 404);

        $failure = $managementLinks->validationError($managementToken, $business, $booking);
        if ($failure !== null) {
            $status = $failure === 'BOOKING_MANAGEMENT_LINK_INVALID' ? 404 : 410;
            $message = match ($failure) {
                'BOOKING_MANAGEMENT_LINK_EXPIRED' => 'The booking management link has expired.',
                'BOOKING_MANAGEMENT_LINK_REVOKED' => 'The booking management link is no longer valid.',
                default => 'The booking management link is invalid.',
            };

            throw new HttpResponseException(response()->json([
                'message' => $message,
                'code' => $failure,
            ], $status));
        }

        return [$booking, true];
    }

    private function managementMutationError(Booking $booking): ?JsonResponse
    {
        if (now()->greaterThanOrEqualTo($booking->starts_at)) {
            return response()->json([
                'message' => 'This booking can only be viewed after its start time.',
                'code' => 'BOOKING_MANAGEMENT_READ_ONLY',
            ], 409);
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            return response()->json([
                'message' => 'This booking cannot be changed in its current state.',
                'code' => 'INVALID_BOOKING_TRANSITION',
            ], 409);
        }

        return null;
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
