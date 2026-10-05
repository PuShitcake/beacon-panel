<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('beacon_content_installations', function (Blueprint $table) {
            $table->string('project_name')->nullable()->after('project_id');
            $table->string('version_number')->nullable()->after('version_id');
            $table->string('icon_url', 2048)->nullable()->after('version_number');
            $table->boolean('is_dependency')->default(false)->after('dependencies')->index();
            $table->string('disabled_path')->nullable()->after('is_dependency');
        });
    }

    public function down(): void
    {
        Schema::table('beacon_content_installations', function (Blueprint $table) {
            $table->dropIndex(['is_dependency']);
            $table->dropColumn(['project_name', 'version_number', 'icon_url', 'is_dependency', 'disabled_path']);
        });
    }
};
