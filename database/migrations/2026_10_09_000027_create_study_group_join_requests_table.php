<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_group_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['study_group_id', 'user_id']);
            $table->index(['study_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_group_join_requests');
    }
};
