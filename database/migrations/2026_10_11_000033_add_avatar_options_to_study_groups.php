<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_groups', function (Blueprint $table): void {
            $table->string('avatar_icon', 40)->default('book-outline');
            $table->string('avatar_color', 20)->default('#1a5f7a');
        });
    }

    public function down(): void
    {
        Schema::table('study_groups', function (Blueprint $table): void {
            $table->dropColumn(['avatar_icon', 'avatar_color']);
        });
    }
};
