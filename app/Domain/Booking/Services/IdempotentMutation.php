<?php

namespace App\Domain\Booking\Services;

use App\Models\Business;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class IdempotentMutation
{
    public function execute(Business $business, string $key, string $endpoint, array $payload, Closure $operation): JsonResponse
    {
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($business, $key, $endpoint, $requestHash, $payload, $operation): JsonResponse {
            DB::table('idempotency_keys')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'business_id' => $business->getKey(),
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $requestHash,
                'response_status' => 0,
                'response_body' => json_encode(Crypt::encryptString('{}'), JSON_THROW_ON_ERROR),
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = IdempotencyKey::query()
                ->where('business_id', $business->getKey())
                ->where('key', $key)
                ->where('endpoint', $endpoint)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->expires_at <= now()) {
                $record->request_hash = $requestHash;
                $record->response_status = 0;
                $record->response_body = json_encode(Crypt::encryptString('{}'), JSON_THROW_ON_ERROR);
                $record->expires_at = now()->addDay();
                $record->save();
            }

            if (! hash_equals($record->request_hash, $requestHash)) {
                throw new ConflictHttpException('IDEMPOTENCY_CONFLICT');
            }

            if ($record->response_status !== 0) {
                $encryptedBody = json_decode($record->response_body, true, flags: JSON_THROW_ON_ERROR);
                $body = json_decode(Crypt::decryptString($encryptedBody), true, flags: JSON_THROW_ON_ERROR);

                return response()->json($body, $record->response_status);
            }

            $response = $operation();
            $record->response_status = $response->getStatusCode();
            $record->response_body = json_encode(
                Crypt::encryptString(json_encode($response->getData(true), JSON_THROW_ON_ERROR)),
                JSON_THROW_ON_ERROR,
            );
            $record->save();

            return $response;
        }, attempts: 3);
    }
}