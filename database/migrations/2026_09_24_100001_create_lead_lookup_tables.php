<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description')->nullable();
            $table->string('color', 20)->default('slate');
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->unsignedTinyInteger('probability')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description')->nullable();
            $table->string('color', 20)->default('slate');
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('lost_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->string('platform', 30)->default('manual')->index();
            $table->string('external_id', 100)->nullable();
            $table->string('external_parent_id', 100)->nullable()->index();
            $table->string('description')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 20);
            $table->string('period', 10);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('lost_reasons');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('lead_statuses');
    }
};
