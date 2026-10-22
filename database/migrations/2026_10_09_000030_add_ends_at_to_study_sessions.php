<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table): void {
            $table->dateTime('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table): void {
            $table->dropColumn('ends_at');
        });
    }
};
