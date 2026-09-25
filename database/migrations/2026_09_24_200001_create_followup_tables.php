<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('followup_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->default('slate');
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->index()->constrained('teams')->nullOnDelete();
            $table->foreignId('followup_type_id')->constrained('followup_types')->restrictOnDelete();

            $table->string('title', 191)->nullable();
            $table->text('description')->nullable();

            $table->timestamp('scheduled_at')->index();
            $table->string('timezone', 64);
            $table->string('status', 20)->default('pending')->index();
            $table->string('priority', 10)->default('medium');

            $table->string('outcome', 30)->nullable();
            $table->text('notes')->nullable();
            $table->string('next_action', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('rescheduled_from_id')->nullable()->constrained('followups')->nullOnDelete();
            $table->text('reschedule_reason')->nullable();

            $table->unsignedInteger('reminder_minutes_before')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index(['assigned_to', 'status', 'scheduled_at']);
            $table->index(['team_id', 'status', 'scheduled_at']);
            $table->index(['lead_id', 'status', 'scheduled_at']);
        });

        Schema::create('followup_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('followup_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->timestamp('remind_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'remind_at']);
            $table->unique(['followup_id', 'kind', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('followup_reminders');
        Schema::dropIfExists('followups');
        Schema::dropIfExists('followup_types');
    }
};
