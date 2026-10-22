<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach (['Department', 'Program', 'LearningStyle'] as $model) {
    $class = 'App\\Models\\' . $model;
    echo strtoupper($model) . "\n";
    $rows = $class::query()->get(['id', 'name']);
    foreach ($rows as $row) {
        echo $row->id . ' | ' . $row->name . "\n";
    }
    echo "---\n";
}

$programs = App\Models\Program::query()->get(['id', 'department_id', 'name']);
foreach ($programs as $p) {
    echo $p->id . ' | dept=' . $p->department_id . ' | ' . $p->name . "\n";
}
