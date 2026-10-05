<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('beacon_minecraft_services', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('rcon_allocation_id')->nullable()->unique();
            $table->unsignedInteger('query_allocation_id')->nullable()->unique();
            $table->text('rcon_password')->nullable();
            $table->boolean('rcon_enabled')->default(false);
            $table->boolean('query_enabled')->default(false);
            $table->unsignedBigInteger('last_operation_id')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('rcon_allocation_id')->references('id')->on('allocations')->nullOnDelete();
            $table->foreign('query_allocation_id')->references('id')->on('allocations')->nullOnDelete();
            $table->foreign('last_operation_id')->references('id')->on('beacon_operations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beacon_minecraft_services');
    }
};
