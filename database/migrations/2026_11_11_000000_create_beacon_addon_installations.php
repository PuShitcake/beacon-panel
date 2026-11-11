<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('beacon_addon_installations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->string('world', 64);
            $table->string('provider', 16);
            $table->string('project_id', 64)->nullable();
            $table->string('version_id', 64)->nullable();
            $table->string('archive_id', 64);
            $table->json('manifest');
            $table->timestamps();
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->foreign('operation_id')->references('id')->on('beacon_operations')->nullOnDelete();
            $table->unique(['server_id', 'world', 'archive_id'], 'beacon_addon_world_archive_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beacon_addon_installations');
    }
};
