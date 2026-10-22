<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('course_id')->constrained()->onDelete('cascade');

            $table->unique(['user_id', 'course_id']);
            $table->index('user_id');
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_courses');
    }
};
