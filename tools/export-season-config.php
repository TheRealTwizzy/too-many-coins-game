<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    }
}

$db = Database::getInstance();
$row = $db->fetch(
    "SELECT * FROM seasons WHERE status IN ('Active', 'Blackout') ORDER BY season_id DESC LIMIT 1"
);
if (!$row) {
    $row = $db->fetch("SELECT * FROM seasons ORDER BY season_id DESC LIMIT 1");
}
if (!$row) {
    fwrite(STDERR, "No season rows found." . PHP_EOL);
    exit(1);
}

if (isset($row['season_seed'])) {
    $row['season_seed_hex'] = bin2hex((string)$row['season_seed']);
    unset($row['season_seed']);
}

$json = json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "Failed to encode season row." . PHP_EOL);
    exit(1);
}

if (!empty($options['output'])) {
    file_put_contents($options['output'], $json);
    fwrite(STDOUT, $options['output'] . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, $json . PHP_EOL);
