<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code', 50)->nullable()->unique()->after('name');
            $table->string('phone', 30)->nullable()->after('email');
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->after('role_id')->constrained('teams')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->after('team_id')->constrained('users')->nullOnDelete();
            $table->string('designation', 100)->nullable()->after('manager_id');
            $table->boolean('is_active')->default(true)->after('designation')->index();
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropUnique(['employee_code']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'employee_code', 'phone', 'designation', 'is_active',
                'last_login_at', 'last_login_ip', 'deleted_at',
            ]);
        });
    }
};
