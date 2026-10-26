<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/** Bounded streaming reads; never buffer a JAR or follow a Wings redirect. */
class VanillaFileReader
{
    public function __construct(private DaemonFileRepository $files)
    {
    }

    public function read(Server $server, string $path, int $limit): string
    {
        $content = '';
        $this->consume($server, $path, $limit, function (string $chunk) use (&$content) { $content .= $chunk; });

        return $content;
    }

    public function consume(Server $server, string $path, int $limit, callable $consume): void
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A positive Vanilla input limit is required.');
        }
        try {
            $response = $this->files->setServer($server)->getHttpClient()->get('/api/servers/' . $server->uuid . '/files/contents', [
                'query' => ['file' => $path], 'stream' => true, 'allow_redirects' => false,
                'headers' => ['Accept-Encoding' => 'identity'],
            ]);
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
        $body = $response->getBody();
        try {
            $length = $response->getHeaderLine('Content-Length');
            if ($response->getStatusCode() !== 200 || (ctype_digit($length) && (float) $length > $limit)) {
                throw new ConflictHttpException('The Vanilla configuration input is unavailable or exceeds its size limit.');
            }
            $size = 0;
            while (!$body->eof()) {
                $chunk = $body->read(min(1048576, $limit + 1 - $size));
                $size += strlen($chunk);
                if ($size > $limit || ($chunk === '' && !$body->eof())) {
                    throw new ConflictHttpException('The Vanilla configuration input could not be read safely.');
                }
                $consume($chunk);
            }
        } finally {
            $body->close();
        }
    }
}
