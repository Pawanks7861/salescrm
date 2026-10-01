<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trainers assigned to batches (many-to-many with users holding the Trainer
 * role). Only this pivot changes: batches, leads and users are untouched.
 * trainer_id restricts deletion so a hard user delete never silently drops
 * assignment history; users are normally soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_trainers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('trainer_id')->index()->constrained('users');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['batch_id', 'trainer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_trainers');
    }
};
