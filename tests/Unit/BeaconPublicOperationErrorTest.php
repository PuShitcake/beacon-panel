<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Jobs\Beacon\ProcessModOperationJob;
use Pterodactyl\Beacon\Operations\PublicOperationError;
use Pterodactyl\Jobs\Beacon\ProcessModpackOperationJob;
use Pterodactyl\Jobs\Beacon\ProcessMinecraftServiceOperationJob;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class BeaconPublicOperationErrorTest extends TestCase
{
    public static function jobs(): array
    {
        return [
            [ProcessModpackOperationJob::class, 'The modpack operation failed. Review the protected application logs.'],
            [ProcessModOperationJob::class, 'The mod operation failed. Review the protected application logs.'],
            [ProcessMinecraftServiceOperationJob::class, 'The Minecraft service operation failed. Review the protected application logs.'],
        ];
    }

    private function message(string $job, \Throwable $exception): string
    {
        $instance = (new \ReflectionClass($job))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($job, 'safeError'))->invoke($instance, $exception);
    }

    #[DataProvider('jobs')]
    public function testDatabaseAndLibraryExceptionsCannotPublishPrivateMessages(string $job, string $fallback): void
    {
        foreach ([\PDOException::class, \RuntimeException::class, \InvalidArgumentException::class, \UnexpectedValueException::class] as $type) {
            $message = $this->message($job, new $type('SQLSTATE[23000] AUDIT_PRIVATE_VALUE /internal/query-path'));
            $this->assertSame($fallback, $message);
            $this->assertStringNotContainsString('AUDIT_PRIVATE_VALUE', $message);
        }
    }

    #[DataProvider('jobs')]
    public function testActionableBackupConflictRemainsReadable(string $job): void
    {
        $message = 'A backup slot is required before a modpack can be changed.';
        $this->assertSame($message, $this->message($job, new ConflictHttpException($message)));
    }

    #[DataProvider('jobs')]
    public function testHttpTransportErrorsCannotPublishRequestOrResponseDetails(string $job, string $fallback): void
    {
        $exception = new \GuzzleHttp\Exception\RequestException(
            'Client error: AUDIT_PRIVATE_VALUE',
            new \GuzzleHttp\Psr7\Request('GET', 'https://example.com', ['Authorization' => 'AUDIT_PRIVATE_HEADER']),
            new \GuzzleHttp\Psr7\Response(500, [], 'AUDIT_PRIVATE_BODY'),
        );
        $this->assertSame($fallback, $this->message($job, $exception));
        $this->assertStringNotContainsString('AUDIT_PRIVATE', $this->message($job, $exception));
    }

    #[DataProvider('jobs')]
    public function testApprovedRuntimeMessageMustNotAllowDatabaseExceptionsOrExtraText(string $job, string $fallback): void
    {
        $message = 'A free backup slot is required before changing this modpack.';
        $this->assertSame($message, $this->message($job, new \RuntimeException($message)));
        $this->assertSame($fallback, $this->message($job, new \PDOException($message)));
        $this->assertSame($fallback, $this->message($job, new \RuntimeException($message . ' AUDIT_PRIVATE_VALUE')));
    }

    #[DataProvider('jobs')]
    public function testControlledProviderFailureRemainsReadableWithoutPublishingItsCause(string $job): void
    {
        $cause = new \PDOException('AUDIT_PRIVATE_VALUE');
        $exception = new ModpackProviderException('The CurseForge Egg did not produce a fresh client manifest.', 0, $cause);
        $this->assertSame($exception->getMessage(), $this->message($job, $exception));
        $this->assertStringNotContainsString('AUDIT_PRIVATE_VALUE', $this->message($job, $exception));
    }

    #[DataProvider('jobs')]
    public function testUntrustedInstallerOutputAndRuntimeSubclassesAreNotPublic(string $job, string $fallback): void
    {
        $this->assertSame($fallback, $this->message($job, new \RuntimeException('The Beacon installer failed: AUDIT_PRIVATE_VALUE')));
        $exception = new class ('A free backup slot is required before changing this modpack.') extends \RuntimeException {};
        $this->assertSame($fallback, $this->message($job, $exception));
    }

    public function testPrivateLogContextPreservesDiagnosticsWithoutExceptionMessagesOrArguments(): void
    {
        $exception = new \RuntimeException('AUDIT_PRIVATE_VALUE', 42, new \PDOException('PRIVATE_QUERY_BINDINGS'));
        $diagnostic = PublicOperationError::diagnostic($exception, 'modpack.rollback', 123);
        $this->assertSame(123, $diagnostic['operation_id']);
        $this->assertSame('modpack.rollback', $diagnostic['stage']);
        $this->assertSame(\RuntimeException::class, $diagnostic['exception_class']);
        $this->assertSame(42, $diagnostic['exception_code']);
        $this->assertSame(\PDOException::class, $diagnostic['cause_class']);
        $this->assertSame(basename(__FILE__), $diagnostic['source_file']);
        $this->assertGreaterThan(0, $diagnostic['source_line']);
        $this->assertStringNotContainsString('AUDIT_PRIVATE_VALUE', json_encode($diagnostic));
        $this->assertStringNotContainsString('PRIVATE_QUERY_BINDINGS', json_encode($diagnostic));
        $this->assertArrayNotHasKey('exception', $diagnostic);
        $this->assertArrayNotHasKey('trace', $diagnostic);
    }
}
