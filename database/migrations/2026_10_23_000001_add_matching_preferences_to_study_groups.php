<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_groups', function (Blueprint $table) {
            $table->string('study_style')->nullable();
            $table->string('preferred_study_time')->nullable();
            $table->string('study_mode', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('study_groups', function (Blueprint $table) {
            $table->dropColumn(['study_style', 'preferred_study_time', 'study_mode']);
        });
    }
};
