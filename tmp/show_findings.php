<?php
$d = json_decode(file_get_contents('simulation_output/current-db/diagnosis/diagnosis_report.json'), true);
foreach ($d['findings'] as $f) {
    echo $f['id'] . ' | ' . $f['severity'] . ' | ' . $f['category'] . PHP_EOL;
}
