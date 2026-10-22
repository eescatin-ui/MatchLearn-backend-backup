<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_one_id', 'user_two_id']);
        });

        Schema::table('study_group_messages', function (Blueprint $table) {
            $table->foreignId('direct_conversation_id')
                ->nullable()
                ->after('group_id')
                ->constrained('direct_conversations')
                ->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('study_group_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('direct_conversation_id');
            $table->foreignId('group_id')->nullable(false)->change();
        });
        Schema::dropIfExists('direct_conversations');
    }
};
