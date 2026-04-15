<?php

class SimulationDeterminismNormalizer
{
    /**
     * Operational fields that are intentionally excluded from semantic
     * determinism assertions.
     */
    private const VOLATILE_FIELD_INVENTORY = [
        [
            'scope' => 'simulation_payload',
            'path' => 'generated_at',
            'reason' => 'Wall-clock timestamp changes on every artifact write.',
        ],
        [
            'scope' => 'simulation_payload',
            'path' => 'config_audit.artifact_paths.*',
            'reason' => 'Preflight audit artifact locations depend on output directories and temp paths, not simulation semantics.',
        ],
        [
            'scope' => 'policy_sweep_result',
            'path' => 'manifest_path',
            'reason' => 'Manifest output location is a run-instance artifact path only.',
        ],
        [
            'scope' => 'policy_sweep_manifest',
            'path' => 'generated_at',
            'reason' => 'Manifest creation time is wall-clock metadata, not economic output.',
        ],
        [
            'scope' => 'policy_sweep_manifest',
            'path' => 'config.base_season_config_path',
            'reason' => 'Input file location is operational metadata; equivalent base config content can live at different paths.',
        ],
        [
            'scope' => 'policy_sweep_manifest',
            'path' => 'runs[*].json',
            'reason' => 'Per-run JSON artifact paths vary with output directories.',
        ],
        [
            'scope' => 'policy_sweep_manifest',
            'path' => 'runs[*].csv',
            'reason' => 'Per-run CSV artifact paths vary with output directories.',
        ],
        [
            'scope' => 'policy_sweep_manifest',
            'path' => 'runs[*].config_audit.*',
            'reason' => 'Embedded audit artifact paths are preserved for operators but are not semantic outputs.',
        ],
    ];

    public static function volatileFieldInventory(): array
    {
        return self::VOLATILE_FIELD_INVENTORY;
    }

    /**
     * Normalize any simulator payload for semantic comparison.
     *
     * Preserves semantic results and audit meaning, while excluding
     * filesystem-location metadata and wall-clock timestamps.
     */
    public static function normalizePayload(array $payload): array
    {
        $normalized = $payload;

        unset($normalized['generated_at']);

        if (isset($normalized['config_audit']) && is_array($normalized['config_audit'])) {
            unset($normalized['config_audit']['artifact_paths']);
        }

        return $normalized;
    }

    /**
     * Normalize the PolicySweepRunner return envelope for semantic comparison.
     */
    public static function normalizePolicySweepResult(array $result): array
    {
        $normalized = $result;

        unset($normalized['manifest_path']);

        if (isset($normalized['manifest']) && is_array($normalized['manifest'])) {
            $normalized['manifest'] = self::normalizePolicySweepManifest($normalized['manifest']);
        }

        return $normalized;
    }

    /**
     * Normalize a Simulation D manifest for semantic comparison.
     */
    public static function normalizePolicySweepManifest(array $manifest): array
    {
        $normalized = $manifest;

        unset($normalized['generated_at']);

        if (isset($normalized['config']) && is_array($normalized['config'])) {
            unset($normalized['config']['base_season_config_path']);
        }

        if (isset($normalized['runs']) && is_array($normalized['runs'])) {
            foreach ($normalized['runs'] as $index => $run) {
                if (!is_array($run)) {
                    continue;
                }

                unset($run['json'], $run['csv'], $run['config_audit']);
                $normalized['runs'][$index] = $run;
            }
        }

        return $normalized;
    }
}
