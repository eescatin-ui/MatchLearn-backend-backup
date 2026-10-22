<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_study_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('course_code', 40);
            $table->string('course_code_normalized', 40);
            $table->string('course_name', 120);
            $table->timestamps();

            $table->unique(['profile_id', 'course_code_normalized']);
            $table->index('course_code_normalized');
        });

        DB::table('profiles')
            ->select(['id', 'study_topics'])
            ->whereNotNull('study_topics')
            ->orderBy('id')
            ->chunkById(100, function ($profiles): void {
                foreach ($profiles as $profile) {
                    $topics = json_decode($profile->study_topics, true);
                    if (!is_array($topics)) {
                        continue;
                    }

                    foreach ($topics as $topic) {
                        if (!is_string($topic) || !preg_match(
                            '/^\s*([A-Za-z0-9][A-Za-z0-9 .\/-]*?)\s+-\s+(.+?)\s*$/u',
                            $topic,
                            $parts
                        )) {
                            continue;
                        }

                        $courseCode = trim($parts[1]);
                        $courseName = trim($parts[2]);
                        $normalizedCode = preg_replace('/[^A-Za-z0-9]/', '', $courseCode);

                        if (!$normalizedCode || !$courseName) {
                            continue;
                        }

                        DB::table('profile_study_courses')->insertOrIgnore([
                            'profile_id' => $profile->id,
                            'course_code' => $courseCode,
                            'course_code_normalized' => strtoupper($normalizedCode),
                            'course_name' => $courseName,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

    }

    public function down(): void
    {
        Schema::dropIfExists('profile_study_courses');
    }
};
