<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── service_plans ────────────────────────────────────────────────────
        Schema::table('service_plans', function (Blueprint $table) {
            $table->enum('provisioner', ['none', 'pterodactyl', 'manual'])
                  ->default('none')
                  ->after('sort_order');

            $table->unsignedInteger('pterodactyl_nest_id')->nullable()->after('provisioner');
            $table->unsignedInteger('pterodactyl_egg_id')->nullable()->after('pterodactyl_nest_id');
            // null = auto-select node with most free allocations
            $table->unsignedInteger('pterodactyl_node_id')->nullable()->after('pterodactyl_egg_id');

            // Resource limits (memory, swap, disk in MB; cpu in %; io weight)
            $table->json('pterodactyl_limits')->nullable()->after('pterodactyl_node_id');
            // Feature limits (databases, backups, allocations)
            $table->json('pterodactyl_feature_limits')->nullable()->after('pterodactyl_limits');
            // Per-plan env var overrides (merged on top of egg defaults)
            $table->json('pterodactyl_environment')->nullable()->after('pterodactyl_feature_limits');
            // Override the egg's Docker image
            $table->string('pterodactyl_docker_image')->nullable()->after('pterodactyl_environment');
            // Override the egg's startup command
            $table->text('pterodactyl_startup')->nullable()->after('pterodactyl_docker_image');

            $table->index('provisioner');
        });

        // ── services ────────────────────────────────────────────────────────
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('pterodactyl_server_id')->nullable()->after('external_id');
            $table->string('pterodactyl_server_uuid', 36)->nullable()->after('pterodactyl_server_id');
            // The Pterodactyl user account that owns/has access to this server
            $table->unsignedInteger('pterodactyl_user_id')->nullable()->after('pterodactyl_server_uuid');

            $table->index('pterodactyl_server_id');
        });

        // ── users ────────────────────────────────────────────────────────────
        if (!Schema::hasColumn('users', 'pterodactyl_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('pterodactyl_user_id')->nullable()->after('stripe_customer_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropIndex(['provisioner']);
            $table->dropColumn([
                'provisioner', 'pterodactyl_nest_id', 'pterodactyl_egg_id',
                'pterodactyl_node_id', 'pterodactyl_limits', 'pterodactyl_feature_limits',
                'pterodactyl_environment', 'pterodactyl_docker_image', 'pterodactyl_startup',
            ]);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['pterodactyl_server_id']);
            $table->dropColumn(['pterodactyl_server_id', 'pterodactyl_server_uuid', 'pterodactyl_user_id']);
        });

        if (Schema::hasColumn('users', 'pterodactyl_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pterodactyl_user_id');
            });
        }
    }
};
