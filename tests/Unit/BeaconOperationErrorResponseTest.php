<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\BeaconOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Jobs\Beacon\ProcessModOperationJob;
use Pterodactyl\Beacon\Operations\PublicOperationError;
use Pterodactyl\Jobs\Beacon\ProcessModpackOperationJob;
use Pterodactyl\Jobs\Beacon\ProcessMinecraftServiceOperationJob;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconModController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconModpackController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconMinecraftServiceController;

class BeaconOperationErrorResponseTest extends TestCase
{
    public static function features(): array
    {
        return [
            [ProcessModpackOperationJob::class, BeaconModpackController::class],
            [ProcessModOperationJob::class, BeaconModController::class],
            [ProcessMinecraftServiceOperationJob::class, BeaconMinecraftServiceController::class],
        ];
    }

    #[DataProvider('features')]
    public function testDatabaseDetailsDoNotReachTheSerializedOperation(string $job, string $controller): void
    {
        $instance = (new \ReflectionClass($job))->newInstanceWithoutConstructor();
        $message = (new \ReflectionMethod($job, 'safeError'))->invoke($instance, new \PDOException('SQLSTATE[23000] AUDIT_PRIVATE_VALUE'));
        $operation = (new BeaconOperation())->forceFill([
            'type' => 'audit.operation',
            'payload' => ['original_runtime' => ['secret' => 'AUDIT_PRIVATE_VALUE']],
            'status' => 'failed',
            'error_code' => 'audit_failure',
            'error_message' => $message,
            'result' => [],
        ]);
        $instance = (new \ReflectionClass($controller))->newInstanceWithoutConstructor();
        $data = (new \ReflectionMethod($controller, 'operationData'))->invoke($instance, $operation);

        $this->assertSame('failed', $data['status']);
        $this->assertSame('audit_failure', $data['error']['code']);
        $this->assertSame($message, $data['error']['message']);
        $this->assertStringNotContainsString('AUDIT_PRIVATE_VALUE', json_encode($data));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($data));
        $this->assertArrayNotHasKey('payload', $data);
    }

    public function testLoggingDoesNotAttachTheOriginalException(): void
    {
        $exception = new \PDOException('AUDIT_PRIVATE_VALUE');
        Log::shouldReceive('error')->once()->with('Beacon operation failed.', \Mockery::on(function ($context) {
            $this->assertSame(123, $context['operation_id']);
            $this->assertSame(\PDOException::class, $context['exception_class']);
            $this->assertStringNotContainsString('AUDIT_PRIVATE_VALUE', json_encode($context));
            $this->assertArrayNotHasKey('exception', $context);

            return true;
        }));
        PublicOperationError::report($exception, 'modpack.operation', 123);
    }
}
