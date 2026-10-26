<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/** Bounded ELF header check, not a game version or whole-binary authenticity check. */
class BedrockRuntimeReader extends VanillaJarVersionReader
{
    public const MAX_BYTES = 536870912;

    public function __construct(private DaemonFileRepository $files)
    {
    }

    public function inspect(Server $server, string $path, int $expectedSize): ?array
    {
        if ($path !== '/bedrock_server' || $expectedSize < 64 || $expectedSize > self::MAX_BYTES) {
            return null;
        }
        // Wings may ignore Range. Close after exactly 64 bytes rather than downloading
        // hundreds of MB for a version-independent properties policy.
        try {
            $response = $this->files->setServer($server)->getHttpClient()->get('/api/servers/' . $server->uuid . '/files/contents', [
                'query' => ['file' => $path], 'stream' => true, 'allow_redirects' => false, 'timeout' => 10,
                'headers' => ['Accept-Encoding' => 'identity'],
            ]);
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
        $body = $response->getBody();
        try {
            $length = $response->getHeaderLine('Content-Length');
            if ($response->getStatusCode() !== 200 || ($length !== '' && (!ctype_digit($length) || (float) $length !== (float) $expectedSize))) {
                throw new ConflictHttpException('The Bedrock runtime header changed or could not be read safely. Reload Configuration.');
            }
            $header = '';
            while (strlen($header) < 64 && !$body->eof()) {
                $chunk = $body->read(64 - strlen($header));
                if ($chunk === '') {
                    break;
                }
                $header .= $chunk;
            }
        } finally {
            $body->close();
        }
        if (strlen($header) !== 64 || substr($header, 0, 6) !== "\x7fELF\x02\x01") {
            return null;
        }

        return ['version' => 'bedrock-file', 'identity' => hash('sha256', $header . ':' . $expectedSize)];
    }
}
