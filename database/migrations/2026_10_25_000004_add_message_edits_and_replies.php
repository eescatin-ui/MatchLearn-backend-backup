<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_group_messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->foreignId('reply_to_id')
                ->nullable()
                ->constrained('study_group_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('study_group_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn('edited_at');
        });
    }
};
