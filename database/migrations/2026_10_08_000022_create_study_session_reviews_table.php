<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_session_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['study_session_id', 'reviewer_user_id']);
            $table->index(['reviewed_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_session_reviews');
    }
};
