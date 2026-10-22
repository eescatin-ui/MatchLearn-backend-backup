<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_group_members', function (Blueprint $table) {
            $table->foreignId('study_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->primary(['study_group_id', 'user_id']);
            $table->index(['user_id', 'study_group_id']);
        });

        DB::table('study_groups')->orderBy('id')->each(function (object $group): void {
            DB::table('study_group_members')->insert([
                'study_group_id' => $group->id,
                'user_id' => $group->created_by,
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_group_members');
    }
};
