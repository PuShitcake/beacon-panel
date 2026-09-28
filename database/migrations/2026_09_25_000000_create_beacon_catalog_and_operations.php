<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('beacon_catalog_applications', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('beacon_catalog_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('application_id');
            $table->unsignedInteger('egg_id');
            $table->string('code', 64);
            $table->string('name');
            $table->string('loader', 32);
            $table->string('content_directory', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['application_id', 'code']);
            $table->foreign('application_id')->references('id')->on('beacon_catalog_applications')->cascadeOnDelete();
            $table->foreign('egg_id')->references('id')->on('eggs')->restrictOnDelete();
        });

        Schema::create('beacon_catalog_versions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('profile_id');
            $table->string('version', 64);
            $table->string('loader_version', 64)->default('');
            $table->string('docker_image')->nullable();
            $table->text('startup')->nullable();
            $table->json('environment');
            $table->boolean('enabled')->default(true);
            $table->boolean('deprecated')->default(false);
            $table->timestamps();
            $table->unique(['profile_id', 'version', 'loader_version']);
            $table->foreign('profile_id')->references('id')->on('beacon_catalog_profiles')->cascadeOnDelete();
        });

        Schema::create('beacon_resource_presets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->unsignedInteger('memory');
            $table->integer('swap')->default(0);
            $table->unsignedInteger('disk');
            $table->unsignedSmallInteger('io')->default(500);
            $table->unsignedInteger('cpu')->default(0);
            $table->string('threads')->nullable();
            $table->unsignedSmallInteger('database_limit')->default(0);
            $table->unsignedSmallInteger('allocation_limit')->default(0);
            $table->unsignedSmallInteger('backup_limit')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('beacon_server_metadata', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('application_id');
            $table->unsignedInteger('profile_id');
            $table->unsignedInteger('version_id');
            $table->unsignedInteger('preset_id');
            $table->timestamps();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('application_id')->references('id')->on('beacon_catalog_applications')->restrictOnDelete();
            $table->foreign('profile_id')->references('id')->on('beacon_catalog_profiles')->restrictOnDelete();
            $table->foreign('version_id')->references('id')->on('beacon_catalog_versions')->restrictOnDelete();
            $table->foreign('preset_id')->references('id')->on('beacon_resource_presets')->restrictOnDelete();
        });

        Schema::create('beacon_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('api_key_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('server_id')->nullable();
            $table->string('actor_key', 191);
            $table->string('type', 64);
            $table->string('status', 32)->index();
            $table->uuid('correlation_id')->index();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->json('payload');
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_key', 'idempotency_key']);
            $table->foreign('api_key_id')->references('id')->on('api_keys')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
        });

        Schema::create('beacon_content_installations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->string('provider', 32);
            $table->string('project_id', 64);
            $table->string('version_id', 64);
            $table->string('project_type', 32);
            $table->string('loader', 32);
            $table->string('game_version', 64);
            $table->string('destination', 32);
            $table->string('filename');
            $table->string('sha512', 128);
            $table->unsignedBigInteger('size');
            $table->json('dependencies');
            $table->string('status', 32)->index();
            $table->timestamps();
            $table->unique(
                ['server_id', 'provider', 'project_id'],
                'beacon_content_server_provider_project_unique'
            );
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('operation_id')->references('id')->on('beacon_operations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beacon_content_installations');
        Schema::dropIfExists('beacon_operations');
        Schema::dropIfExists('beacon_server_metadata');
        Schema::dropIfExists('beacon_resource_presets');
        Schema::dropIfExists('beacon_catalog_versions');
        Schema::dropIfExists('beacon_catalog_profiles');
        Schema::dropIfExists('beacon_catalog_applications');
    }
};
