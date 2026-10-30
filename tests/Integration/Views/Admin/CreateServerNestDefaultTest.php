<?php

namespace Pterodactyl\Tests\Integration\Views\Admin;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;

/** Render the actual Nest select without a database or external service. */
class CreateServerNestDefaultTest extends TestCase
{
    #[DataProvider('selections')]
    public function testDefaultAndPreviouslySubmittedNestSelections(array $nests, array $old, string $configuredName, ?string $expected): void
    {
        config()->set('beacon.modpacks.nest.name', $configuredName);
        $session = app('session')->driver('array');
        $session->put('_old_input', $old);
        app('request')->setLaravelSession($session);

        $source = file_get_contents(resource_path('views/admin/servers/new.blade.php'));
        $this->assertSame(1, preg_match('/<select id="pNestId"[^>]*>.*?<\/select>/s', $source, $match));
        $html = Blade::render($match[0], ['nests' => collect($nests)->map(fn ($nest) => (object) $nest)]);
        $document = new \DOMDocument();
        $document->loadHTML($html);
        $options = (new \DOMXPath($document))->query('//select[@id="pNestId"]/option');
        $selected = [];
        foreach ($options as $option) {
            if ($option->hasAttribute('selected')) {
                $selected[] = $option->getAttribute('value');
            }
        }
        $this->assertSame(count($nests), $options->length, 'Other Nests remain selectable.');
        $this->assertSame($expected === null ? [] : [$expected], $selected);
    }

    public static function selections(): array
    {
        $nests = [['id' => 1, 'name' => 'Minecraft'], ['id' => 2, 'name' => 'Source Engine'], ['id' => 5, 'name' => 'Beacon']];

        return [
            'default Beacon is not the first option' => [$nests, [], 'Beacon', '5'],
            'string old input takes priority' => [$nests, ['nest_id' => '2'], 'Beacon', '2'],
            'integer old input takes priority' => [$nests, ['nest_id' => 1], 'Beacon', '1'],
            'old Beacon stays selected' => [$nests, ['nest_id' => '5'], 'Beacon', '5'],
            'configured Beacon nest name' => [[['id' => 1, 'name' => 'Minecraft'], ['id' => 8, 'name' => 'Custom Beacon']], [], 'Custom Beacon', '8'],
            'no matching nest retains browser default' => [[['id' => 1, 'name' => 'Minecraft']], [], 'Beacon', null],
            'empty list' => [[], [], 'Beacon', null],
        ];
    }
}
