<?php

namespace Pterodactyl\Beacon\Minecraft;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** BDS uses literal UTF-8 key=value lines, not Java escaping or continuations. */
class BedrockPropertiesDocument
{
    private array $records = [];
    private array $values = [];
    private array $positions = [];

    public function __construct(string $content)
    {
        if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new ConflictHttpException('Bedrock server.properties must contain valid UTF-8 text without NUL bytes.');
        }
        $parts = preg_split('/(\r\n|\r|\n)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0; $i < count($parts); $i += 2) {
            $line = $parts[$i];
            $ending = $parts[$i + 1] ?? '';
            $index = count($this->records);
            $this->records[] = ['raw' => $line . $ending, 'ending' => $ending];
            if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!preg_match('/^[a-z][a-z0-9-]*$/D', $key)) {
                continue;
            }
            // BDS duplicate precedence is not a Java Properties contract. Never guess it.
            if (array_key_exists($key, $this->positions)) {
                throw new ConflictHttpException('Bedrock server.properties contains duplicate keys. Review the file before editing.');
            }
            $this->values[$key] = $value;
            $this->positions[$key] = $index;
        }
    }

    public function values(): array
    {
        return $this->values;
    }

    public function patch(array $changes): string
    {
        $records = $this->records;
        foreach ($changes as $key => $value) {
            if (!array_key_exists($key, $this->positions) || !is_string($value)
                || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new ConflictHttpException('Only existing, supported Bedrock settings with valid single-line values can be changed.');
            }
            $index = $this->positions[$key];
            $records[$index]['raw'] = $key . '=' . $value . $records[$index]['ending'];
        }

        return implode('', array_column($records, 'raw'));
    }
}
