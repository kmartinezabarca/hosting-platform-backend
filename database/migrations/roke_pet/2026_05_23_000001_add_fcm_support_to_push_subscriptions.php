<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds FCM (Firebase Cloud Messaging) token support alongside
 * the existing Web Push (VAPID) subscriptions.
 *
 * For FCM records:
 *   endpoint  = FCM device token (used as unique key)
 *   p256dh    = null
 *   auth      = null
 *   type      = 'fcm'
 *
 * For Web Push records (existing):
 *   endpoint  = push service URL
 *   p256dh    = ECDH public key
 *   auth      = authentication secret
 *   type      = 'webpush'
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('push_subscriptions', function (Blueprint $table) {
            // Subscription type: 'webpush' | 'fcm'
            $table->string('type', 16)->default('webpush')->after('owner_id');

            // Make p256dh and auth nullable (required for webpush, null for fcm)
            $table->string('p256dh')->nullable()->change();
            $table->string('auth')->nullable()->change();

            // Allow endpoint to hold FCM tokens (which can be longer than a URL)
            // We change to 512 chars and drop the unique constraint, then re-add
            // (endpoint stays as the unique identifier for both types)
        });

        // Extend endpoint column length for FCM tokens (can be ~160 chars)
        Schema::connection('roke_pet')->table('push_subscriptions', function (Blueprint $table) {
            $table->string('endpoint', 512)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->string('p256dh')->nullable(false)->change();
            $table->string('auth')->nullable(false)->change();
            $table->string('endpoint', 500)->change();
        });
    }
};
