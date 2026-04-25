<?php

require_once __DIR__ . '/ApprovalManifest.php';
require_once __DIR__ . '/FeatureFactoryException.php';
require_once __DIR__ . '/FeaturePatchClassifier.php';
require_once __DIR__ . '/../optimization/AgenticOptimization.php';
require_once __DIR__ . '/../simulation/EconomicCandidateValidator.php';

class MechanicScaffolder
{
    public static function writeBundle(array $brief, array $balanceReport, array $options = []): array
    {
        $outputRootOption = (string)($options['output_root'] ?? '');
        $outputRoot = $outputRootOption !== ''
            ? rtrim($outputRootOption, DIRECTORY_SEPARATOR)
            : realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR . 'feature_factory';
        $mechanicId = (string)$brief['mechanic_id'];
        $bundleRoot = $outputRoot . DIRECTORY_SEPARATOR . $mechanicId;
        self::ensureDir($bundleRoot);

        $candidateTemplate = self::candidateTemplate($brief);
        $candidateValidation = self::validateCandidateTemplate($candidateTemplate);
        $bundleHash = substr(AgenticOptimizationUtils::jsonHash([
            'brief' => $brief,
            'balance' => $balanceReport,
            'candidate_template' => $candidateTemplate,
        ]), 0, 16);

        $artifactPaths = [
            'mechanic_brief_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'mechanic_brief.json',
            'mechanic_brief_md' => $bundleRoot . DIRECTORY_SEPARATOR . 'mechanic_brief.md',
            'balance_impact_report_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'balance_impact_report.json',
            'balance_impact_report_md' => $bundleRoot . DIRECTORY_SEPARATOR . 'balance_impact_report.md',
            'candidate_patch_template_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'candidate_patch_template.json',
            'mechanic_contract_checklist_md' => $bundleRoot . DIRECTORY_SEPARATOR . 'mechanic_contract_checklist.md',
            'approval_manifest_example_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'approval_manifest.example.json',
            'implementation_plan_draft_md' => $bundleRoot . DIRECTORY_SEPARATOR . 'implementation_plan_draft.md',
            'patch_classification_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'patch_classification.json',
        ];

        $relativePaths = [
            'simulation_output/feature_factory/' . $mechanicId . '/mechanic_brief.json',
            'simulation_output/feature_factory/' . $mechanicId . '/mechanic_brief.md',
            'simulation_output/feature_factory/' . $mechanicId . '/balance_impact_report.json',
            'simulation_output/feature_factory/' . $mechanicId . '/balance_impact_report.md',
            'simulation_output/feature_factory/' . $mechanicId . '/candidate_patch_template.json',
            'simulation_output/feature_factory/' . $mechanicId . '/mechanic_contract_checklist.md',
            'simulation_output/feature_factory/' . $mechanicId . '/approval_manifest.example.json',
            'simulation_output/feature_factory/' . $mechanicId . '/implementation_plan_draft.md',
            'simulation_output/feature_factory/' . $mechanicId . '/patch_classification.json',
        ];
        foreach ((array)($options['planned_patch_paths'] ?? []) as $path) {
            $relativePaths[] = (string)$path;
        }
        $classification = FeaturePatchClassifier::classifyPaths($relativePaths);
        FeaturePatchClassifier::assertPreApprovalAllowed($classification);

        self::writeJson($artifactPaths['mechanic_brief_json'], $brief);
        file_put_contents($artifactPaths['mechanic_brief_md'], self::briefMarkdown($brief));
        self::writeJson($artifactPaths['balance_impact_report_json'], $balanceReport);
        file_put_contents($artifactPaths['balance_impact_report_md'], self::balanceMarkdown($balanceReport));
        self::writeJson($artifactPaths['candidate_patch_template_json'], $candidateTemplate);
        file_put_contents($artifactPaths['mechanic_contract_checklist_md'], self::contractChecklist($brief));
        self::writeJson($artifactPaths['approval_manifest_example_json'], ApprovalManifest::example($mechanicId, $bundleHash));
        file_put_contents($artifactPaths['implementation_plan_draft_md'], self::implementationDraft($brief));
        self::writeJson($artifactPaths['patch_classification_json'], $classification);

        return [
            'schema_version' => 'tmc-feature-factory-bundle.v1',
            'mechanic_id' => $mechanicId,
            'bundle_hash' => $bundleHash,
            'bundle_root' => $bundleRoot,
            'artifact_paths' => $artifactPaths,
            'candidate_validation' => $candidateValidation,
            'classification' => $classification,
        ];
    }

