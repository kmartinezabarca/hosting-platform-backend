<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_status', function (Blueprint $table) {
            $table->string('region')->nullable()->after('service_name');
            $table->string('label')->nullable()->after('region');
            $table->decimal('coord_x', 5, 2)->nullable()->after('label')->comment('0-100 globe X percent');
            $table->decimal('coord_y', 5, 2)->nullable()->after('coord_x')->comment('0-100 globe Y percent');
            $table->unsignedTinyInteger('load_pct')->nullable()->after('coord_y');
            $table->boolean('is_primary')->default(false)->after('load_pct');
            $table->boolean('is_datacenter')->default(false)->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('system_status', function (Blueprint $table) {
            $table->dropColumn(['region', 'label', 'coord_x', 'coord_y', 'load_pct', 'is_primary', 'is_datacenter']);
        });
    }
};
