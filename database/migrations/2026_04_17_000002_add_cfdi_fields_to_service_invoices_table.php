<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el ciclo de vida del CFDI a service_invoices.
     *
     * Estados:
     *  - needs_info        → El cliente aún no ha proporcionado datos fiscales
     *  - scheduled         → Se timbrará como Público en General pasadas 72 h
     *  - pending_stamp     → Datos fiscales completos, pendiente de timbrado
     *  - stamped           → CFDI timbrado ante el SAT
     *  - failed            → El timbrado falló (ver cfdi_error)
     *  - cancelled         → CFDI cancelado ante el SAT
     */
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->enum('cfdi_status', [
                'needs_info',
                'scheduled',
                'pending_stamp',
                'stamped',
                'failed',
                'cancelled',
            ])->default('pending_stamp')->after('constancia');

            // Cuándo debe timbarse automáticamente (null = no aplica)
            $table->timestamp('stamp_scheduled_at')->nullable()->after('cfdi_status');

            // true = datos fiscales son los predeterminados de Público en General
            $table->boolean('is_publico_general')->default(false)->after('stamp_scheduled_at');

            // Campos del CFDI una vez timbrado
            $table->string('cfdi_uuid', 36)->nullable()->after('is_publico_general');   // UUID del SAT
            $table->longText('cfdi_xml')->nullable()->after('cfdi_uuid');               // XML timbrado
            $table->string('cfdi_pdf_path')->nullable()->after('cfdi_xml');             // Ruta al PDF
            $table->text('cfdi_error')->nullable()->after('cfdi_pdf_path');             // Mensaje de error si falló
            $table->timestamp('stamped_at')->nullable()->after('cfdi_error');           // Cuándo se timbró

            $table->index('cfdi_status');
            $table->index('stamp_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropIndex(['cfdi_status']);
            $table->dropIndex(['stamp_scheduled_at']);
            $table->dropColumn([
                'cfdi_status', 'stamp_scheduled_at', 'is_publico_general',
                'cfdi_uuid', 'cfdi_xml', 'cfdi_pdf_path', 'cfdi_error', 'stamped_at',
            ]);
        });
    }
};
