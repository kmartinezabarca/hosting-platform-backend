Schema::create('blog_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string("email")->unique();
            $table->boolean("is_active")->default(true);
            $table->index(['uuid']);
            $table->timestamps();
        });