<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->default('slate');
            $table->string('location_mode', 20)->default('flexible');
            $table->unsignedSmallInteger('default_duration_minutes')->default(30);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_number', 30)->unique();
            $table->foreignId('lead_id')->nullable()->index()->constrained()->restrictOnDelete();

            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->text('agenda')->nullable();
            $table->foreignId('meeting_type_id')->index()->constrained('meeting_types')->restrictOnDelete();

            $table->foreignId('host_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->index()->constrained('teams')->nullOnDelete();

            $table->timestamp('start_at')->index();
            $table->timestamp('end_at')->index();
            $table->string('timezone', 64);

            $table->string('location_type', 20);
            $table->string('location', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('meeting_url', 500)->nullable();

            $table->string('status', 20)->default('scheduled')->index();
            $table->string('priority', 10)->default('medium');
            $table->json('reminder_offsets')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();

            $table->string('outcome', 30)->nullable();
            $table->text('outcome_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('rescheduled_from_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->text('reschedule_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index(['host_user_id', 'status', 'start_at']);
            $table->index(['team_id', 'status', 'start_at']);
            $table->index(['lead_id', 'status', 'start_at']);
            $table->index(['start_at', 'end_at']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('participant_type', 20);
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->index()->constrained('leads')->nullOnDelete();
            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('attendance_status', 20)->default('pending');
            $table->string('invitation_status', 20)->default('not_sent');
            $table->timestamps();

            $table->index(['meeting_id', 'participant_type']);
            $table->unique(['meeting_id', 'user_id']);
            $table->unique(['meeting_id', 'lead_id']);
        });

        Schema::create('meeting_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('minutes_before');
            $table->timestamp('remind_at');
            $table->string('channel', 20)->default('database');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'remind_at']);
            $table->unique(['meeting_id', 'user_id', 'channel', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reminders');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('meeting_types');
    }
};
