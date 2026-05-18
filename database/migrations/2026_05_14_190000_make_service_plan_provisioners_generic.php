<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_plans', 'provisioner')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE service_plans MODIFY provisioner VARCHAR(50) NULL DEFAULT NULL');
            }

            DB::table('service_plans')
                ->where('provisioner', 'none')
                ->update(['provisioner' => null]);
        }

        $hasProvisionerConfig = Schema::hasColumn('service_plans', 'provisioner_config');

        Schema::table('service_plans', function (Blueprint $table) use ($hasProvisionerConfig) {
            if (! $hasProvisionerConfig) {
                $table->json('provisioner_config')->nullable()->after('provisioner');
            }
        });
    }

    public function down(): void
    {
        $hasProvisionerConfig = Schema::hasColumn('service_plans', 'provisioner_config');

        Schema::table('service_plans', function (Blueprint $table) use ($hasProvisionerConfig) {
            if ($hasProvisionerConfig) {
                $table->dropColumn('provisioner_config');
            }
        });

        if (Schema::hasColumn('service_plans', 'provisioner') && DB::getDriverName() === 'mysql') {
            DB::table('service_plans')
                ->whereNull('provisioner')
                ->update(['provisioner' => 'none']);
            DB::statement("ALTER TABLE service_plans MODIFY provisioner ENUM('none','pterodactyl','hestia','manual') NOT NULL DEFAULT 'none'");
        }
    }
};
