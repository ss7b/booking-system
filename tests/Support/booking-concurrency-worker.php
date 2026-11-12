<?php

use App\Domain\Booking\Services\CreateBooking;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Domain\Booking\Services\TransitionBooking;
use App\Domain\Booking\Services\UpdateServiceLocationSettings;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Business;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'booking_test') {
    fwrite(STDERR, "Worker refused to use a database other than booking_test.\n");
    exit(2);
}

file_put_contents($payload['ready'], 'ready');
$deadline = microtime(true) + 15;

while (! is_file($payload['barrier'])) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Worker timed out waiting for the release barrier.\n");
        exit(2);
    }

    usleep(10000);
}

file_put_contents($payload['started'], 'started');

try {
    $business = Business::query()->findOrFail($payload['business_id']);
    $service = Service::query()->findOrFail($payload['service_id']);
    $operation = $payload['operation'] ?? 'create';
    $result = match ($operation) {
        'create' => (static function () use ($payload, $business, $service) {
            $location = Location::query()->findOrFail($payload['location_id']);
            $staff = isset($payload['staff_id']) ? Staff::query()->findOrFail($payload['staff_id']) : null;
            $created = app(CreateBooking::class)->execute(
                $business,
                $location,
                $service,
                $staff,
                new DateTimeImmutable($payload['start']),
                ['name' => 'Concurrency Test Customer', 'phone' => $payload['phone']],
                null,
                (bool) ($payload['hold'] ?? false),
                'public',
                'customer',
                'concurrency-test',
            );

            return ['status' => 'created', 'booking_id' => $created->booking->getKey()];
        })(),
        'transition' => (static function () use ($payload) {
            $booking = Booking::query()->findOrFail($payload['booking_id']);
            $updated = app(TransitionBooking::class)->execute(
                $booking,
                BookingStatus::from($payload['target_status']),
                'Concurrency test',
            );

            return ['status' => 'transitioned', 'booking_status' => $updated->status->value];
        })(),
        'reschedule' => (static function () use ($payload, $business, $service) {
            $booking = Booking::query()->findOrFail($payload['booking_id']);
            $location = Location::query()->findOrFail($payload['location_id']);
            $staff = isset($payload['staff_id']) ? Staff::query()->findOrFail($payload['staff_id']) : null;
            $updated = app(RescheduleBooking::class)->execute(
                $business,
                $booking,
                $location,
                $staff,
                new DateTimeImmutable($payload['start']),
                'system',
                null,
                'Concurrency test',
            );

            return ['status' => 'rescheduled', 'booking_id' => $updated->getKey()];
        })(),
        'settings' => (static function () use ($payload, $business, $service) {
            $location = Location::query()->findOrFail($payload['location_id']);
            $updated = app(UpdateServiceLocationSettings::class)->execute(
                $business,
                $service,
                $location,
                BookingMode::from($payload['booking_mode']),
                (int) $payload['capacity'],
            );

            return [
                'status' => 'settings_updated',
                'booking_mode' => $updated->booking_mode->value,
                'capacity' => $updated->capacity,
            ];
        })(),
        default => throw new RuntimeException('Unknown concurrency operation.'),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (DomainException $exception) {
    echo json_encode(['status' => 'rejected', 'reason' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}