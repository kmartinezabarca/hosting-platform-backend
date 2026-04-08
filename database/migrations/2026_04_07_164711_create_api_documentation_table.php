Schema::create('api_documentation', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string("title");
            $table->string("slug")->unique();
            $table->longText("content");
            $table->string("category")->nullable();
            $table->boolean("is_published")->default(false);
            $table->index(['uuid']);
            $table->timestamps();
        });