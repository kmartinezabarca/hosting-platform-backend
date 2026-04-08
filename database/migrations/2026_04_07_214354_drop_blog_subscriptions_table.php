Schema::create('blog_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string("email")->unique();
            $table->index(['uuid']);
            $table->timestamps();
        });