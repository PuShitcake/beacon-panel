<?php

namespace Pterodactyl\Beacon\Addons;

/** Names are sent only to native file APIs, never interpolated into a shell command. */
final class AddonPath
{
    public static function name(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 200 && $name !== ''
            && $name !== '.' && $name !== '..' && rtrim($name, ' .') === $name
            && preg_match("/^[A-Za-z0-9 _.,@()+'\\[\\]-]+$/D", $name) === 1;
    }

    public static function archive(mixed $path): bool
    {
        if (!is_string($path) || strlen($path) > 200) {
            return false;
        }
        // Remove one directory marker, not leading slashes or repeated separators.
        $relative = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        foreach (explode('/', $relative) as $part) {
            if (!self::name($part)) {
                return false;
            }
        }

        return true;
    }
}
