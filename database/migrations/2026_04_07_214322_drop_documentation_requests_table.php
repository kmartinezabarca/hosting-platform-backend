Schema::create('documentation_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string("name");
            $table->string("email");
            $table->string("topic")->nullable();
            $table->text("description")->nullable();
            $table->enum("kind", ["documentation", "api_documentation"]);
            $table->boolean("is_resolved")->default(false);
            $table->index(['uuid']);
            $table->timestamps();
        });