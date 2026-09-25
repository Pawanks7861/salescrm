<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1 (additive only): browser Web Push subscriptions (one row per
 * browser/device, many per user) and per-user notification preferences.
 * Endpoint and keys are stored encrypted; endpoint_hash allows lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('browser_notifications_enabled')->default(false)->after('is_active');
            $table->boolean('notification_sound_enabled')->default(true)->after('browser_notifications_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['browser_notifications_enabled', 'notification_sound_enabled']);
        });
        Schema::dropIfExists('push_subscriptions');
    }
};
