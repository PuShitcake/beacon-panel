<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('beacon_modpack_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->string('provider', 32);
            $table->string('project_id', 191);
            $table->string('project_slug', 191)->nullable();
            $table->string('name', 191);
            $table->string('icon_url', 2048)->nullable();
            $table->string('version_id', 191);
            $table->string('version_name', 191);
            $table->string('minecraft_version', 32);
            $table->string('loader', 32);
            $table->string('loader_version', 64)->nullable();
            $table->string('status', 32)->default('installed');
            $table->uuid('safety_backup_uuid');
            $table->json('original_runtime');
            $table->json('manifest');
            $table->timestamp('installed_at');
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('operation_id')->references('id')->on('beacon_operations')->nullOnDelete();
            $table->index(['provider', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beacon_modpack_installations');
    }
};
