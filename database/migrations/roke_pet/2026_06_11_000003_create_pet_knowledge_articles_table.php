<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base de conocimiento que alimenta a la IA de soporte (RAG por keywords v1).
 * Arquitectura abierta a embeddings/vector search en el futuro (columna keywords
 * + metadata json permiten añadir un índice vectorial sin migración destructiva).
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_knowledge_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('brand', 32)->default('roke_pet');
            $table->string('title', 200);
            $table->string('slug', 200);
            $table->string('excerpt', 400)->nullable();
            $table->longText('content');
            $table->string('category', 64)->nullable();
            $table->json('tags')->nullable();
            $table->text('keywords')->nullable();           // términos extra para el scoring de búsqueda
            $table->string('status', 16)->default('published'); // draft | published
            $table->timestamps();

            $table->unique(['brand', 'slug']);
            $table->index(['brand', 'status']);
            $table->index(['brand', 'status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_knowledge_articles');
    }
};
