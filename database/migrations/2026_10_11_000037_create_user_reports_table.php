<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 50);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['reported_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reports');
    }
};
