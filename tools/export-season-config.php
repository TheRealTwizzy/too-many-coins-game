<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../scripts/simulation/SeasonConfigExporter.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--metadata-output=')) {
        $options['metadata-output'] = substr($arg, 18);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Export Season Config

Usage:
  php tools/export-season-config.php [--output=FILE] [--metadata-output=FILE]

Behavior:
  - exports only the canonical patchable economy config
  - optionally writes separate metadata/runtime-state JSON
  - never mixes DB metadata or runtime-owned fields into the canonical config output
HELP;
        exit(0);
    }
}

$db = Database::getInstance();
$select = implode(",\n       ", SeasonConfigExporter::dbSelectExpressions());
$row = $db->fetch(
    "SELECT {$select}
       FROM seasons
      WHERE status IN ('Active', 'Blackout')
      ORDER BY season_id DESC
      LIMIT 1"
);
if (!$row) {
    $row = $db->fetch(
        "SELECT {$select}
           FROM seasons
          ORDER BY season_id DESC
          LIMIT 1"
    );
}
if (!$row) {
    fwrite(STDERR, "No season rows found." . PHP_EOL);
    exit(1);
}

$export = SeasonConfigExporter::exportDocumentsFromRow($row);
$json = json_encode($export['canonical_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "Failed to encode season row." . PHP_EOL);
    exit(1);
}

if (!empty($options['output'])) {
    file_put_contents($options['output'], $json);
    fwrite(STDOUT, $options['output'] . PHP_EOL);
}

$metadataJson = null;
if (!empty($options['metadata-output'])) {
    $metadataJson = json_encode($export['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($metadataJson === false) {
        fwrite(STDERR, "Failed to encode season export metadata." . PHP_EOL);
        exit(1);
    }
    file_put_contents($options['metadata-output'], $metadataJson);
    if (!empty($options['output'])) {
        fwrite(STDOUT, $options['metadata-output'] . PHP_EOL);
    }
}

if (empty($options['output'])) {
    fwrite(STDOUT, $json . PHP_EOL);
}
