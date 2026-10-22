<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\LearningStyle;
use App\Models\Strength;
use App\Models\Weakness;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed Learning Styles
        LearningStyle::firstOrCreate(['name' => 'Visual']);
        LearningStyle::firstOrCreate(['name' => 'Auditory']);
        LearningStyle::firstOrCreate(['name' => 'Reading/Writing']);
        LearningStyle::firstOrCreate(['name' => 'Kinesthetic']);

        // Seed Strengths and Weaknesses
        $strengths = [
            'Time management',
            'Problem solving',
            'Communication',
            'Discipline',
            'Teamwork',
            'Research',
        ];

        foreach ($strengths as $strength) {
            Strength::firstOrCreate(['name' => $strength]);
        }

        $weaknesses = [
            'Procrastination',
            'Concept retention',
            'Public speaking',
            'Test anxiety',
            'Focus management',
            'Reading speed',
        ];

        foreach ($weaknesses as $weakness) {
            Weakness::firstOrCreate(['name' => $weakness]);
        }

        // Seed SNSU Main Campus colleges.
        $snsuCCIS = Department::where('name', 'College of Computing and Information Sciences')->first();
        if (!$snsuCCIS) {
            $snsuCCIS = Department::where('name', 'College of Computer Studies')->first();
            if ($snsuCCIS) {
                $snsuCCIS->update(['name' => 'College of Computing and Information Sciences']);
                $snsuCCIS->refresh();
            } else {
                $snsuCCIS = Department::firstOrCreate(['name' => 'College of Computing and Information Sciences']);
            }
        }
        $snsuEng = Department::firstOrCreate(['name' => 'College of Engineering']);
        $snsuCAS = Department::firstOrCreate(['name' => 'College of Arts and Sciences']);
        $snsuCTE = Department::firstOrCreate(['name' => 'College of Teacher Education']);
        $snsuCOT = Department::firstOrCreate(['name' => 'College of Technology']);

        // Preserve the existing SSCT catalog for accounts using that institution.
        $ssctEng = Department::firstOrCreate(['name' => 'Engineering Technology']);
        $ssctInfo = Department::firstOrCreate(['name' => 'Information Technology']);

        $this->seedPrograms($snsuCCIS, [
            'Bachelor of Science in Computer Science',
            'Bachelor of Science in Information Technology',
            'Bachelor of Science in Information System',
        ]);
        $this->seedPrograms($snsuEng, [
            'Bachelor of Science in Civil Engineering',
            'Bachelor of Science in Electronics Engineering',
            'Bachelor of Science in Electrical Engineering',
            'Bachelor of Science in Computer Engineering',
        ]);
        $this->seedPrograms($snsuCAS, [
            'Bachelor of Science in Mathematics',
            'Bachelor of Science in Environmental Science',
            'Bachelor of Arts in English Language',
        ]);
        $this->seedPrograms($snsuCTE, [
            'Bachelor of Elementary Education',
            'Bachelor of Secondary Education - English',
            'Bachelor of Secondary Education - Filipino',
            'Bachelor of Secondary Education - Science',
            'Bachelor of Secondary Education - Mathematics',
            'Bachelor of Physical Education',
            'Bachelor of Technical-Vocational Teacher Education',
        ]);
        $this->seedPrograms($snsuCOT, [
            'Bachelor of Engineering Technology',
            'Bachelor of Automotive Engineering Technology',
            'Bachelor of Electrical Engineering Technology',
            'Bachelor of Electronics Engineering Technology',
            'Bachelor of Mechanical Engineering Technology - Mechanical Technology',
            'Bachelor of Mechanical Engineering Technology - Refrigeration and Air-Conditioning Technology',
            'Bachelor of Mechanical Engineering Technology - Welding and Fabrication Technology',
            'Bachelor of Industrial Technology - Architectural Drafting',
            'Bachelor of Industrial Technology - Automotive Technology',
            'Bachelor of Industrial Technology - Electrical Technology',
            'Bachelor of Industrial Technology - Electronics Technology',
            'Bachelor of Industrial Technology - Mechanical Technology',
            'Bachelor of Industrial Technology - Heating, Ventilating, and Air-Conditioning Technology',
            'Bachelor of Industrial Technology - Welding and Fabrication Technology',
            'Bachelor of Science in Hospitality Management',
            'Bachelor of Science in Tourism Management',
        ]);

        // Seed Programs for SSCT
        $ssctEng->programs()->firstOrCreate(['name' => 'Associate in Electronics and Communication Engineering']);
        $ssctInfo->programs()->firstOrCreate(['name' => 'Associate in Computer Technology']);
        $ssctInfo->programs()->firstOrCreate(['name' => 'Associate in Information Technology']);

        // Seed Courses
        $this->seedCourses($snsuCCIS);
        $this->seedCourses($snsuEng);
        $this->seedCourses($snsuCAS);
        $this->seedCourses($snsuCTE);
        $this->seedCourses($snsuCOT);
        $this->seedCourses($ssctEng);
        $this->seedCourses($ssctInfo);
    }

    private function seedPrograms(Department $department, array $programs): void
    {
        foreach ($programs as $name) {
            $department->programs()->firstOrCreate(['name' => $name]);
        }
    }

    private function seedCourses(Department $department): void
    {
        $courses = match($department->name) {
            'College of Computing and Information Sciences' => [
                'CCSIS101' => 'Introduction to Computer Science',
                'CCSIS102' => 'Programming Fundamentals',
                'CCSIS201' => 'Data Structures',
                'CCSIS202' => 'Database Management',
                'CCSIS301' => 'Web Development',
            ],
            'College of Engineering' => [
                'ENGMAT101' => 'Engineering Mathematics',
                'ENGMAT102' => 'Physics for Engineers',
                'ENGMAT201' => 'Thermodynamics',
                'ENGCIV101' => 'Civil Engineering Fundamentals',
                'ENGELC101' => 'Electrical Engineering Fundamentals',
            ],
            'Engineering Technology' => [
                'ETECE101' => 'Basic Electronics',
                'ETECE201' => 'Electronic Communications',
                'ETCT101' => 'Computer Hardware Fundamentals',
            ],
            'Information Technology' => [
                'IT101' => 'Information Technology Fundamentals',
                'IT201' => 'Computer Systems and Networks',
                'IT202' => 'Database Management',
            ],
            default => []
        };

        foreach ($courses as $code => $name) {
            $department->courses()->firstOrCreate(
                ['code' => $code],
                ['name' => $name]
            );
        }
    }
}
