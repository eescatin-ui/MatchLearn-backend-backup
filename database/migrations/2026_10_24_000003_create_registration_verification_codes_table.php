<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_verification_codes', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_verification_codes');
    }
};
