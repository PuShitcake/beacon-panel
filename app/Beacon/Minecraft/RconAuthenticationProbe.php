<?php

namespace Pterodactyl\Beacon\Minecraft;

/** Bounded authentication-only probe. Never executes console commands or logs credentials. */
class RconAuthenticationProbe
{
    public const REQUEST_ID = 17311;

    public function authenticate(string $ip, int $port, string $password): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || in_array($ip, ['0.0.0.0', '::'], true)
            || $port < 1024 || $port > 65535 || $password === '' || strlen($password) > 256) {
            return false;
        }
        $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $stream = @stream_socket_client('tcp://' . $address . ':' . $port, $errno, $error, 2, STREAM_CLIENT_CONNECT);
        if ($stream === false) {
            return false;
        }
        try {
            stream_set_timeout($stream, 2);
            $deadline = microtime(true) + 4;
            $packet = self::packet($password);
            $sent = 0;
            while ($sent < strlen($packet)) {
                $n = @fwrite($stream, substr($packet, $sent));
                if ($n === false || $n === 0) {
                    return false;
                }
                $sent += $n;
            }
            // Some servers send an empty response before the authentication response.
            for ($i = 0; $i < 2; ++$i) {
                $header = $this->read($stream, 4, $deadline);
                if ($header === null) {
                    return false;
                }
                $length = unpack('Vlength', $header)['length'];
                if ($length < 10 || $length > 4096) {
                    return false;
                }
                $body = $this->read($stream, $length, $deadline);
                if ($body === null) {
                    return false;
                }
                $accepted = self::accepted($header . $body);
                if ($accepted !== null) {
                    return $accepted;
                }
            }

            return false;
        } finally {
            fclose($stream);
        }
    }

    public static function packet(string $password): string
    {
        return pack('VVV', strlen($password) + 10, self::REQUEST_ID, 3) . $password . "\0\0";
    }

    public static function accepted(string $packet): ?bool
    {
        if (strlen($packet) < 14 || strlen($packet) > 4100 || substr($packet, -2) !== "\0\0") {
            return false;
        }
        $header = unpack('Vlength/Vid/Vtype', substr($packet, 0, 12));
        if ($header['length'] !== strlen($packet) - 4) {
            return false;
        }
        if ($header['type'] === 2) {
            return $header['id'] === self::REQUEST_ID;
        }

        return $header['type'] === 0 && $header['id'] === self::REQUEST_ID ? null : false;
    }

    private function read($stream, int $size, float $deadline): ?string
    {
        $data = '';
        while (strlen($data) < $size) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            stream_set_timeout($stream, 0, (int) (min(2, $remaining) * 1000000));
            $chunk = @fread($stream, $size - strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
