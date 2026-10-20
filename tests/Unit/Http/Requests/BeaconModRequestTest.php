<?php

namespace Pterodactyl\Tests\Unit\Http\Requests;

use Pterodactyl\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconModRequest;

class BeaconModRequestTest extends TestCase
{
    public static function providerCategories(): array
    {
        return [
            [[], true], [['provider' => 'curseforge', 'category' => '412'], true],
            [['provider' => 'modrinth', 'category' => 'technology'], true],
            [['provider' => 'curseforge', 'category' => 'technology'], false],
            [['provider' => 'modrinth', 'category' => '412'], false],
            [['provider' => 'other'], false], [['provider' => ['curseforge']], false],
            [['provider' => 'curseforge', 'category' => '../412'], false],
            [['provider' => 'curseforge', 'category' => []], false],
        ];
    }

    #[DataProvider('providerCategories')]
    public function testProviderAndCategoryValidation(array $input, bool $valid): void
    {
        $request = new BeaconModRequest($input);
        $validator = app('validator')->make($input, $request->rules());
        $this->assertSame($valid, $validator->passes());
    }
}
