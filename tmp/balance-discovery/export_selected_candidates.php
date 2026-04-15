<?php

$selectedIds = [
    'stage1-market_affordability_bias_fp-02',
    'stage1-starprice_reactivation_window_ticks-01',
    'stage1-starprice_max_downstep_fp-04',
    'stage1-starprice_max_upstep_fp-03',
    'stage1-base_ubi_active_per_tick-06',
    'stage1-target_spend_rate_per_tick-05',
    'stage2-market_affordability_bias_fp-starprice_reactivation_window_ticks',
    'stage2-market_affordability_bias_fp-starprice_max_downstep_fp',
    'stage2-starprice_max_upstep_fp-starprice_reactivation_window_ticks',
];

$doc = json_decode((string)file_get_contents(__DIR__ . '/generated_candidates.json'), true);
if (!is_array($doc)) {
    fwrite(STDERR, "Failed to decode generated candidates.\n");
    exit(1);
}

$packages = [];
foreach ((array)($doc['packages'] ?? []) as $package) {
    $packages[(string)($package['candidate_id'] ?? '')] = $package;
}

$outputDir = __DIR__ . '/selected';
if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output dir.\n");
    exit(1);
}

$manifest = [];
foreach ($selectedIds as $candidateId) {
    if (!isset($packages[$candidateId])) {
        fwrite(STDERR, "Missing package: {$candidateId}\n");
        exit(1);
    }

    $package = $packages[$candidateId];
    $patch = [];
    foreach ((array)($package['changes'] ?? []) as $change) {
        $target = (string)($change['target'] ?? '');
        if ($target === '') {
            continue;
        }
        $patch[$target] = $change['proposed_value'] ?? null;
    }

    $path = $outputDir . '/' . $candidateId . '.json';
    file_put_contents($path, json_encode($patch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $manifest[] = [
        'candidate_id' => $candidateId,
        'stage' => (string)($package['stage'] ?? ''),
        'targets' => array_values(array_keys($patch)),
        'path' => str_replace('\\', '/', $path),
    ];
}

file_put_contents(__DIR__ . '/selected_candidates_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
