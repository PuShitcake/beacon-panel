<?php

namespace Pterodactyl\Beacon\Operations;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Pterodactyl\Models\BeaconOperation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StartContentOperationService
{
    /**
     * @return array{BeaconOperation, bool}
     */
    public function handle(
        User $user,
        Server $server,
        string $type,
        string $correlationId,
        string $idempotencyKey,
        array $payload,
    ): array {
        $requestHash = hash('sha256', json_encode([
            'type' => $type,
            'server_id' => $server->id,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
        $actorKey = 'user:' . $user->id;

        try {
            return DB::transaction(function () use ($user, $server, $type, $correlationId, $idempotencyKey, $payload, $requestHash, $actorKey) {
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
                    'api_key_id' => null,
                    'user_id' => $user->id,
                    'server_id' => $server->id,
                    'actor_key' => $actorKey,
                    'type' => $type,
                    'status' => BeaconOperation::STATUS_PENDING,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'payload' => $payload,
                    'result' => null,
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
