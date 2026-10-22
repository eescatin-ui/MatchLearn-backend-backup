<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_reports', function (Blueprint $table): void {
            $table->json('reasons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_reports', function (Blueprint $table): void {
            $table->dropColumn('reasons');
        });
    }
};
