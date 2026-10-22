<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['reviewer_user_id', 'reviewed_user_id']);
            $table->index(['reviewed_user_id', 'created_at']);
        });

        $latestReviews = [];

        DB::table('study_session_reviews')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $review) use (&$latestReviews): void {
                $key = $review->reviewer_user_id.':'.$review->reviewed_user_id;
                $latestReviews[$key] = [
                    'reviewer_user_id' => $review->reviewer_user_id,
                    'reviewed_user_id' => $review->reviewed_user_id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at,
                    'updated_at' => $review->updated_at,
                ];
            });

        foreach (array_chunk(array_values($latestReviews), 500) as $reviews) {
            DB::table('buddy_reviews')->insert($reviews);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_reviews');
    }
};
