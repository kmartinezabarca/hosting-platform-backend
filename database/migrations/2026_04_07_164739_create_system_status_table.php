Schema::create('system_status', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string("service_name")->unique();
            $table->enum("status", ["operational", "degraded_performance", "partial_outage", "major_outage"])->default("operational");
            $table->text("message")->nullable();
            $table->timestamp("last_updated")->useCurrent();
            $table->index(['uuid']);
            $table->timestamps();
        });