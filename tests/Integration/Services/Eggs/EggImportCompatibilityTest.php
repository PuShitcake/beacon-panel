<?php

namespace Pterodactyl\Tests\Integration\Services\Eggs;

use Pterodactyl\Models\Egg;
use Pterodactyl\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\EggVariable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Services\Eggs\EggParserService;
use Pterodactyl\Exceptions\Model\DataValidationException;
use Pterodactyl\Services\Eggs\Sharing\EggImporterService;
use Pterodactyl\Exceptions\Service\InvalidFileUploadException;
use Pterodactyl\Services\Eggs\Sharing\EggUpdateImporterService;

/** Native parser/import/update and model validation on isolated SQLite only; no shared migrations. */
class EggImportCompatibilityTest extends TestCase
{
    private EggParserService $parser;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'egg_import_test');
        config()->set('database.connections.egg_import_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('nests', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('eggs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('nest_id');
            foreach (['uuid', 'name', 'author', 'description', 'features', 'file_denylist', 'docker_images', 'update_url',
                'startup', 'config_files', 'config_startup', 'config_logs', 'config_stop',
                'script_install', 'script_entry', 'script_container'] as $column) {
                $table->text($column)->nullable();
            }
            $table->unsignedInteger('copy_script_from')->nullable();
            $table->timestamps();
            $table->foreign('nest_id')->references('id')->on('nests');
        });
        Schema::create('egg_variables', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('egg_id');
            foreach (['name', 'description', 'env_variable', 'default_value', 'rules'] as $column) {
                $table->text($column);
            }
            $table->boolean('user_viewable');
            $table->boolean('user_editable');
            $table->timestamps();
            $table->unique(['egg_id', 'env_variable']);
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
        });
        DB::table('nests')->insert(['id' => 1, 'name' => 'Beacon']);
        $this->parser = new EggParserService();
    }

    protected function tearDown(): void
    {
        DB::purge('egg_import_test');
        parent::tearDown();
    }

    #[DataProvider('versions')]
    public function testNewerVariableMetadataImportsWithoutChangingRuntimeOrUploadedFile(string $version): void
    {
        $definition = $this->definition($version);
        $definition['variables'][0] += ['sort' => 0, 'field_type' => 'text', 'future_metadata' => ['value'],
            'id' => 999, 'egg_id' => 999, 'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00'];
        $upload = $this->upload($definition);
        $hash = hash_file('sha256', $upload->getPathname());
        $parsed = $this->parser->handle($upload);
        $this->assertSame($this->variable(), $parsed['variables'][0]);
        $expected = $definition;
        $expected['variables'] = [$this->variable()];
        if ($version === 'PTDL_v1') {
            unset($expected['image']);
            $expected['docker_images'] = [$definition['image'] => $definition['image']];
        }
        $this->assertSame($expected, $parsed);
        $egg = $this->importer()->handle($upload, 1);
        $this->assertRuntime($definition, $egg);
        $this->assertSame($hash, hash_file('sha256', $upload->getPathname()));
        $variable = $egg->variables()->sole();
        $this->assertSame($egg->id, $variable->egg_id);
        $this->assertNotSame(999, $variable->id);
        $this->assertNotSame('2099-01-01', $variable->created_at->toDateString());
        $expectedVariable = $this->variable();
        $storedVariable = array_intersect_key($variable->attributesToArray(), $expectedVariable);
        ksort($expectedVariable);
        ksort($storedVariable);
        $this->assertSame($expectedVariable, $storedVariable);
        $this->assertFalse(Schema::hasColumn('egg_variables', 'sort'));
        $this->assertFalse(Schema::hasColumn('egg_variables', 'field_type'));
    }

    #[DataProvider('versions')]
    public function testUpdateFiltersMetadataAndPreservesVariableIdentityAndNativeSynchronization(string $version): void
    {
        $old = $this->definition();
        $old['variables'][] = array_replace($this->variable(), ['name' => 'Old setting', 'env_variable' => 'OLD_SETTING']);
        $egg = $this->importer()->handle($this->upload($old), 1);
        $before = $egg->variables()->where('env_variable', 'VERSION')->sole();
        $new = $this->definition($version);
        $new['variables'][0]['default_value'] = '1.21.1';
        $new['variables'][0] += ['sort' => 5, 'field_type' => 'text', 'id' => 999, 'egg_id' => 999,
            'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00'];
        $new['variables'][] = array_replace($this->variable(), ['name' => 'Server label', 'env_variable' => 'SERVER_LABEL', 'sort' => 9, 'field_type' => 'text']);
        $updated = (new EggUpdateImporterService(DB::connection(), $this->parser))->handle($egg, $this->upload($new));
        $this->assertSame($egg->id, $updated->id);
        $this->assertSame($egg->uuid, $updated->uuid);
        $this->assertRuntime($new, $updated);
        $after = $updated->variables()->where('env_variable', 'VERSION')->sole();
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->created_at->toIso8601String(), $after->created_at->toIso8601String());
        $this->assertSame($egg->id, $after->egg_id);
        $this->assertSame('1.21.1', $after->default_value);
        $this->assertSame(['SERVER_LABEL', 'VERSION'], $updated->variables()->orderBy('env_variable')->pluck('env_variable')->all());
    }

    #[DataProvider('versions')]
    public function testImportsWithoutVariablesStillWork(string $version): void
    {
        $definition = $this->definition($version);
        unset($definition['variables']);
        $egg = $this->importer()->handle($this->upload($definition), 1);
        $this->assertCount(0, $egg->variables);
        $this->assertRuntime($definition, $egg);
    }

    public function testFailedVariableValidationRollsBackTheEntireImport(): void
    {
        $definition = $this->definition();
        $definition['variables'][] = array_replace($this->variable(), ['env_variable' => 'INVALID SPACE']);
        try {
            $this->importer()->handle($this->upload($definition), 1);
            $this->fail('Expected normal model validation.');
        } catch (DataValidationException) {
            $this->assertSame(0, Egg::query()->count());
            $this->assertSame(0, EggVariable::query()->count());
        }
    }

    public function testLegacyMultipleImagesRetainTheExistingConversion(): void
    {
        $definition = $this->definition('PTDL_v1');
        unset($definition['image']);
        $definition['images'] = ['ghcr.io/pterodactyl/yolks:debian', 'ghcr.io/pterodactyl/yolks:ubuntu'];
        $definition['variables'][0]['sort'] = 3;
        $parsed = $this->parser->handle($this->upload($definition));
        $this->assertSame(array_combine($definition['images'], $definition['images']), $parsed['docker_images']);
        $this->assertSame($this->variable(), $parsed['variables'][0]);
        $this->assertArrayNotHasKey('images', $parsed);
    }

    public function testFailedUpdateRestoresOriginalEggAndVariables(): void
    {
        $egg = $this->importer()->handle($this->upload($this->definition()), 1);
        $beforeEgg = $egg->fresh()->getRawOriginal();
        $beforeVariables = $egg->variables()->get()->map(fn ($variable) => $variable->getRawOriginal())->all();
        $definition = $this->definition();
        $definition['name'] = 'Changed name';
        $definition['variables'][0]['default_value'] = 'changed';
        $definition['variables'][] = array_replace($this->variable(), ['env_variable' => 'INVALID SPACE']);
        try {
            (new EggUpdateImporterService(DB::connection(), $this->parser))->handle($egg, $this->upload($definition));
            $this->fail('Expected normal model validation.');
        } catch (DataValidationException) {
            $this->assertSame($beforeEgg, $egg->fresh()->getRawOriginal());
            $this->assertSame($beforeVariables, $egg->variables()->get()->map(fn ($variable) => $variable->getRawOriginal())->all());
        }
    }

    #[DataProvider('malformedVariables')]
    public function testMalformedVariableContainersAreRejectedBeforePersistence(mixed $variables): void
    {
        $definition = $this->definition();
        $definition['variables'] = $variables;
        $this->expectException(InvalidFileUploadException::class);
        $this->importer()->handle($this->upload($definition), 1);
    }

    public static function versions(): array
    {
        return [['PTDL_v1'], ['PTDL_v2']];
    }

    public static function malformedVariables(): array
    {
        return [['not-an-array'], [[false]], [['not-a-definition']]];
    }

    private function importer(): EggImporterService
    {
        return new EggImporterService(DB::connection(), $this->parser);
    }

    private function upload(array $definition): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('egg-compatibility.json', json_encode($definition, JSON_THROW_ON_ERROR));
    }

    private function variable(): array
    {
        return ['name' => 'Version', 'description' => 'Game version', 'env_variable' => 'VERSION', 'default_value' => 'latest',
            'user_viewable' => true, 'user_editable' => true, 'rules' => 'required|string'];
    }

    private function definition(string $version = 'PTDL_v2'): array
    {
        $definition = ['meta' => ['version' => $version], 'name' => 'Import compatibility fixture', 'author' => 'test@example.com',
            'description' => 'Synthetic fixture; never runs an installation.', 'features' => [], 'file_denylist' => [],
            'docker_images' => ['Runtime' => 'ghcr.io/pterodactyl/yolks:debian'], 'startup' => './bedrock_server',
            'config' => ['files' => '{}', 'startup' => '{"done":"Server started"}', 'logs' => '{}', 'stop' => 'stop'],
            'scripts' => ['installation' => ['script' => "#!/bin/sh\nexit 0\n", 'entrypoint' => 'sh', 'container' => 'alpine:3.20']],
            'variables' => [$this->variable()]];
        if ($version === 'PTDL_v1') {
            unset($definition['docker_images']);
            $definition['image'] = 'ghcr.io/pterodactyl/yolks:debian';
        }

        return $definition;
    }

    private function assertRuntime(array $definition, Egg $egg): void
    {
        $this->assertSame($definition['startup'], $egg->startup);
        $this->assertSame($definition['scripts']['installation']['script'], $egg->script_install);
        $this->assertSame($definition['scripts']['installation']['container'], $egg->script_container);
        $this->assertSame($definition['scripts']['installation']['entrypoint'], $egg->script_entry);
        $this->assertSame($definition['config']['files'], $egg->config_files);
        $images = $definition['docker_images'] ?? [$definition['image'] => $definition['image']];
        $this->assertSame($images, $egg->docker_images);
    }
}
