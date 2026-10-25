<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_group_messages', function (Blueprint $table) {
            $table->string('client_message_id', 64)->nullable();
            $table->unique(
                ['user_id', 'direct_conversation_id', 'client_message_id'],
                'sgm_direct_client_unique'
            );
            $table->unique(
                ['user_id', 'group_id', 'client_message_id'],
                'sgm_group_client_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('study_group_messages', function (Blueprint $table) {
            $table->dropUnique('sgm_direct_client_unique');
            $table->dropUnique('sgm_group_client_unique');
            $table->dropColumn('client_message_id');
        });
    }
};
