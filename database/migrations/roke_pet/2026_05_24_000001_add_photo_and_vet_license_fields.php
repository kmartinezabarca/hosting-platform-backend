<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        // vaccines — cédula profesional del vet + foto de la etiqueta
        Schema::connection('roke_pet')->table('vaccines', function (Blueprint $table) {
            $table->string('vet_license')->nullable()->after('applied_by');
            $table->string('label_photo')->nullable()->after('batch_number');
        });

        // medical_records — cédula profesional + foto de la consulta
        Schema::connection('roke_pet')->table('medical_records', function (Blueprint $table) {
            $table->string('vet_license')->nullable()->after('vet');
            $table->string('photo_url')->nullable()->after('notes');
        });

        // weight_history — foto del peso / ticket
        Schema::connection('roke_pet')->table('weight_history', function (Blueprint $table) {
            $table->string('photo_url')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('vaccines', function (Blueprint $table) {
            $table->dropColumn(['vet_license', 'label_photo']);
        });
        Schema::connection('roke_pet')->table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['vet_license', 'photo_url']);
        });
        Schema::connection('roke_pet')->table('weight_history', function (Blueprint $table) {
            $table->dropColumn('photo_url');
        });
    }
};
