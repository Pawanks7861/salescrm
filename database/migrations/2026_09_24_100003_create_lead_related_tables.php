<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('external_id', 100)->nullable()->index();
            $table->json('enquiry_data_json')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->timestamp('received_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'received_at']);
        });

        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('to_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignment_type', 20);
            $table->foreignId('rule_id')->nullable()->constrained('lead_assignment_rules')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'created_at']);
            $table->index('to_user_id');
        });

        Schema::create('lead_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('field_type', 20);
            $table->json('options_json')->nullable();
            $table->json('validation_rules_json')->nullable();
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lead_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_custom_field_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'lead_custom_field_id']);
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->text('note');
            $table->string('visibility', 20)->default('team');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('lead_note_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_note_id')->constrained()->cascadeOnDelete();
            $table->text('old_content');
            $table->text('new_content');
            $table->string('old_visibility', 20)->nullable();
            $table->string('new_visibility', 20)->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64)->index();
            $table->text('description');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['subject_type', 'subject_id', 'id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('original_name', 255);
            $table->string('stored_name', 100);
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('lead_note_histories');
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('lead_custom_field_values');
        Schema::dropIfExists('lead_custom_fields');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('lead_enquiries');
    }
};
