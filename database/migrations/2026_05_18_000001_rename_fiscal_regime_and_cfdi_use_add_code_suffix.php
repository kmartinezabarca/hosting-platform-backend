<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_fiscal_profiles', function (Blueprint $table) {
            $table->renameColumn('fiscal_regime', 'fiscal_regime_code');
            $table->renameColumn('cfdi_use', 'cfdi_use_code');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('cfdi_use', 'cfdi_use_code');
        });
    }

    public function down(): void
    {
        Schema::table('customer_fiscal_profiles', function (Blueprint $table) {
            $table->renameColumn('fiscal_regime_code', 'fiscal_regime');
            $table->renameColumn('cfdi_use_code', 'cfdi_use');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('cfdi_use_code', 'cfdi_use');
        });
    }
};
