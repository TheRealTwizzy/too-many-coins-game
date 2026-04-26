<?php

require_once __DIR__ . '/ExperimentBrief.php';
require_once __DIR__ . '/ExperienceGate.php';
require_once __DIR__ . '/FeatureFactory.php';

class ExperimentCapsuleRunner
{
    public static function generateFromProposalFile(string $proposalPath, array $options = []): array
    {
        if (!is_file($proposalPath)) {
            throw new FeatureFactoryException('Experiment proposal file not found', [[
                'path' => $proposalPath,
                'reason_code' => 'experiment_proposal_file_not_found',
                'reason_detail' => 'The proposal path does not point to a file.',
            ]]);
        }

        $json = (string)file_get_contents($proposalPath);
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new FeatureFactoryException('Experiment proposal JSON must decode to an object', [[
                'path' => $proposalPath,
                'reason_code' => 'experiment_proposal_json_invalid',
                'reason_detail' => 'The proposal file must contain a JSON object.',
            ]]);
        }

        return self::generate($decoded, $options);
    }

    public static function generate(array $proposal, array $options = []): array
    {
        $experimentBrief = ExperimentBrief::fromProposal($proposal);
        $featureFactoryResult = FeatureFactory::generate($experimentBrief['mechanic_proposal'], [
            'output_root' => $options['output_root'] ?? null,
        ]);

        $approvalManifest = $options['approval_manifest'] ?? null;
        if (($options['approval_manifest_bundle_hash_mode'] ?? '') === 'use_generated_hash' && is_array($approvalManifest)) {
            $approvalManifest['bundle_hash'] = $featureFactoryResult['bundle_hash'];
        }

        $experienceGateReport = ExperienceGate::evaluate(
            $experimentBrief,
            is_array($approvalManifest) ? $approvalManifest : null,
            (string)$featureFactoryResult['bundle_hash']
        );

        $decisionRecord = self::decisionRecord($experimentBrief, $featureFactoryResult, $experienceGateReport);
        $artifactPaths = self::writeExperimentArtifacts(
            (string)$featureFactoryResult['bundle_root'],
            $experimentBrief,
            $experienceGateReport,
            $decisionRecord
        );

        return array_merge($featureFactoryResult, [
            'experiment_id' => (string)$experimentBrief['experiment_id'],
            'experiment_brief' => $experimentBrief,
            'experience_gate_report' => $experienceGateReport,
            'decision_record' => $decisionRecord,
            'experiment_artifact_paths' => $artifactPaths,
        ]);
    }

    private static function decisionRecord(array $experimentBrief, array $featureFactoryResult, array $experienceGateReport): array
    {
        $status = 'pass';
        $reasons = [];

        if (($featureFactoryResult['candidate_validation']['status'] ?? 'fail') === 'fail') {
            $status = 'fail';
            $reasons[] = self::reason('candidate_validation_failed', 'Candidate template failed canonical validation.');
        }

        if (($experimentBrief['recommended_mode'] ?? 'capsule') === 'dual_lane') {
            $status = 'split_to_dual_lane';
            foreach ((array)$experimentBrief['mode_decision_reasons'] as $reason) {
                $reasons[] = $reason;
            }
        }

        $experienceStatus = (string)($experienceGateReport['status'] ?? 'fail');
        if ($experienceStatus === 'fail') {
            $status = 'fail';
            foreach ((array)$experienceGateReport['issues'] as $issue) {
                $reasons[] = $issue;
            }
        } elseif ($experienceStatus === 'split_to_dual_lane') {
            $status = 'split_to_dual_lane';
            foreach ((array)$experienceGateReport['issues'] as $issue) {
                $reasons[] = $issue;
            }
        } elseif ($experienceStatus === 'pending_approval' && $status === 'pass') {
            $status = 'revise';
            foreach ((array)$experienceGateReport['issues'] as $issue) {
                $reasons[] = $issue;
            }
        }

        return [
            'schema_version' => 'tmc-experiment-decision-record.v1',
            'experiment_id' => (string)$experimentBrief['experiment_id'],
            'mechanic_id' => (string)$experimentBrief['mechanic_brief']['mechanic_id'],
            'status' => $status,
            'recommended_mode' => (string)$experimentBrief['recommended_mode'],
            'candidate_validation_status' => (string)($featureFactoryResult['candidate_validation']['status'] ?? 'unknown'),
            'experience_gate_status' => $experienceStatus,
            'reasons' => array_values($reasons),
            'rollback_notes' => (string)$experimentBrief['rollback_notes'],
        ];
    }

    private static function writeExperimentArtifacts(string $bundleRoot, array $experimentBrief, array $experienceGateReport, array $decisionRecord): array
    {
        $paths = [
            'experiment_brief_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'experiment_brief.json',
            'experience_gate_report_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'experience_gate_report.json',
            'decision_record_json' => $bundleRoot . DIRECTORY_SEPARATOR . 'decision_record.json',
        ];

        self::writeJson($paths['experiment_brief_json'], $experimentBrief);
        self::writeJson($paths['experience_gate_report_json'], $experienceGateReport);
        self::writeJson($paths['decision_record_json'], $decisionRecord);

        return $paths;
    }

    private static function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function reason(string $code, string $detail): array
    {
        return [
            'path' => 'decision_record',
            'reason_code' => $code,
            'reason_detail' => $detail,
        ];
    }
}
