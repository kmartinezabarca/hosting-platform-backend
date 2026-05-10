<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true)->after('remember_token');
            $table->boolean('push_notifications')->default(true)->after('email_notifications');
            $table->boolean('service_notifications')->default(true)->after('push_notifications');
            $table->boolean('payment_notifications')->default(true)->after('service_notifications');
            $table->boolean('ticket_notifications')->default(true)->after('payment_notifications');
            $table->boolean('invoice_notifications')->default(true)->after('ticket_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_notifications',
                'push_notifications',
                'service_notifications',
                'payment_notifications',
                'ticket_notifications',
                'invoice_notifications',
            ]);
        });
    }
};