    private static function candidateTemplate(array $brief): array
    {
        $changes = [];
        foreach ((array)$brief['tunable_parameters'] as $parameter) {
            $key = (string)$parameter['key'];
            if (($brief['config_key_status'][$key] ?? 'proposed') !== 'existing') {
                continue;
            }
            $changes[] = [
                'target' => $key,
                'proposed_value' => $parameter['proposed_value'],
            ];
        }

        return [
            'schema_version' => 'tmc-feature-candidate-template.v1',
            'mechanic_id' => (string)$brief['mechanic_id'],
            'packages' => $changes === [] ? [] : [[
                'package_name' => 'feature_factory_' . (string)$brief['mechanic_id'],
                'changes' => $changes,
            ]],
            'scenarios' => [],
            'proposed_keys_excluded_from_optimizer_search' => array_values(array_filter(
                array_keys((array)$brief['config_key_status']),
                static fn($key) => (($brief['config_key_status'][$key] ?? '') === 'proposed')
            )),
        ];
    }

    private static function validateCandidateTemplate(array $candidateTemplate): array
    {
        if ((array)$candidateTemplate['packages'] === []) {
            return [
                'status' => 'skipped_no_existing_patchable_keys',
                'failures' => [],
            ];
        }

        $failures = EconomicCandidateValidator::validateCandidateDocument($candidateTemplate);
        return [
            'status' => $failures === [] ? 'pass' : 'fail',
            'failures' => $failures,
        ];
    }

    private static function briefMarkdown(array $brief): string
    {
        return '# Mechanic Brief: ' . $brief['title'] . PHP_EOL . PHP_EOL
            . '- Mechanic ID: `' . $brief['mechanic_id'] . '`' . PHP_EOL
            . '- Primary strategy: `' . $brief['primary_strategy'] . '`' . PHP_EOL
            . '- Summary: ' . $brief['summary'] . PHP_EOL
            . '- Player fantasy: ' . $brief['player_fantasy'] . PHP_EOL;
    }

    private static function balanceMarkdown(array $report): string
    {
        $lines = ['# Balance Impact Report', '', '- Mechanic ID: `' . $report['mechanic_id'] . '`'];
        $lines[] = '- Primary strategy: `' . $report['primary_strategy'] . '`';
        $lines[] = '- Risk flags: ' . count((array)$report['risk_flags']);
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function contractChecklist(array $brief): string
    {
        return '# Mechanic Contract Checklist' . PHP_EOL . PHP_EOL
            . '- [ ] Runtime path declared before approval' . PHP_EOL
            . '- [ ] Simulation path declared before approval' . PHP_EOL
            . '- [ ] Parity fixture planned before optimizer search eligibility' . PHP_EOL
            . '- [ ] Candidate keys validated against canonical schema' . PHP_EOL
            . '- [ ] Effective-config audit artifacts required for simulation runs' . PHP_EOL
            . PHP_EOL
            . 'Mechanic: `' . $brief['mechanic_id'] . '`' . PHP_EOL;
    }

    private static function implementationDraft(array $brief): string
    {
        return '# Implementation Plan Draft: ' . $brief['title'] . PHP_EOL . PHP_EOL
            . 'This draft is limited to scaffolding until an approval manifest allows player-facing paths.' . PHP_EOL . PHP_EOL
            . '- Mechanic ID: `' . $brief['mechanic_id'] . '`' . PHP_EOL
            . '- Approval required for runtime/API/frontend work: yes' . PHP_EOL;
    }

    private static function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}
