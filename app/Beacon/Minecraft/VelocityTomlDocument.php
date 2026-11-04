<?php

namespace Pterodactyl\Beacon\Minecraft;

/**
 * Lossless editor for the scalar/string-array TOML emitted by Velocity. This is
 * deliberately NOT a general TOML parser: ambiguous or unsupported syntax fails
 * closed for the entire form, including dotted keys, inline and array tables,
 * multiline strings and nested arrays. Files remains available for those files.
 */
class VelocityTomlDocument
{
    private array $values = [];
    private array $spans = [];
    private int $offset = 0;

    public function __construct(private string $content)
    {
        if (strlen($content) > 262144 || !mb_check_encoding($content, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|\r(?!\n)/', $content)) {
            $this->invalid();
        }
        $section = '';
        $tables = [];
        while ($this->offset < strlen($content)) {
            $this->space(true);
            if ($this->offset >= strlen($content)) {
                break;
            }
            if ($content[$this->offset] === '[') {
                ++$this->offset;
                $this->space();
                $section = $this->key();
                $this->space();
                if (str_contains($section, '.') || ($content[$this->offset++] ?? '') !== ']' || isset($tables[$section]) || isset($this->values[$section])) {
                    $this->invalid();
                }
                $tables[$section] = true;
                $this->endLine();
                continue;
            }
            $key = $this->key();
            if ($section === '' && str_contains($key, '.')) {
                $this->invalid();
            }
            // Dots in quoted keys are valid (forced-hosts), but never an editable path.
            $path = $section === '' ? $key : $section . '.' . $key;
            $this->space();
            if (($content[$this->offset++] ?? '') !== '=' || array_key_exists($path, $this->values) || ($section === '' && isset($tables[$key]))) {
                $this->invalid();
            }
            $this->space();
            $start = $this->offset;
            $this->values[$path] = $this->value();
            $this->spans[$path] = [$start, $this->offset - $start];
            $this->endLine();
        }
    }

    public function values(): array
    {
        return $this->values;
    }

    public function patch(array $changes): string
    {
        $replacements = [];
        foreach ($changes as $key => $value) {
            if (!isset($this->spans[$key]) || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                $this->invalid();
            }
            // JSON's string escapes are a safe subset of TOML basic-string escapes.
            $replacements[] = [...$this->spans[$key], json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        }
        usort($replacements, fn ($a, $b) => $b[0] <=> $a[0]);
        $result = $this->content;
        foreach ($replacements as [$start, $length, $value]) {
            $result = substr_replace($result, $value, $start, $length);
        }

        return $result;
    }

    private function key(): string
    {
        $char = $this->content[$this->offset] ?? '';
        if ($char === '"' || $char === "'") {
            return $this->quoted();
        }
        if (!preg_match('/\G[A-Za-z0-9_-]+/', $this->content, $matches, 0, $this->offset)) {
            $this->invalid();
        }
        $this->offset += strlen($matches[0]);

        return $matches[0];
    }

    private function quoted(): string
    {
        $quote = $this->content[$this->offset++];
        $result = '';
        while ($this->offset < strlen($this->content)) {
            $char = $this->content[$this->offset++];
            if ($char === $quote) {
                return $result;
            }
            if ($char === "\n" || $char === "\r") {
                $this->invalid();
            }
            if ($char === '\\' && $quote === '"') {
                $escape = $this->content[$this->offset++] ?? '';
                $map = ['b' => "\x08", 't' => "\t", 'n' => "\n", 'f' => "\x0c", 'r' => "\r", '"' => '"', '\\' => '\\'];
                if (array_key_exists($escape, $map)) {
                    $result .= $map[$escape];
                    continue;
                }
                $count = match ($escape) {
                    'u' => 4, 'U' => 8, default => 0,
                };
                $hex = substr($this->content, $this->offset, $count);
                if (!$count || strlen($hex) !== $count || !ctype_xdigit($hex)) {
                    $this->invalid();
                }
                $code = hexdec($hex);
                if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    $this->invalid();
                }
                $result .= mb_chr($code, 'UTF-8');
                $this->offset += $count;
            } else {
                $result .= $char;
            }
        }
        $this->invalid();
    }

    private function value(bool $inArray = false): mixed
    {
        $char = $this->content[$this->offset] ?? '';
        if ($char === '"' || $char === "'") {
            return $this->quoted();
        }
        if ($char === '[' && !$inArray) {
            ++$this->offset;
            $values = [];
            $this->space(true);
            while (($this->content[$this->offset] ?? '') !== ']') {
                if (count($values) >= 1024) {
                    $this->invalid();
                }
                $values[] = $this->value(true);
                $this->space(true);
                if (($this->content[$this->offset] ?? '') === ']') {
                    break;
                }
                if (($this->content[$this->offset++] ?? '') !== ',') {
                    $this->invalid();
                }
                $this->space(true);
            }
            ++$this->offset;

            return $values;
        }
        if (!preg_match('/\G(?:true|false|-?(?:0|[1-9][0-9]{0,8}))(?=[\s,#\]]|$)/', $this->content, $m, 0, $this->offset)) {
            $this->invalid();
        }
        $this->offset += strlen($m[0]);

        return match ($m[0]) {
            'true' => true, 'false' => false, default => (int) $m[0],
        };
    }

    private function space(bool $lines = false): void
    {
        while ($this->offset < strlen($this->content)) {
            $char = $this->content[$this->offset];
            if ($char === ' ' || $char === "\t" || ($lines && ($char === "\r" || $char === "\n"))) {
                ++$this->offset;
            } elseif ($lines && $char === '#') {
                $this->offset = strpos($this->content, "\n", $this->offset) ?: strlen($this->content);
            } else {
                break;
            }
        }
    }

    private function endLine(): void
    {
        $this->space();
        if (($this->content[$this->offset] ?? '') === '#') {
            $this->offset = strpos($this->content, "\n", $this->offset) ?: strlen($this->content);
        }
        if ($this->offset < strlen($this->content) && !in_array($this->content[$this->offset], ["\r", "\n"], true)) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new \InvalidArgumentException('Unsupported or ambiguous Velocity TOML.');
    }
}
