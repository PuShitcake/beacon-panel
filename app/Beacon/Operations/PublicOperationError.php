<?php

namespace Pterodactyl\Beacon\Operations;

use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\DisplayException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class PublicOperationError
{
    // Exact application-authored messages only. Never allow a whole library exception family.
    private const RUNTIME_MESSAGES = [
        'A free backup slot is required before changing this modpack.',
        'Backup creation is temporarily rate limited. Try again after the backup cooldown.',
        'The modpack rollback backup no longer exists.',
        'The modpack rollback backup did not finish within 30 minutes.',
        'The modpack rollback backup failed.',
        'The safety backup record no longer exists.',
        'The safety backup did not complete within 30 minutes.',
        'The safety backup failed.',
        'The modpack JVM arguments could not be normalized.',
        'The modpack JVM arguments must be a bounded regular file.',
        'Stop the server before applying modpack JVM memory settings.',
        'The modpack JVM arguments exceed the file size limit.',
        'The modpack JVM memory settings could not be verified.',
        'The installed server pack did not identify a supported Minecraft loader.',
        'The backup restore did not complete successfully.',
        'The server did not stop within the configured time limit. No modpack files were changed.',
        'Stop the server before restoring or uninstalling its modpack.',
        'The original modpack safety backup is unavailable.',
        'Wings did not return a valid server power state. No modpack files were changed.',
        'The modpack was uninstalled, but the server did not start within the configured time limit. Start it manually.',
        'The Wings installation process failed.',
        'The server entered an unexpected state during installation.',
        'The modpack operation exceeded its configured time limit.',
        'The server did not stop within the configured time limit. No mod files were changed.',
        'The server must remain offline while its mods are being changed.',
        'The mod files were changed, but the server did not start within the configured time limit. Start it manually.',
        'Wings did not return a valid server power state. No mod files were changed.',
        'The mod operation failed and its file rollback also failed. Manual file review is required.',
        'Mod uninstall failed and its file rollback also failed. Manual file review is required.',
        'The server did not stop within the configured time limit. No settings were changed.',
        'The server must remain offline while RCON or Query is being configured.',
        'The settings were changed, but the server did not start within the configured time limit. Start it manually.',
        'Wings did not return a valid server power state. No settings were changed.',
        'RCON is already enabled by Beacon.',
        'QUERY is already enabled by Beacon.',
        'RCON is not managed or enabled by Beacon.',
        'QUERY is not managed or enabled by Beacon.',
        'The Minecraft service change failed and rollback also failed. Manual review is required.',
        'Automatic rollback requires the server to be offline. It was left for manual review.',
        'The previous Minecraft service allocation is unavailable for rollback.',
    ];

    public static function message(\Throwable $exception, string $fallback): string
    {
        // These exception types explicitly carry controlled, user-facing application messages.
        if ($exception instanceof ModpackProviderException
            || $exception instanceof ConflictHttpException
            || $exception instanceof DisplayException) {
            return $exception->getMessage();
        }
        if ($exception::class === \RuntimeException::class
            && in_array($exception->getMessage(), self::RUNTIME_MESSAGES, true)) {
            return $exception->getMessage();
        }

        return $fallback;
    }

    public static function diagnostic(\Throwable $exception, string $stage, int $operationId): array
    {
        return [
            'stage' => $stage,
            'operation_id' => $operationId,
            'exception_class' => $exception::class,
            'exception_code' => (int) $exception->getCode(),
            'source_file' => basename($exception->getFile()),
            'source_line' => $exception->getLine(),
            'cause_class' => $exception->getPrevious() ? $exception->getPrevious()::class : null,
            'cause_code' => $exception->getPrevious() ? (int) $exception->getPrevious()->getCode() : null,
        ];
    }

    public static function report(\Throwable $exception, string $stage, int $operationId): void
    {
        // Do not attach the original exception: query bindings, HTTP bodies and trace arguments
        // can contain credentials even when the public response has already been sanitized.
        Log::error('Beacon operation failed.', self::diagnostic($exception, $stage, $operationId));
    }
}
