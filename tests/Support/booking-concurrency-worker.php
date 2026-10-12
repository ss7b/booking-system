<?php

use App\Domain\Booking\Services\CreateBooking;
use App\Models\Business;
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
    $location = Location::query()->findOrFail($payload['location_id']);
    $service = Service::query()->findOrFail($payload['service_id']);
    $staff = Staff::query()->findOrFail($payload['staff_id']);
    $result = app(CreateBooking::class)->execute(
        $business,
        $location,
        $service,
        $staff,
        new DateTimeImmutable($payload['start']),
        ['name' => 'Concurrency Test Customer', 'phone' => $payload['phone']],
        null,
        false,
        'public',
        'customer',
        'concurrency-test',
    );

    echo json_encode(['status' => 'created', 'booking_id' => $result->booking->getKey()], JSON_THROW_ON_ERROR);
} catch (DomainException $exception) {
    if ($exception->getMessage() !== 'SLOT_UNAVAILABLE') {
        fwrite(STDERR, $exception->getMessage());
        exit(1);
    }

    echo json_encode(['status' => 'slot_unavailable'], JSON_THROW_ON_ERROR);
}