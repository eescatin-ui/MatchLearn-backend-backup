<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_group_join_requests', function (Blueprint $table) {
            $table->foreignId('invited_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('study_group_join_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_user_id');
        });
    }
};
