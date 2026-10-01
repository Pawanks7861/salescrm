<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('priority_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('message');
            $table->string('priority', 20)->default('urgent');
            $table->foreignId('sent_by')->constrained('users');
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('priority_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('priority_broadcasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['broadcast_id', 'user_id']);
            $table->index(['user_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('priority_broadcast_recipients');
        Schema::dropIfExists('priority_broadcasts');
    }
};
