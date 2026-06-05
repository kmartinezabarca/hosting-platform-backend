<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_plans', 'provisioner') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE service_plans MODIFY provisioner ENUM('none','pterodactyl','hestia','manual') NOT NULL DEFAULT 'none'");
        }

        $hasHestiaPackage = Schema::hasColumn('service_plans', 'hestia_package');

        Schema::table('service_plans', function (Blueprint $table) use ($hasHestiaPackage) {
            if (! $hasHestiaPackage) {
                $table->string('hestia_package')->nullable()->after('provisioner');
            }
        });
    }

    public function down(): void
    {
        $hasHestiaPackage = Schema::hasColumn('service_plans', 'hestia_package');

        Schema::table('service_plans', function (Blueprint $table) use ($hasHestiaPackage) {
            if ($hasHestiaPackage) {
                $table->dropColumn('hestia_package');
            }
        });

        if (Schema::hasColumn('service_plans', 'provisioner') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE service_plans MODIFY provisioner ENUM('none','pterodactyl','manual') NOT NULL DEFAULT 'none'");
        }
    }
};
