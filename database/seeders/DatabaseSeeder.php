<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\LearningStyle;
use App\Models\Profile;
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

        // Seed the current SNSU Main Campus colleges.
        $snsuCE = $this->findOrRenameDepartment('College of Engineering and Architecture', [
            'College of Engineering',
        ]);
        $snsuCICS = $this->findOrRenameDepartment('College of Information and Computing Sciences', [
            'College of Computing and Information Sciences',
            'College of Computer Studies',
        ]);
        $snsuCIT = $this->findOrRenameDepartment('College of Industrial Technology', [
            'College of Technology',
        ]);
        $snsuCTE = Department::firstOrCreate(['name' => 'College of Teacher Education']);
        $snsuCBHM = Department::firstOrCreate(['name' => 'College of Business and Hospitality Management']);

        // Preserve the existing SSCT catalog for accounts using that institution.
        $ssctEng = Department::firstOrCreate(['name' => 'Engineering Technology']);
        $ssctInfo = Department::firstOrCreate(['name' => 'Information Technology']);

        $this->seedPrograms($snsuCE, [
            'Bachelor of Science in Civil Engineering (BSCE)',
            'Bachelor of Science in Electrical Engineering (BSEE)',
            'Bachelor of Science in Computer Engineering (BSCpE)',
            'Bachelor of Science in Electronics Engineering (BSEC / ECE)',
        ], [
            'Bachelor of Science in Civil Engineering' => 'Bachelor of Science in Civil Engineering (BSCE)',
            'Bachelor of Science in Electrical Engineering' => 'Bachelor of Science in Electrical Engineering (BSEE)',
            'Bachelor of Science in Computer Engineering' => 'Bachelor of Science in Computer Engineering (BSCpE)',
            'Bachelor of Science in Electronics Engineering' => 'Bachelor of Science in Electronics Engineering (BSEC / ECE)',
        ]);
        $this->seedPrograms($snsuCICS, [
            'Bachelor of Science in Information Technology (BSIT)',
            'Bachelor of Science in Computer Science (BSCS)',
            'Bachelor of Science in Information Systems (BSIS)',
        ], [
            'Bachelor of Science in Information Technology' => 'Bachelor of Science in Information Technology (BSIT)',
            'Bachelor of Science in Computer Science' => 'Bachelor of Science in Computer Science (BSCS)',
            'Bachelor of Science in Information System' => 'Bachelor of Science in Information Systems (BSIS)',
        ]);
        $this->seedPrograms($snsuCIT, [
            'Bachelor of Industrial Technology (BIT) - Automotive',
            'Bachelor of Industrial Technology (BIT) - Electrical',
            'Bachelor of Industrial Technology (BIT) - Electronics',
            'Bachelor of Industrial Technology (BIT) - Mechanical',
            'Bachelor of Industrial Technology (BIT) - Drafting',
            'Bachelor of Industrial Technology (BIT) - Civil',
            'Bachelor of Industrial Technology (BIT) - Foods',
            'Bachelor of Industrial Technology (BIT) - Welding and Fabrication',
            'Bachelor of Industrial Technology (BIT) - Refrigeration and Air-Conditioning',
            'Associate in Industrial Technology (AIT)',
        ], [
            'Bachelor of Automotive Engineering Technology' => 'Bachelor of Industrial Technology (BIT) - Automotive',
            'Bachelor of Electrical Engineering Technology' => 'Bachelor of Industrial Technology (BIT) - Electrical',
            'Bachelor of Electronics Engineering Technology' => 'Bachelor of Industrial Technology (BIT) - Electronics',
            'Bachelor of Mechanical Engineering Technology - Mechanical Technology' => 'Bachelor of Industrial Technology (BIT) - Mechanical',
            'Bachelor of Mechanical Engineering Technology - Refrigeration and Air-Conditioning Technology' => 'Bachelor of Industrial Technology (BIT) - Refrigeration and Air-Conditioning',
            'Bachelor of Mechanical Engineering Technology - Welding and Fabrication Technology' => 'Bachelor of Industrial Technology (BIT) - Welding and Fabrication',
            'Bachelor of Industrial Technology - Architectural Drafting' => 'Bachelor of Industrial Technology (BIT) - Drafting',
            'Bachelor of Industrial Technology - Automotive Technology' => 'Bachelor of Industrial Technology (BIT) - Automotive',
            'Bachelor of Industrial Technology - Electrical Technology' => 'Bachelor of Industrial Technology (BIT) - Electrical',
            'Bachelor of Industrial Technology - Electronics Technology' => 'Bachelor of Industrial Technology (BIT) - Electronics',
            'Bachelor of Industrial Technology - Mechanical Technology' => 'Bachelor of Industrial Technology (BIT) - Mechanical',
            'Bachelor of Industrial Technology - Heating, Ventilating, and Air-Conditioning Technology' => 'Bachelor of Industrial Technology (BIT) - Refrigeration and Air-Conditioning',
            'Bachelor of Industrial Technology - Welding and Fabrication Technology' => 'Bachelor of Industrial Technology (BIT) - Welding and Fabrication',
        ]);
        $this->seedPrograms($snsuCTE, [
            'Bachelor of Elementary Education (BEEd)',
            'Bachelor of Secondary Education (BSEd) - English',
            'Bachelor of Secondary Education (BSEd) - Mathematics',
            'Bachelor of Secondary Education (BSEd) - Science/Biological/Physical Science',
            'Bachelor of Secondary Education (BSEd) - Filipino',
            'Bachelor of Technical-Vocational Teacher Education (BTVTED)',
        ], [
            'Bachelor of Elementary Education' => 'Bachelor of Elementary Education (BEEd)',
            'Bachelor of Secondary Education - English' => 'Bachelor of Secondary Education (BSEd) - English',
            'Bachelor of Secondary Education - Mathematics' => 'Bachelor of Secondary Education (BSEd) - Mathematics',
            'Bachelor of Secondary Education - Science' => 'Bachelor of Secondary Education (BSEd) - Science/Biological/Physical Science',
            'Bachelor of Secondary Education - Filipino' => 'Bachelor of Secondary Education (BSEd) - Filipino',
            'Bachelor of Technical-Vocational Teacher Education' => 'Bachelor of Technical-Vocational Teacher Education (BTVTED)',
        ]);
        $this->seedPrograms($snsuCBHM, [
            'Bachelor of Science in Hospitality Management (BSHM) / Hotel and Restaurant Management',
        ]);

        // Seed Programs for SSCT
        $ssctEng->programs()->firstOrCreate(['name' => 'Associate in Electronics and Communication Engineering']);
        $ssctInfo->programs()->firstOrCreate(['name' => 'Associate in Computer Technology']);
        $ssctInfo->programs()->firstOrCreate(['name' => 'Associate in Information Technology']);

        // Seed Courses
        $this->seedCourses($snsuCICS);
        $this->seedCourses($snsuCE);
        $this->seedCourses($snsuCTE);
        $this->seedCourses($snsuCIT);
        $this->seedCourses($snsuCBHM);
        $this->seedCourses($ssctEng);
        $this->seedCourses($ssctInfo);
    }

    private function findOrRenameDepartment(string $name, array $legacyNames = []): Department
    {
        $department = Department::where('name', $name)->first();
        if ($department) {
            return $department;
        }

        $department = Department::whereIn('name', $legacyNames)->first();
        if ($department) {
            $department->update(['name' => $name]);

            return $department->refresh();
        }

        return Department::create(['name' => $name]);
    }

    private function seedPrograms(Department $department, array $programs, array $legacyNames = []): void
    {
        foreach ($legacyNames as $legacyName => $currentName) {
            $legacyProgram = $department->programs()->where('name', $legacyName)->first();
            if (! $legacyProgram) {
                continue;
            }

            $currentProgram = $department->programs()->where('name', $currentName)->first();
            if ($currentProgram) {
                Profile::where('program_id', $legacyProgram->id)
                    ->update(['program_id' => $currentProgram->id]);
                $legacyProgram->delete();
            } else {
                $legacyProgram->update(['name' => $currentName]);
            }
        }

        foreach ($programs as $name) {
            $department->programs()->firstOrCreate(['name' => $name]);
        }
    }

    private function seedCourses(Department $department): void
    {
        $courses = match ($department->name) {
            'College of Information and Computing Sciences' => [
                'CCSIS101' => 'Introduction to Computer Science',
                'CCSIS102' => 'Programming Fundamentals',
                'CCSIS201' => 'Data Structures',
                'CCSIS202' => 'Database Management',
                'CCSIS301' => 'Web Development',
            ],
            'College of Engineering and Architecture' => [
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
            'College of Business and Hospitality Management' => [
                'CBHM101' => 'Introduction to Hospitality Management',
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
