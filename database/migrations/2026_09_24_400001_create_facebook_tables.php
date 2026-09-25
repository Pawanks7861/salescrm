<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Meta Lead Ads. Additive only: new tables plus nullable columns and
 * indexes on existing tables. No existing column is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->default('Meta Lead Ads');
            $table->string('app_id', 64)->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->string('token_type', 20)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('data_access_expires_at')->nullable();
            $table->string('facebook_user_id', 64)->nullable();
            $table->string('facebook_user_name', 191)->nullable();
            $table->string('facebook_business_id', 64)->nullable();
            $table->string('graph_version', 10);
            $table->json('granted_scopes_json')->nullable();
            $table->json('missing_scopes_json')->nullable();
            $table->string('status', 30)->default('disconnected')->index();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('last_lead_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('facebook_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_integration_id')->constrained()->restrictOnDelete();
            $table->string('page_id', 64);
            $table->string('page_name', 191);
            $table->text('page_access_token_encrypted')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('picture_url', 500)->nullable();
            $table->json('tasks_json')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->boolean('is_subscribed')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('subscription_error', 300)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_subscription_check_at')->nullable();
            $table->timestamps();

            $table->unique(['facebook_integration_id', 'page_id']);
            $table->index('page_id');
        });

        Schema::create('facebook_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_page_id')->constrained()->restrictOnDelete();
            $table->string('form_id', 64);
            $table->string('form_name', 191);
            $table->string('status', 30)->nullable();
            $table->string('locale', 20)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->json('questions_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_lead_at')->nullable();
            $table->timestamps();

            $table->unique(['facebook_page_id', 'form_id']);
            $table->index('form_id');
        });

        Schema::create('facebook_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_form_id')->constrained()->cascadeOnDelete();
            $table->string('meta_field', 100);
            $table->string('meta_label', 191)->nullable();
            $table->string('target_type', 20);
            $table->string('lead_field', 50)->nullable();
            $table->foreignId('lead_custom_field_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['facebook_form_id', 'meta_field']);
        });

        // One row per Meta lead (leadgen_id): the idempotency ledger shared by
        // webhook delivery, manual sync and local test ingestion.
        Schema::create('facebook_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('leadgen_id', 64)->unique();
            $table->string('event_type', 30)->default('leadgen');
            $table->string('origin', 20)->default('webhook');
            $table->string('page_id', 64)->nullable()->index();
            $table->string('form_id', 64)->nullable()->index();
            $table->string('ad_id', 64)->nullable();
            $table->foreignId('facebook_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('facebook_form_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload_json')->nullable();
            $table->timestamp('meta_created_at')->nullable();
            $table->timestamp('received_at');
            $table->unsignedInteger('delivery_count')->default(1);
            $table->timestamp('last_delivered_at')->nullable();
            $table->string('processing_status', 20)->default('received');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_category', 30)->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_enquiry_id')->nullable()->constrained('lead_enquiries')->nullOnDelete();
            $table->timestamps();

            $table->index(['processing_status', 'received_at']);
            $table->index('received_at');
        });

        Schema::table('lead_enquiries', function (Blueprint $table) {
            $table->string('channel', 30)->nullable()->after('campaign_id');
            $table->json('metadata_json')->nullable()->after('enquiry_data_json');
            $table->unique(['channel', 'external_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->index('facebook_form_id');
            $table->index('facebook_page_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['facebook_form_id']);
            $table->dropIndex(['facebook_page_id']);
        });

        Schema::table('lead_enquiries', function (Blueprint $table) {
            $table->dropUnique(['channel', 'external_id']);
            $table->dropColumn(['channel', 'metadata_json']);
        });

        Schema::dropIfExists('facebook_webhook_events');
        Schema::dropIfExists('facebook_field_mappings');
        Schema::dropIfExists('facebook_forms');
        Schema::dropIfExists('facebook_pages');
        Schema::dropIfExists('facebook_integrations');
    }
};
