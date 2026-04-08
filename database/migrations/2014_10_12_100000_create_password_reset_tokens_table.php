Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->index(['uuid']);
        });