<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('participant_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('topic', 255);
            $table->dateTime('starts_at');
            $table->string('location', 255);
            $table->string('status')->default('pending')->index();
            $table->timestamps();

            $table->index(['host_user_id', 'starts_at']);
            $table->index(['participant_user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_sessions');
    }
};
