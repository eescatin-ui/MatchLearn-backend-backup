<?php

namespace App\Http\Requests\Profile;

use Illuminate\Validation\Validator;

trait ValidatesDistinctStudyTopics
{
    private function validateDistinctStudyTopics(Validator $validator): void
    {
        $profile = $this->user()?->profile()->first();
        $strengths = $this->exists('academic_strengths')
            ? $this->input('academic_strengths', [])
            : ($profile?->academic_strengths ?? []);
        $needs = $this->exists('academic_needs')
            ? $this->input('academic_needs', [])
            : ($profile?->academic_needs ?? []);

        if (! is_array($strengths) || ! is_array($needs)) {
            return;
        }

        $strengthKeys = [];
        foreach ($strengths as $strength) {
            if (is_string($strength)) {
                $key = mb_strtolower(trim($strength));
                if ($key !== '') {
                    $strengthKeys[$key] = true;
                }
            }
        }

        foreach ($needs as $index => $need) {
            if (is_string($need) && isset($strengthKeys[mb_strtolower(trim($need))])) {
                $validator->errors()->add(
                    "academic_needs.{$index}",
                    'A topic cannot be selected as both a strength and a weakness.'
                );
            }
        }
    }
}
