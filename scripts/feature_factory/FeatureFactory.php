<?php

require_once __DIR__ . '/BalanceImpactModel.php';
require_once __DIR__ . '/FeatureFactoryException.php';
require_once __DIR__ . '/MechanicBrief.php';
require_once __DIR__ . '/MechanicScaffolder.php';

class FeatureFactory
{
    public static function generateFromProposalFile(string $proposalPath, array $options = []): array
    {
        if (!is_file($proposalPath)) {
            throw new FeatureFactoryException('Proposal file not found', [[
                'path' => $proposalPath,
                'reason_code' => 'proposal_file_not_found',
                'reason_detail' => 'The proposal path does not point to a file.',
            ]]);
        }

        $json = (string)file_get_contents($proposalPath);
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new FeatureFactoryException('Proposal JSON must decode to an object', [[
                'path' => $proposalPath,
                'reason_code' => 'proposal_json_invalid',
                'reason_detail' => 'The proposal file must contain a JSON object.',
            ]]);
        }

        return self::generate($decoded, $options);
    }

    public static function generate(array $proposal, array $options = []): array
    {
        $brief = MechanicBrief::fromProposal($proposal);
        $balance = BalanceImpactModel::buildReport($brief);

        return MechanicScaffolder::writeBundle($brief, $balance, [
            'output_root' => $options['output_root'] ?? null,
            'planned_patch_paths' => (array)($proposal['planned_patch_paths'] ?? []),
        ]);
    }
}
