<?php

require_once __DIR__ . '/optimization/RejectedIterationInputProducer.php';

/**
 * @return array<string, mixed>
 */
function parsePrepareArgs(array $argv): array
{
    $options = [
        'mode' => null,
        'repo-root' => null,
        'canonical-root' => null,
        'manifest' => null,
        'primary-source' => null,
        'secondary-source' => null,
        'max-age-seconds' => 86400,
        'strict' => false,
        'json' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--mode=')) {
            $options['mode'] = strtolower(trim(substr($arg, 7)));
        } elseif (str_starts_with($arg, '--repo-root=')) {
            $options['repo-root'] = substr($arg, 12);
        } elseif (str_starts_with($arg, '--canonical-root=')) {
            $options['canonical-root'] = substr($arg, 17);
        } elseif (str_starts_with($arg, '--manifest=')) {
            $options['manifest'] = substr($arg, 11);
        } elseif (str_starts_with($arg, '--primary-source=')) {
            $options['primary-source'] = substr($arg, 17);
        } elseif (str_starts_with($arg, '--secondary-source=')) {
            $options['secondary-source'] = substr($arg, 19);
        } elseif (str_starts_with($arg, '--max-age-seconds=')) {
            $options['max-age-seconds'] = (int)substr($arg, 18);
        } elseif ($arg === '--strict') {
            $options['strict'] = true;
        } elseif ($arg === '--json') {
            $options['json'] = true;
        } elseif ($arg === '--help') {
            $options['help'] = true;
        } else {
            $options['invalid_arg'] = $arg;
        }
    }

    return $options;
}

$args = parsePrepareArgs($argv);
if (!empty($args['help'])) {
    echo <<<'HELP'
Prepare Rejected Iteration Inputs (Phase 3 producer path)

Usage:
  php scripts/prepare_rejected_iteration_inputs.php --mode=generate [options]
  php scripts/prepare_rejected_iteration_inputs.php --mode=verify [options]

Options:
  --mode=generate|verify      Required.
  --repo-root=PATH            Optional repo root override.
  --canonical-root=PATH       Optional canonical root override.
  --manifest=PATH             Optional manifest path (verify mode).
  --primary-source=PATH       Optional primary source override (generate mode).
  --secondary-source=PATH     Optional secondary source override (generate mode).
  --max-age-seconds=N         Freshness threshold for verify checks (default: 86400).
  --strict                    Verify mode: fail when integrity/freshness validation fails.
  --json                      Print machine-readable result payload.
  --help                      Show this help.
HELP;
    exit(0);
}

if (!empty($args['invalid_arg'])) {
    fwrite(STDERR, 'Unknown argument: ' . (string)$args['invalid_arg'] . PHP_EOL);
    exit(2);
}

$mode = (string)($args['mode'] ?? '');
if (!in_array($mode, ['generate', 'verify'], true)) {
    fwrite(STDERR, "Missing or invalid --mode. Supported values: generate, verify\n");
    exit(2);
}

$baseOptions = [
    'repo_root' => $args['repo-root'],
    'canonical_root' => $args['canonical-root'],
    'manifest_path' => $args['manifest'],
    'primary_source' => $args['primary-source'],
    'secondary_source' => $args['secondary-source'],
    'max_age_seconds' => (int)($args['max-age-seconds'] ?? 86400),
    'strict_integrity' => (bool)($args['strict'] ?? false),
];

try {
    if ($mode === 'generate') {
        $result = AgenticRejectedIterationInputProducer::generate($baseOptions);
        if (!empty($args['json'])) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo "Generated canonical rejected-iteration inputs." . PHP_EOL;
            echo "Manifest: " . (string)$result['manifest_path'] . PHP_EOL;
            echo "Primary: " . (string)$result['primary_canonical_path'] . PHP_EOL;
            echo "Secondary: " . (string)$result['secondary_canonical_path'] . PHP_EOL;
        }
        exit(0);
    }

    $result = AgenticRejectedIterationInputProducer::verify($baseOptions);
    if (!empty($args['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo "Verification " . ((bool)($result['ok'] ?? false) ? 'passed' : 'failed') . PHP_EOL;
        echo "Manifest: " . (string)$result['manifest_path'] . PHP_EOL;
    }
    exit((bool)($result['ok'] ?? false) ? 0 : 4);
} catch (Throwable $e) {
    fwrite(STDERR, ($mode === 'verify' ? 'Verify failed: ' : 'Generate failed: ') . $e->getMessage() . PHP_EOL);
    exit($mode === 'verify' ? 4 : 3);
}
