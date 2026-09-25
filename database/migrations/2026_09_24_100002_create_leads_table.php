<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('condition_type', 20)->default('any');
            $table->string('condition_value', 191)->nullable();
            $table->string('assignment_type', 30);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->json('user_pool_json')->nullable();
            $table->foreignId('last_assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'priority']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('lead_number', 30)->unique();

            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('full_name', 201)->index();

            $table->string('email', 191)->nullable()->index();
            $table->string('phone', 30)->nullable()->index();
            $table->string('normalized_phone', 20)->nullable()->index();
            $table->string('alternate_phone', 30)->nullable();
            $table->string('normalized_alternate_phone', 20)->nullable()->index();

            $table->string('company_name', 191)->nullable()->index();
            $table->string('designation', 100)->nullable();

            $table->foreignId('source_id')->constrained('lead_sources')->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();

            $table->string('facebook_lead_id', 64)->nullable()->unique();
            $table->string('facebook_form_id', 64)->nullable();
            $table->string('facebook_page_id', 64)->nullable();
            $table->string('facebook_ad_id', 64)->nullable();
            $table->string('facebook_adset_id', 64)->nullable();
            $table->string('facebook_campaign_id', 64)->nullable();

            $table->foreignId('assigned_to')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->index()->constrained('teams')->nullOnDelete();

            $table->foreignId('status_id')->index()->constrained('lead_statuses')->restrictOnDelete();
            $table->string('priority', 10)->default('medium')->index();

            $table->string('city', 100)->nullable()->index();
            $table->string('state', 100)->nullable()->index();
            $table->string('country', 100)->nullable();
            $table->string('pincode', 20)->nullable();

            $table->decimal('estimated_value', 14, 2)->nullable();

            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('next_followup_at')->nullable()->index();

            $table->timestamp('converted_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->foreignId('lost_reason_id')->nullable()->constrained('lost_reasons')->nullOnDelete();
            $table->text('lost_reason_notes')->nullable();

            $table->boolean('is_duplicate')->default(false)->index();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index(['assigned_to', 'status_id']);
            $table->index(['team_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_assignment_rules');
    }
};
