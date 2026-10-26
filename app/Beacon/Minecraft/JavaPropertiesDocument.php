<?php

namespace Pterodactyl\Beacon\Minecraft;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Lossless storage of physical records, with Java Properties decoding of logical keys/values. */
class JavaPropertiesDocument
{
    private array $records = [];
    private array $values = [];
    private array $last = [];

    public function __construct(string $content, private bool $utf8)
    {
        // Modern Vanilla falls back to Latin-1 for the whole file, not individual values.
        $this->utf8 = $utf8 && mb_check_encoding($content, 'UTF-8');
        $parts = preg_split('/(\r\n|\r|\n)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $raw = '';
        $logical = '';
        $continued = false;
        for ($i = 0; $i < count($parts); $i += 2) {
            $line = $parts[$i];
            $eol = $parts[$i + 1] ?? '';
            if ($i === count($parts) - 1 && $line === '' && $raw === '') {
                break;
            }
            $raw .= $line . $eol;
            $fragment = $continued ? ltrim($line, " \t\f") : $line;
            $slashes = strlen($fragment) - strlen(rtrim($fragment, '\\'));
            $comment = !$continued && preg_match('/^[ \t\f]*[#!]/', $fragment);
            $continued = !$comment && $eol !== '' && $slashes % 2 === 1;
            $logical .= $continued ? substr($fragment, 0, -1) : $fragment;
            if ($continued) {
                continue;
            }
            $pair = $this->pair($logical);
            $index = count($this->records);
            $this->records[] = ['raw' => $raw, 'ending' => $eol];
            if ($pair !== null) {
                [$key, $value] = $pair;
                $this->values[$key] = $value;
                $this->last[$key] = $index;
            }
            $raw = '';
            $logical = '';
        }
        if ($raw !== '') {
            throw new ConflictHttpException('The server.properties continuation is incomplete. Review the file before editing.');
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
            if (!array_key_exists($key, $this->last)) {
                throw new ConflictHttpException('Only existing, supported Vanilla properties can be changed.');
            }
            $index = $this->last[$key];
            $records[$index]['raw'] = $key . '=' . $this->encode($value) . $records[$index]['ending'];
        }

        return implode('', array_column($records, 'raw'));
    }

    private function pair(string $line): ?array
    {
        $line = ltrim($line, " \t\f");
        if ($line === '' || str_contains('#!', $line[0])) {
            return null;
        }
        $end = strlen($line);
        $escaped = false;
        for ($i = 0; $i < strlen($line); ++$i) {
            if (!$escaped && str_contains("=: \t\f", $line[$i])) {
                $end = $i;
                break;
            }
            $escaped = !$escaped && $line[$i] === '\\';
        }
        $rest = ltrim(substr($line, $end), " \t\f");
        if ($rest !== '' && ($rest[0] === '=' || $rest[0] === ':')) {
            $rest = ltrim(substr($rest, 1), " \t\f");
        }

        return [$this->decode(substr($line, 0, $end)), $this->decode($rest)];
    }

    private function decode(string $value): string
    {
        if (!$this->utf8 || preg_match('//u', $value) !== 1) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }
        $result = '';
        for ($i = 0; $i < strlen($value); ++$i) {
            if ($value[$i] !== '\\') {
                $result .= $value[$i];
                continue;
            }
            if (++$i >= strlen($value)) {
                throw new ConflictHttpException('The server.properties escape sequence is incomplete.');
            }
            $c = $value[$i];
            if ($c !== 'u') {
                $result .= match ($c) {
                    't' => "\t", 'r' => "\r", 'n' => "\n", 'f' => "\f", default => $c,
                };
                continue;
            }
            $hex = substr($value, $i + 1, 4);
            if (strlen($hex) !== 4 || !ctype_xdigit($hex)) {
                throw new ConflictHttpException('The server.properties Unicode escape is invalid.');
            }
            $code = hexdec($hex);
            $i += 4;
            if ($code >= 0xD800 && $code <= 0xDBFF) {
                $low = substr($value, $i + 1, 6);
                if (!preg_match('/^\\\\u([dD][c-fC-F][0-9a-fA-F]{2})$/D', $low, $match)) {
                    throw new ConflictHttpException('The server.properties Unicode pair is invalid.');
                }
                $result .= mb_convert_encoding(pack('n2', $code, hexdec($match[1])), 'UTF-8', 'UTF-16BE');
                $i += 6;
            } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
                throw new ConflictHttpException('The server.properties Unicode pair is invalid.');
            } else {
                $result .= mb_convert_encoding(pack('n', $code), 'UTF-8', 'UTF-16BE');
            }
        }

        return $result;
    }

    private function encode(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new ConflictHttpException('Configuration text must be valid UTF-8.');
        }
        $value = strtr($value, ['\\' => '\\\\', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\f" => '\\f']);
        $leading = strlen($value) - strlen(ltrim($value, ' '));
        $value = str_repeat('\\ ', $leading) . substr($value, $leading);

        return preg_replace_callback('/[^\x20-\x7E]/u', function ($match) {
            $bytes = mb_convert_encoding($match[0], 'UTF-16BE', 'UTF-8');
            $escaped = '';
            foreach (unpack('n*', $bytes) as $code) {
                $escaped .= sprintf('\\u%04x', $code);
            }

            return $escaped;
        }, $value);
    }
}
