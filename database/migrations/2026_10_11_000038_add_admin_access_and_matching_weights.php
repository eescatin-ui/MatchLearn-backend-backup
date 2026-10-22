<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index();
        });

        Schema::create('matching_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('weights');
            $table->timestamps();
        });

        DB::table('matching_settings')->insert([
            'key' => 'default',
            'weights' => json_encode([
                'courses' => 25,
                'academic_complementarity' => 25,
                'program' => 20,
                'learning_style' => 10,
                'schedule' => 10,
                'year_level' => 5,
                'study_mode' => 5,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('matching_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
