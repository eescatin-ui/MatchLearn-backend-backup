<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('matched_user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedTinyInteger('compatibility_score');
            $table->unsignedTinyInteger('shared_course_count')->default(0);
            $table->json('reasons')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'matched_user_id']);
            $table->index(['user_id', 'compatibility_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
