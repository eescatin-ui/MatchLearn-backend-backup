<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->json('study_topics')->nullable();
            $table->json('academic_strengths')->nullable();
            $table->json('academic_needs')->nullable();
            $table->string('study_mode', 20)->nullable();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('shared_topic_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('shared_topic_count');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'study_topics',
                'academic_strengths',
                'academic_needs',
                'study_mode',
            ]);
        });
    }
};
