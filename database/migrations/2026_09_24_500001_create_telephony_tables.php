<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephony_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique();
            $table->string('name', 100);
            // Non-secret provider options (encrypted at rest anyway). Credentials stay in the environment.
            $table->text('configuration_encrypted')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('browser_calling_enabled')->default(false);
            $table->boolean('pstn_calling_enabled')->default(true);
            $table->boolean('recording_enabled')->default(true);
            $table->string('default_calling_mode', 10)->default('pstn');
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status', 20)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_callback_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('telephony_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('telephony_integrations')->restrictOnDelete();
            $table->string('provider_number_id', 100)->nullable();
            $table->string('phone_number', 30);
            $table->string('normalized_number', 20)->index();
            $table->string('display_name', 100);
            $table->string('number_type', 20)->default('virtual');
            $table->boolean('supports_inbound')->default(true);
            $table->boolean('supports_outbound')->default(true);
            $table->boolean('supports_webrtc')->default(false);
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'normalized_number']);
        });

        Schema::create('telephony_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('integration_id')->constrained('telephony_integrations')->restrictOnDelete();
            $table->string('provider_user_id', 100)->nullable();
            $table->string('provider_agent_id', 100)->nullable();
            $table->string('provider_sip_username', 150)->nullable();
            $table->string('registered_phone', 30)->nullable();
            $table->string('registered_phone_normalized', 20)->nullable()->index();
            $table->string('calling_mode', 10)->default('pstn');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['integration_id', 'user_id']);
            $table->index(['integration_id', 'provider_user_id']);
        });

        Schema::create('call_dispositions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('color', 20)->default('slate');
            $table->boolean('is_contact')->default(true);
            $table->boolean('requires_note')->default(false);
            $table->boolean('requires_next_action')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_number', 30)->unique();
            // Correlation reference handed to the provider (CustomField) and the softphone.
            $table->uuid('client_reference')->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();

            // Historical: never rewritten when the lead is reassigned or the agent changes team.
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->foreignId('integration_id')->nullable()->constrained('telephony_integrations')->nullOnDelete();
            $table->foreignId('telephony_number_id')->nullable()->constrained('telephony_numbers')->nullOnDelete();
            $table->string('provider', 30);
            $table->string('provider_call_id', 100)->nullable();
            $table->string('provider_status', 40)->nullable();

            $table->string('direction', 10);
            $table->string('channel', 10);
            $table->string('contact_field', 20)->nullable();

            $table->string('from_number', 30)->nullable();
            $table->string('from_number_normalized', 20)->nullable();
            $table->string('to_number', 30)->nullable();
            $table->string('to_number_normalized', 20)->nullable();
            $table->string('customer_number_normalized', 20)->nullable()->index();
            $table->string('virtual_number', 30)->nullable();

            $table->string('status', 20)->default('initiated');

            $table->timestamp('started_at');
            $table->timestamp('ringing_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('ring_duration_seconds')->nullable();
            $table->unsignedInteger('talk_duration_seconds')->nullable();
            $table->unsignedInteger('total_duration_seconds')->nullable();

            $table->boolean('requires_disposition')->default(false);
            $table->foreignId('disposition_id')->nullable()->constrained('call_dispositions')->restrictOnDelete();
            $table->timestamp('disposition_at')->nullable();
            $table->foreignId('disposition_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('notes_updated_at')->nullable();
            $table->foreignId('notes_updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('next_action', 20)->nullable();
            $table->foreignId('followup_id')->nullable()->constrained('followups')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();

            $table->string('failure_code', 50)->nullable();
            $table->string('failure_reason', 255)->nullable();

            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedSmallInteger('reconcile_attempts')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'provider_call_id']);
            $table->index('status');
            $table->index('direction');
            $table->index('started_at');
            $table->index('answered_at');
            $table->index('disposition_id');
            $table->index(['lead_id', 'started_at']);
            $table->index(['agent_user_id', 'started_at']);
            $table->index(['team_id', 'started_at']);
            $table->index(['agent_user_id', 'requires_disposition', 'disposition_id']);
            $table->index(['status', 'started_at']);
        });

        Schema::create('call_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('provider_call_id', 100)->nullable()->index();
            $table->string('provider_event_id', 100)->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->string('event_type', 40);
            $table->string('provider_status', 40)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->string('processing_status', 20)->default('received')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            // Sanitised provider payload, encrypted at rest (contains phone numbers).
            $table->text('payload_encrypted')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['call_id', 'received_at']);
        });

        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider_recording_id', 100)->nullable();
            $table->string('storage_type', 20)->default('provider');
            // Provider recording URL, encrypted; never sent to the browser.
            $table->text('provider_reference_encrypted')->nullable();
            $table->string('disk', 50)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
        Schema::dropIfExists('call_events');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('call_dispositions');
        Schema::dropIfExists('telephony_users');
        Schema::dropIfExists('telephony_numbers');
        Schema::dropIfExists('telephony_integrations');
    }
};
