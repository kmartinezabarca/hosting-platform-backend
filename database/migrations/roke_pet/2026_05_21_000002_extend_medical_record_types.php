<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        // Extiende el enum `type` para incluir nuevos tipos del rediseño del panel privado.
        DB::connection('roke_pet')->statement(
            "ALTER TABLE medical_records MODIFY type ENUM(
                'checkup','surgery','treatment','deworming','illness',
                'vaccination','study','emergency','other'
            ) NOT NULL"
        );
    }

    public function down(): void
    {
        DB::connection('roke_pet')->statement(
            "ALTER TABLE medical_records MODIFY type ENUM(
                'checkup','surgery','treatment','deworming','illness'
            ) NOT NULL"
        );
    }
};
