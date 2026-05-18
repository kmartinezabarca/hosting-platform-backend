<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_fiscal_profiles', function (Blueprint $table) {
            $table->renameColumn('razon_social', 'business_name');
            $table->renameColumn('codigo_postal', 'postal_code');
            $table->renameColumn('regimen_fiscal', 'fiscal_regime');
            $table->renameColumn('uso_cfdi', 'cfdi_use');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('uso_cfdi', 'cfdi_use');
        });
    }

    public function down(): void
    {
        Schema::table('customer_fiscal_profiles', function (Blueprint $table) {
            $table->renameColumn('business_name', 'razon_social');
            $table->renameColumn('postal_code', 'codigo_postal');
            $table->renameColumn('fiscal_regime', 'regimen_fiscal');
            $table->renameColumn('cfdi_use', 'uso_cfdi');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('cfdi_use', 'uso_cfdi');
        });
    }
};
