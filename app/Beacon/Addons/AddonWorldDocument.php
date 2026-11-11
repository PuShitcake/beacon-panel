<?php

namespace Pterodactyl\Beacon\Addons;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Preserves existing entries and order; never writes level.dat, experiments or server.properties. */
class AddonWorldDocument
{
    public function parse(?string $content): array
    {
        if ($content === null) {
            return [];
        }
        try {
            $items = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConflictHttpException('Review the existing world pack JSON before installing add-ons.');
        }
        if (!is_array($items) || !array_is_list($items) || count($items) > 256) {
            throw new ConflictHttpException('The world pack list is invalid or too large.');
        }
        $seen = [];
        $schema = new AddonArchive();
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new ConflictHttpException('The world pack entry is invalid.');
            }
            $uuid = $schema->uuid($item['pack_id'] ?? null);
            $schema->version($item['version'] ?? null);
            if (isset($seen[$uuid])) {
                throw new ConflictHttpException('The world contains duplicate pack UUIDs. Review its pack list first.');
            }
            $seen[$uuid] = true;
        }

        return $items;
    }

    public function change(?string $content, array $packs, string $directory, bool $remove): string
    {
        $items = $this->parse($content);
        foreach ($packs as $pack) {
            if ($pack['directory'] !== $directory) {
                continue;
            }
            $index = array_search($pack['uuid'], array_map(fn ($p) => strtolower($p['pack_id']), $items), true);
            if ($remove) {
                if ($index === false || $items[$index]['version'] !== $pack['version']) {
                    throw new ConflictHttpException('The managed world pack entry changed. Review it before removing this add-on.');
                }
                unset($items[$index]);
                $items = array_values($items);
            } else {
                if ($index !== false) {
                    throw new ConflictHttpException('A pack with this UUID is already enabled in the selected world.');
                }
                $items[] = ['pack_id' => $pack['uuid'], 'version' => $pack['version']];
            }
        }

        return json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
