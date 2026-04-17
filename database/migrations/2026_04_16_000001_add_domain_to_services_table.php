<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Dominio/hostname asociado al servicio (hosting, VPS, dominio)
            $table->string('domain')->nullable()->after('name');
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropColumn('domain');
        });
    }
};
