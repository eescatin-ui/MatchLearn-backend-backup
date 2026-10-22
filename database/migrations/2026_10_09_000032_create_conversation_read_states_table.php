<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_read_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_type', 20);
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('last_read_message_id');
            $table->timestamps();

            $table->unique(
                ['user_id', 'conversation_type', 'conversation_id'],
                'conversation_read_state_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_read_states');
    }
};
