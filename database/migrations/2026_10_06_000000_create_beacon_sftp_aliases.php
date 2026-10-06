<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Older Beacon deployments may already contain the permanent reservation
        // ledger. Preserve it so that a previously issued username is never reused.
        if (!Schema::hasTable('beacon_sftp_alias_reservations')) {
            Schema::create('beacon_sftp_alias_reservations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('public_username', 64)->unique();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('beacon_sftp_aliases')) {
            Schema::create('beacon_sftp_aliases', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('server_id');
                $table->string('public_username', 64)->unique();
                $table->timestamps();

                $table->unique(['user_id', 'server_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('beacon_sftp_aliases');

        // Deliberately retain the permanent reservation ledger across rollbacks.
        // Reusing an old public username could point clients at a different identity.
    }
};
