<?php

namespace Pterodactyl\Beacon\Operations;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\ApiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Pterodactyl\Models\BeaconOperation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StartServerProvisioningService
{
    /**
     * @return array{BeaconOperation, bool}
     */
    public function handle(ApiKey $apiKey, int $userId, string $correlationId, string $idempotencyKey, array $payload): array
    {
        $requestHash = hash('sha256', json_encode(['type' => 'server.create', 'payload' => $payload], JSON_THROW_ON_ERROR));
        $actorKey = 'api-key:' . $apiKey->id;

        try {
            return DB::transaction(function () use ($apiKey, $userId, $correlationId, $idempotencyKey, $payload, $requestHash, $actorKey) {
                $existing = BeaconOperation::query()
                    ->where('actor_key', $actorKey)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof BeaconOperation) {
                    return [$this->validateReplay($existing, $requestHash), false];
                }

                $operation = BeaconOperation::query()->create([
                    'uuid' => Uuid::uuid4()->toString(),
                    'api_key_id' => $apiKey->id,
                    'user_id' => $userId,
                    'server_id' => null,
                    'actor_key' => $actorKey,
                    'type' => 'server.create',
                    'status' => BeaconOperation::STATUS_PENDING,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'payload' => $payload,
                    'error_code' => null,
                    'error_message' => null,
                    'result' => null,
                    'started_at' => null,
                    'finished_at' => null,
                ]);

                return [$operation, true];
            }, 5);
        } catch (QueryException $exception) {
            $existing = BeaconOperation::query()
                ->where('actor_key', $actorKey)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if (!$existing instanceof BeaconOperation) {
                throw $exception;
            }

            return [$this->validateReplay($existing, $requestHash), false];
        }
    }

    private function validateReplay(BeaconOperation $operation, string $requestHash): BeaconOperation
    {
        if (!hash_equals($operation->request_hash ?? '', $requestHash)) {
            throw new ConflictHttpException('The idempotency key was already used with a different request.');
        }

        return $operation;
    }
}
