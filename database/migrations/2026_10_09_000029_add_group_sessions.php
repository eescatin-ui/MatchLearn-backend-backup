<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table): void {
            $table->foreignId('participant_user_id')->nullable()->change();
            $table->foreignId('study_group_id')
                ->nullable()
                ->after('participant_user_id')
                ->constrained('study_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('study_sessions')
            ->whereNotNull('study_group_id')
            ->delete();

        Schema::table('study_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('study_group_id');
            $table->foreignId('participant_user_id')->nullable(false)->change();
        });
    }
};
