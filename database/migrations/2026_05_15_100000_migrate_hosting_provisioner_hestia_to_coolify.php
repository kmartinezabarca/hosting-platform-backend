<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_plans')
            ->where('provisioner', 'hestia')
            ->update(['provisioner' => 'coolify']);
    }

    public function down(): void
    {
        DB::table('service_plans')
            ->where('provisioner', 'coolify')
            ->update(['provisioner' => 'hestia']);
    }
};
