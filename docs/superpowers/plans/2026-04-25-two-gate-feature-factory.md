# Two-Gate Feature Factory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a CLI-first feature factory that turns mechanic proposals into simulation/config/mechanic scaffolding bundles while blocking player-facing writes until explicit approval.

**Architecture:** Add a focused `scripts/feature_factory/` module with brief validation, balance impact modeling, path classification, approval manifest validation, scaffolding generation, and orchestration. The CLI writes review-first bundles under `simulation_output/feature_factory/<mechanic_id>/` and never edits runtime/API/frontend/deployment files.

**Tech Stack:** PHP 8.x, PHPUnit 11, existing `EconomicCandidateValidator`, existing direct `require_once` test conventions.

---

## File Structure

- Create `scripts/feature_factory/FeatureFactoryException.php`: shared exception carrying machine-readable failure details.
- Create `scripts/feature_factory/MechanicBrief.php`: proposal normalization and required strategic-footprint validation.
- Create `scripts/feature_factory/FeaturePatchClassifier.php`: path classification and pre-approval blocking.
- Create `scripts/feature_factory/ApprovalManifest.php`: approval manifest validation against mechanic id, bundle hash, classes, and paths.
- Create `scripts/feature_factory/BalanceImpactModel.php`: archetype/metric/risk report generation.
- Create `scripts/feature_factory/MechanicScaffolder.php`: writes bundle artifacts and validates existing-key candidate templates.
- Create `scripts/feature_factory/FeatureFactory.php`: coordinates proposal -> brief -> balance report -> scaffolding -> classification.
- Create `scripts/generate_feature_factory_bundle.php`: CLI entrypoint.
- Create `tests/FeatureFactoryBriefTest.php`: brief validation coverage.
- Create `tests/FeatureFactoryGuardrailTest.php`: path classifier and approval manifest coverage.
- Create `tests/FeatureFactoryScaffolderTest.php`: bundle artifact and candidate validation coverage.
- Create `tests/FeatureFactoryCliTest.php`: CLI smoke coverage.

No existing runtime, API, frontend, deployment, schema, init, or migration files are modified in this plan.

---

### Task 1: Mechanic Brief Validation

**Files:**
- Create: `scripts/feature_factory/FeatureFactoryException.php`
- Create: `scripts/feature_factory/MechanicBrief.php`
- Test: `tests/FeatureFactoryBriefTest.php`

- [ ] **Step 1: Write the failing brief validation tests**

Create `tests/FeatureFactoryBriefTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/MechanicBrief.php';

class FeatureFactoryBriefTest extends TestCase
{
    public function testValidProposalNormalizesToBrief(): void
    {
        $brief = MechanicBrief::fromProposal($this->validProposal());

        $this->assertSame('daily_streak_pressure', $brief['mechanic_id']);
        $this->assertSame('Regular play streak pressure', $brief['title']);
        $this->assertSame('regular', $brief['primary_strategy']);
        $this->assertSame(['hoarder', 'mostly_idle'], $brief['secondary_strategies']);
        $this->assertSame(['base_ubi_active_per_tick'], array_column($brief['tunable_parameters'], 'key'));
        $this->assertSame(['streak_decay_rate_fp'], array_column($brief['proposed_new_config_keys'], 'key'));
        $this->assertSame('proposed', $brief['config_key_status']['streak_decay_rate_fp']);
        $this->assertSame('existing', $brief['config_key_status']['base_ubi_active_per_tick']);
    }

    public function testMissingStrategicFootprintIsRejected(): void
    {
        $proposal = $this->validProposal();
        unset($proposal['counterplay'], $proposal['required_metrics']['mechanic_specific']);

        try {
            MechanicBrief::fromProposal($proposal);
            $this->fail('Expected missing strategic footprint to fail.');
        } catch (FeatureFactoryException $e) {
            $codes = array_column($e->details(), 'reason_code');
            $this->assertContains('missing_counterplay', $codes);
            $this->assertContains('missing_metric_family', $codes);
        }
    }

    public function testInvalidMechanicIdIsRejected(): void
    {
        $proposal = $this->validProposal();
        $proposal['mechanic_id'] = 'Bad Id With Spaces';

        $this->expectException(FeatureFactoryException::class);
        $this->expectExceptionMessage('Mechanic brief validation failed');

        MechanicBrief::fromProposal($proposal);
    }

    private function validProposal(): array
    {
        return [
            'mechanic_id' => 'daily_streak_pressure',
            'title' => 'Regular play streak pressure',
            'summary' => 'Reward repeat daily participation without making hoarding dominant.',
            'player_fantasy' => 'Consistent players feel their daily rhythm matters.',
            'affected_systems' => ['ubi', 'hoarding_pressure'],
            'primary_strategy' => 'regular',
            'secondary_strategies' => ['hoarder', 'mostly_idle'],
            'counterplay' => ['Spend earlier to avoid becoming an easy hoarding target.'],
            'failure_modes' => ['dominant_strategy_risk', 'hoarding_abuse', 'onboarding_harm'],
            'tunable_parameters' => [
                [
                    'key' => 'base_ubi_active_per_tick',
                    'kind' => 'existing',
                    'proposed_value' => 42,
                    'reason' => 'Existing patchable key used to model reward pressure.',
                ],
            ],
            'proposed_new_config_keys' => [
                [
                    'key' => 'streak_decay_rate_fp',
                    'type' => 'int',
                    'reason' => 'Future runtime key for streak decay.',
                ],
            ],
            'required_metrics' => [
                'viability' => ['archetype_viability_min_ratio'],
                'concentration_or_diversity' => ['strategic_diversity'],
                'mechanic_specific' => ['streak_completion_density'],
            ],
            'archetype_expectations' => [
                'hoarder' => 'down',
                'mostly_idle' => 'down',
                'regular' => 'up',
                'hardcore' => 'flat',
                'boost_focused' => 'flat',
                'star_focused' => 'flat',
                'early_locker' => 'flat',
                'late_deployer' => 'down',
                'casual' => 'up',
                'aggressive_sigil_user' => 'flat',
            ],
        ];
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryBriefTest.php --no-coverage
```

Expected: FAIL because `scripts/feature_factory/MechanicBrief.php` does not exist.

- [ ] **Step 3: Add the feature factory exception**

Create `scripts/feature_factory/FeatureFactoryException.php`:

```php
<?php

class FeatureFactoryException extends RuntimeException
{
    private array $details;

    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = array_values($details);
    }

    public function details(): array
    {
        return $this->details;
    }
}
```

- [ ] **Step 4: Add mechanic brief normalization**

Create `scripts/feature_factory/MechanicBrief.php`:

```php
<?php

require_once __DIR__ . '/FeatureFactoryException.php';
require_once __DIR__ . '/../simulation/CanonicalEconomyConfigContract.php';

class MechanicBrief
{
    private const REQUIRED_ARCHETYPES = [
        'hoarder',
        'mostly_idle',
        'regular',
        'hardcore',
        'boost_focused',
        'star_focused',
        'early_locker',
        'late_deployer',
        'casual',
        'aggressive_sigil_user',
    ];

    private const REQUIRED_METRIC_FAMILIES = [
        'viability',
        'concentration_or_diversity',
        'mechanic_specific',
    ];

    public static function fromProposal(array $proposal): array
    {
        $failures = self::validate($proposal);
        if ($failures !== []) {
            throw new FeatureFactoryException('Mechanic brief validation failed', $failures);
        }

        $brief = [
            'schema_version' => 'tmc-mechanic-brief.v1',
            'mechanic_id' => self::cleanString($proposal['mechanic_id']),
            'title' => self::cleanString($proposal['title']),
            'summary' => self::cleanString($proposal['summary']),
            'player_fantasy' => self::cleanString($proposal['player_fantasy']),
            'affected_systems' => array_values((array)$proposal['affected_systems']),
            'primary_strategy' => self::cleanString($proposal['primary_strategy']),
            'secondary_strategies' => array_values((array)$proposal['secondary_strategies']),
            'counterplay' => array_values((array)$proposal['counterplay']),
            'failure_modes' => array_values((array)$proposal['failure_modes']),
            'tunable_parameters' => self::normalizeTunableParameters((array)$proposal['tunable_parameters']),
            'proposed_new_config_keys' => self::normalizeProposedKeys((array)($proposal['proposed_new_config_keys'] ?? [])),
            'required_metrics' => [
                'viability' => array_values((array)$proposal['required_metrics']['viability']),
                'concentration_or_diversity' => array_values((array)$proposal['required_metrics']['concentration_or_diversity']),
                'mechanic_specific' => array_values((array)$proposal['required_metrics']['mechanic_specific']),
            ],
            'archetype_expectations' => (array)$proposal['archetype_expectations'],
            'approval_required_for_player_facing_paths' => true,
        ];

        $brief['config_key_status'] = self::configKeyStatus($brief);

        return $brief;
    }

    public static function validate(array $proposal): array
    {
        $failures = [];
        foreach ([
            'mechanic_id',
            'title',
            'summary',
            'player_fantasy',
            'primary_strategy',
        ] as $field) {
            if (self::cleanString($proposal[$field] ?? '') === '') {
                $failures[] = self::failure($field, 'missing_required_field', 'Required field is missing or empty.');
            }
        }

        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', (string)($proposal['mechanic_id'] ?? ''))) {
            $failures[] = self::failure('mechanic_id', 'invalid_mechanic_id', 'Use lowercase snake_case, 3-64 characters.');
        }

        foreach (['affected_systems', 'secondary_strategies', 'failure_modes', 'tunable_parameters'] as $field) {
            if (count((array)($proposal[$field] ?? [])) === 0) {
                $failures[] = self::failure($field, 'missing_required_list', 'Required list must contain at least one item.');
            }
        }

        if (count((array)($proposal['counterplay'] ?? [])) === 0) {
            $failures[] = self::failure('counterplay', 'missing_counterplay', 'At least one counterplay entry is required.');
        }

        $metrics = (array)($proposal['required_metrics'] ?? []);
        foreach (self::REQUIRED_METRIC_FAMILIES as $family) {
            if (count((array)($metrics[$family] ?? [])) === 0) {
                $failures[] = self::failure('required_metrics.' . $family, 'missing_metric_family', 'Required metric family must contain at least one metric.');
            }
        }

        $expectations = (array)($proposal['archetype_expectations'] ?? []);
        foreach (self::REQUIRED_ARCHETYPES as $archetype) {
            if (!array_key_exists($archetype, $expectations)) {
                $failures[] = self::failure('archetype_expectations.' . $archetype, 'missing_archetype_expectation', 'Every existing archetype needs an expected impact.');
                continue;
            }
            if (!in_array($expectations[$archetype], ['up', 'down', 'flat', 'unknown'], true)) {
                $failures[] = self::failure('archetype_expectations.' . $archetype, 'invalid_archetype_expectation', 'Impact must be up, down, flat, or unknown.');
            }
        }

        foreach ((array)($proposal['tunable_parameters'] ?? []) as $index => $parameter) {
            if (!is_array($parameter)) {
                $failures[] = self::failure('tunable_parameters[' . $index . ']', 'invalid_tunable_parameter', 'Tunable parameter must be an object.');
                continue;
            }
            if (self::cleanString($parameter['key'] ?? '') === '') {
                $failures[] = self::failure('tunable_parameters[' . $index . '].key', 'missing_tunable_key', 'Tunable parameter key is required.');
            }
            if (!in_array((string)($parameter['kind'] ?? ''), ['existing', 'proposed'], true)) {
                $failures[] = self::failure('tunable_parameters[' . $index . '].kind', 'invalid_tunable_kind', 'Tunable kind must be existing or proposed.');
            }
        }

        return $failures;
    }

    private static function normalizeTunableParameters(array $parameters): array
    {
        $normalized = [];
        foreach ($parameters as $parameter) {
            $normalized[] = [
                'key' => self::cleanString($parameter['key'] ?? ''),
                'kind' => self::cleanString($parameter['kind'] ?? ''),
                'proposed_value' => $parameter['proposed_value'] ?? null,
                'reason' => self::cleanString($parameter['reason'] ?? ''),
            ];
        }
        return $normalized;
    }

    private static function normalizeProposedKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[] = [
                'key' => self::cleanString($key['key'] ?? ''),
                'type' => self::cleanString($key['type'] ?? ''),
                'reason' => self::cleanString($key['reason'] ?? ''),
                'optimizer_search_eligible' => false,
            ];
        }
        return $normalized;
    }

    private static function configKeyStatus(array $brief): array
    {
        $surface = CanonicalEconomyConfigContract::validatorSurfaceMeta();
        $status = [];
        foreach ((array)$brief['tunable_parameters'] as $parameter) {
            $key = (string)$parameter['key'];
            $status[$key] = isset($surface[$key]) ? 'existing' : 'proposed';
        }
        foreach ((array)$brief['proposed_new_config_keys'] as $key) {
            $status[(string)$key['key']] = 'proposed';
        }
        ksort($status);
        return $status;
    }

    private static function failure(string $path, string $code, string $detail): array
    {
        return [
            'path' => $path,
            'reason_code' => $code,
            'reason_detail' => $detail,
        ];
    }

    private static function cleanString(mixed $value): string
    {
        return trim((string)$value);
    }
}
```

- [ ] **Step 5: Run the brief tests and verify they pass**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryBriefTest.php --no-coverage
```

Expected: PASS with 3 tests.

- [ ] **Step 6: Commit**

```bash
git add scripts/feature_factory/FeatureFactoryException.php scripts/feature_factory/MechanicBrief.php tests/FeatureFactoryBriefTest.php
git commit -m "feat: add feature factory mechanic brief validation"
```

---

### Task 2: Path Classification And Approval Guardrails

**Files:**
- Create: `scripts/feature_factory/FeaturePatchClassifier.php`
- Create: `scripts/feature_factory/ApprovalManifest.php`
- Test: `tests/FeatureFactoryGuardrailTest.php`

- [ ] **Step 1: Write the failing guardrail tests**

Create `tests/FeatureFactoryGuardrailTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/FeaturePatchClassifier.php';
require_once __DIR__ . '/../scripts/feature_factory/ApprovalManifest.php';

class FeatureFactoryGuardrailTest extends TestCase
{
    public function testPreApprovalBlocksRuntimeApiFrontendDeploymentDatabaseAndUnknownPaths(): void
    {
        $classification = FeaturePatchClassifier::classifyPaths([
            'simulation_output/feature_factory/example/mechanic_brief.json',
            'scripts/simulation/RuntimeParityCertification.php',
            'includes/economy.php',
            'api/index.php',
            'public/js/app.js',
            'public/css/style.css',
            'Dockerfile',
            'migration_20260425_example.sql',
            'mystery/path.txt',
        ]);

        $this->assertSame('scaffolding', $classification['paths'][0]['class']);
        $this->assertSame('mechanic_contract', $classification['paths'][1]['class']);
        $this->assertSame('runtime', $classification['paths'][2]['class']);
        $this->assertSame('api', $classification['paths'][3]['class']);
        $this->assertSame('frontend', $classification['paths'][4]['class']);
        $this->assertSame('frontend', $classification['paths'][5]['class']);
        $this->assertSame('deployment', $classification['paths'][6]['class']);
        $this->assertSame('database', $classification['paths'][7]['class']);
        $this->assertSame('unknown', $classification['paths'][8]['class']);

        $blocked = FeaturePatchClassifier::blockedPreApproval($classification);
        $this->assertSame(
            ['includes/economy.php', 'api/index.php', 'public/js/app.js', 'public/css/style.css', 'Dockerfile', 'migration_20260425_example.sql', 'mystery/path.txt'],
            array_column($blocked, 'path')
        );
    }

    public function testApprovalManifestAllowsOnlyMatchingMechanicBundleClassAndPath(): void
    {
        $manifest = [
            'schema_version' => 'tmc-feature-approval.v1',
            'mechanic_id' => 'daily_streak_pressure',
            'bundle_hash' => 'abc123',
            'allowed_path_classes' => ['runtime'],
            'allowed_paths' => ['includes/economy.php'],
            'approval_reason' => 'Runtime implementation approved after scaffolding review.',
            'approver' => 'trent',
            'approved_at' => '2026-04-25T12:00:00Z',
        ];

        $failures = ApprovalManifest::validateForPaths(
            $manifest,
            'daily_streak_pressure',
            'abc123',
            FeaturePatchClassifier::classifyPaths(['includes/economy.php'])
        );

        $this->assertSame([], $failures);

        $failures = ApprovalManifest::validateForPaths(
            $manifest,
            'daily_streak_pressure',
            'abc123',
            FeaturePatchClassifier::classifyPaths(['public/js/app.js'])
        );

        $this->assertSame('approval_class_not_allowed', $failures[0]['reason_code']);
    }
}
```

- [ ] **Step 2: Run the guardrail tests and verify they fail**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryGuardrailTest.php --no-coverage
```

Expected: FAIL because guardrail classes do not exist.

- [ ] **Step 3: Add path classification**

Create `scripts/feature_factory/FeaturePatchClassifier.php`:

```php
<?php

require_once __DIR__ . '/FeatureFactoryException.php';

class FeaturePatchClassifier
{
    private const BLOCKED_PRE_APPROVAL = ['runtime', 'api', 'frontend', 'database', 'deployment', 'unknown'];

    public static function classifyPaths(array $paths): array
    {
        $rows = [];
        foreach ($paths as $path) {
            $normalized = self::normalizePath((string)$path);
            $rows[] = [
                'path' => $normalized,
                'class' => self::classifyPath($normalized),
            ];
        }

        return [
            'schema_version' => 'tmc-feature-patch-classification.v1',
            'paths' => $rows,
            'blocked_pre_approval' => self::blockedPreApproval(['paths' => $rows]),
        ];
    }

    public static function blockedPreApproval(array $classification): array
    {
        $blocked = [];
        foreach ((array)($classification['paths'] ?? []) as $row) {
            if (in_array((string)($row['class'] ?? 'unknown'), self::BLOCKED_PRE_APPROVAL, true)) {
                $blocked[] = $row;
            }
        }
        return $blocked;
    }

    public static function assertPreApprovalAllowed(array $classification): void
    {
        $blocked = self::blockedPreApproval($classification);
        if ($blocked !== []) {
            throw new FeatureFactoryException('Feature factory pre-approval guard blocked player-facing or unsafe paths.', $blocked);
        }
    }

    private static function classifyPath(string $path): string
    {
        if ($path === '' || str_contains($path, '..')) {
            return 'unknown';
        }
        if (preg_match('#^(simulation_output/feature_factory/|docs/superpowers/|tmp/feature_factory/)#', $path)) {
            return 'scaffolding';
        }
        if (preg_match('#^(scripts/simulation/|scripts/optimization/|tools/export-season-config\.php|scripts/lint_candidate_packages\.php)#', $path)) {
            return 'mechanic_contract';
        }
        if (preg_match('#^(includes/)#', $path)) {
            return 'runtime';
        }
        if ($path === 'api/index.php' || str_starts_with($path, 'api/')) {
            return 'api';
        }
        if (preg_match('#^(public/).*\.(js|css|html)$#', $path)) {
            return 'frontend';
        }
        if (preg_match('#^(migration_.*\.sql|schema\.sql|seed_data\.sql|init_db\.php)$#', $path)) {
            return 'database';
        }
        if (preg_match('#^(\.github/|docker/|Dockerfile|docker-compose\.yml|DEPLOY_|setup\.sh)#', $path)) {
            return 'deployment';
        }
        return 'unknown';
    }

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
```

- [ ] **Step 4: Add approval manifest validation**

Create `scripts/feature_factory/ApprovalManifest.php`:

```php
<?php

require_once __DIR__ . '/FeatureFactoryException.php';

class ApprovalManifest
{
    public static function example(string $mechanicId, string $bundleHash): array
    {
        return [
            'schema_version' => 'tmc-feature-approval.v1',
            'mechanic_id' => $mechanicId,
            'bundle_hash' => $bundleHash,
            'allowed_path_classes' => ['runtime'],
            'allowed_paths' => ['includes/economy.php'],
            'approval_reason' => 'Replace with the reviewed reason before use.',
            'approver' => 'human-reviewer',
            'approved_at' => gmdate('c'),
        ];
    }

    public static function validateForPaths(array $manifest, string $mechanicId, string $bundleHash, array $classification): array
    {
        $failures = [];

        if (($manifest['schema_version'] ?? '') !== 'tmc-feature-approval.v1') {
            $failures[] = self::failure('schema_version', 'approval_schema_mismatch', 'Approval schema version must be tmc-feature-approval.v1.');
        }
        if ((string)($manifest['mechanic_id'] ?? '') !== $mechanicId) {
            $failures[] = self::failure('mechanic_id', 'approval_mechanic_mismatch', 'Approval mechanic id does not match this bundle.');
        }
        if ((string)($manifest['bundle_hash'] ?? '') !== $bundleHash) {
            $failures[] = self::failure('bundle_hash', 'approval_bundle_hash_mismatch', 'Approval bundle hash does not match this bundle.');
        }
        foreach (['approval_reason', 'approver', 'approved_at'] as $field) {
            if (trim((string)($manifest[$field] ?? '')) === '') {
                $failures[] = self::failure($field, 'approval_required_field_missing', 'Approval field is required.');
            }
        }

        $allowedClasses = array_fill_keys(array_map('strval', (array)($manifest['allowed_path_classes'] ?? [])), true);
        $allowedPaths = array_fill_keys(array_map([self::class, 'normalizePath'], (array)($manifest['allowed_paths'] ?? [])), true);

        foreach ((array)($classification['paths'] ?? []) as $row) {
            $class = (string)($row['class'] ?? 'unknown');
            $path = self::normalizePath((string)($row['path'] ?? ''));
            if (!isset($allowedClasses[$class])) {
                $failures[] = self::failure($path, 'approval_class_not_allowed', 'Approval does not allow path class ' . $class . '.');
                continue;
            }
            if (!isset($allowedPaths[$path])) {
                $failures[] = self::failure($path, 'approval_path_not_allowed', 'Approval does not allow this path.');
            }
        }

        return $failures;
    }

    private static function failure(string $path, string $code, string $detail): array
    {
        return [
            'path' => $path,
            'reason_code' => $code,
            'reason_detail' => $detail,
        ];
    }

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
```

- [ ] **Step 5: Run the guardrail tests and verify they pass**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryGuardrailTest.php --no-coverage
```

Expected: PASS with 2 tests.

- [ ] **Step 6: Commit**

```bash
git add scripts/feature_factory/FeaturePatchClassifier.php scripts/feature_factory/ApprovalManifest.php tests/FeatureFactoryGuardrailTest.php
git commit -m "feat: add feature factory approval guardrails"
```

---

### Task 3: Balance Reports And Scaffolding Bundle

**Files:**
- Create: `scripts/feature_factory/BalanceImpactModel.php`
- Create: `scripts/feature_factory/MechanicScaffolder.php`
- Test: `tests/FeatureFactoryScaffolderTest.php`

- [ ] **Step 1: Write the failing scaffolder tests**

Create `tests/FeatureFactoryScaffolderTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/MechanicBrief.php';
require_once __DIR__ . '/../scripts/feature_factory/BalanceImpactModel.php';
require_once __DIR__ . '/../scripts/feature_factory/MechanicScaffolder.php';

class FeatureFactoryScaffolderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_feature_factory_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testScaffolderWritesExpectedBundleArtifacts(): void
    {
        $brief = MechanicBrief::fromProposal($this->proposal());
        $balance = BalanceImpactModel::buildReport($brief);

        $result = MechanicScaffolder::writeBundle($brief, $balance, [
            'output_root' => $this->tempDir,
        ]);

        $this->assertFileExists($result['artifact_paths']['mechanic_brief_json']);
        $this->assertFileExists($result['artifact_paths']['mechanic_brief_md']);
        $this->assertFileExists($result['artifact_paths']['balance_impact_report_json']);
        $this->assertFileExists($result['artifact_paths']['candidate_patch_template_json']);
        $this->assertFileExists($result['artifact_paths']['mechanic_contract_checklist_md']);
        $this->assertFileExists($result['artifact_paths']['approval_manifest_example_json']);
        $this->assertFileExists($result['artifact_paths']['implementation_plan_draft_md']);
        $this->assertFileExists($result['artifact_paths']['patch_classification_json']);
        $this->assertSame([], $result['classification']['blocked_pre_approval']);

        $candidate = json_decode((string)file_get_contents($result['artifact_paths']['candidate_patch_template_json']), true);
        $this->assertSame('feature_factory_daily_streak_pressure', $candidate['packages'][0]['package_name']);
        $this->assertSame('base_ubi_active_per_tick', $candidate['packages'][0]['changes'][0]['target']);
        $this->assertSame(42, $candidate['packages'][0]['changes'][0]['proposed_value']);
        $this->assertSame('pass', $result['candidate_validation']['status']);
    }

    public function testProposedOnlyKeysDoNotEnterCandidateTemplate(): void
    {
        $proposal = $this->proposal();
        $proposal['tunable_parameters'] = [
            [
                'key' => 'streak_decay_rate_fp',
                'kind' => 'proposed',
                'proposed_value' => 900000,
                'reason' => 'Future runtime key.',
            ],
        ];
        $brief = MechanicBrief::fromProposal($proposal);
        $balance = BalanceImpactModel::buildReport($brief);

        $result = MechanicScaffolder::writeBundle($brief, $balance, [
            'output_root' => $this->tempDir,
        ]);

        $candidate = json_decode((string)file_get_contents($result['artifact_paths']['candidate_patch_template_json']), true);
        $this->assertSame([], $candidate['packages']);
        $this->assertSame('skipped_no_existing_patchable_keys', $result['candidate_validation']['status']);
    }

    private function proposal(): array
    {
        return [
            'mechanic_id' => 'daily_streak_pressure',
            'title' => 'Regular play streak pressure',
            'summary' => 'Reward repeat daily participation without making hoarding dominant.',
            'player_fantasy' => 'Consistent players feel their daily rhythm matters.',
            'affected_systems' => ['ubi', 'hoarding_pressure'],
            'primary_strategy' => 'regular',
            'secondary_strategies' => ['hoarder', 'mostly_idle'],
            'counterplay' => ['Spend earlier to avoid becoming an easy hoarding target.'],
            'failure_modes' => ['dominant_strategy_risk', 'hoarding_abuse', 'onboarding_harm'],
            'tunable_parameters' => [
                ['key' => 'base_ubi_active_per_tick', 'kind' => 'existing', 'proposed_value' => 42, 'reason' => 'Model reward pressure.'],
            ],
            'proposed_new_config_keys' => [
                ['key' => 'streak_decay_rate_fp', 'type' => 'int', 'reason' => 'Future runtime key for streak decay.'],
            ],
            'required_metrics' => [
                'viability' => ['archetype_viability_min_ratio'],
                'concentration_or_diversity' => ['strategic_diversity'],
                'mechanic_specific' => ['streak_completion_density'],
            ],
            'archetype_expectations' => [
                'hoarder' => 'down',
                'mostly_idle' => 'down',
                'regular' => 'up',
                'hardcore' => 'flat',
                'boost_focused' => 'flat',
                'star_focused' => 'flat',
                'early_locker' => 'flat',
                'late_deployer' => 'down',
                'casual' => 'up',
                'aggressive_sigil_user' => 'flat',
            ],
        ];
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array)scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->deleteDir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($path);
    }
}
```

- [ ] **Step 2: Run the scaffolder tests and verify they fail**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryScaffolderTest.php --no-coverage
```

Expected: FAIL because `BalanceImpactModel` and `MechanicScaffolder` do not exist.

- [ ] **Step 3: Add balance impact report generation**

Create `scripts/feature_factory/BalanceImpactModel.php`:

```php
<?php

class BalanceImpactModel
{
    public static function buildReport(array $brief): array
    {
        $expectations = (array)$brief['archetype_expectations'];
        $up = array_keys(array_filter($expectations, static fn($v) => $v === 'up'));
        $down = array_keys(array_filter($expectations, static fn($v) => $v === 'down'));
        $flat = array_keys(array_filter($expectations, static fn($v) => $v === 'flat'));
        $unknown = array_keys(array_filter($expectations, static fn($v) => $v === 'unknown'));

        return [
            'schema_version' => 'tmc-balance-impact-report.v1',
            'mechanic_id' => (string)$brief['mechanic_id'],
            'primary_strategy' => (string)$brief['primary_strategy'],
            'secondary_strategies' => array_values((array)$brief['secondary_strategies']),
            'counterplay' => array_values((array)$brief['counterplay']),
            'failure_modes' => array_values((array)$brief['failure_modes']),
            'required_metrics' => (array)$brief['required_metrics'],
            'archetype_expectations' => $expectations,
            'archetype_impact_summary' => [
                'up' => $up,
                'down' => $down,
                'flat' => $flat,
                'unknown' => $unknown,
            ],
            'risk_flags' => self::riskFlags($brief, $up, $down, $unknown),
            'optimizer_search_constraints' => self::optimizerSearchConstraints($brief),
        ];
    }

    private static function riskFlags(array $brief, array $up, array $down, array $unknown): array
    {
        $flags = [];
        $failureModes = array_fill_keys((array)$brief['failure_modes'], true);
        if (isset($failureModes['dominant_strategy_risk']) && count($up) <= 1) {
            $flags[] = [
                'risk' => 'single_strategy_overbuff',
                'severity' => 'major',
                'detail' => 'Primary declared risk plus one or fewer positively affected archetypes can create a dominant lane.',
            ];
        }
        if (isset($failureModes['hoarding_abuse']) && in_array('hoarder', $up, true)) {
            $flags[] = [
                'risk' => 'hoarder_positive_pressure',
                'severity' => 'critical',
                'detail' => 'Mechanic declares hoarding abuse risk while expecting hoarder impact to increase.',
            ];
        }
        if ($unknown !== []) {
            $flags[] = [
                'risk' => 'unknown_archetype_impact',
                'severity' => 'minor',
                'detail' => 'Some archetype impacts are unknown and need focused simulation interpretation.',
            ];
        }
        if ($down !== [] && count($down) >= 4) {
            $flags[] = [
                'risk' => 'broad_strategy_suppression',
                'severity' => 'major',
                'detail' => 'Four or more archetypes are expected to move down.',
            ];
        }
        return $flags;
    }

    private static function optimizerSearchConstraints(array $brief): array
    {
        $constraints = [];
        foreach ((array)$brief['config_key_status'] as $key => $status) {
            $constraints[] = [
                'key' => (string)$key,
                'status' => (string)$status,
                'optimizer_search_eligible' => $status === 'existing',
            ];
        }
        return $constraints;
    }
}
```

- [ ] **Step 4: Add the scaffolder**

Create `scripts/feature_factory/MechanicScaffolder.php` with these public methods and behavior:

```php
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
        $outputRoot = rtrim((string)($options['output_root'] ?? (__DIR__ . '/../../simulation_output/feature_factory')), DIRECTORY_SEPARATOR);
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
```

- [ ] **Step 5: Run the scaffolder tests and verify they pass**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryScaffolderTest.php --no-coverage
```

Expected: PASS with 2 tests.

- [ ] **Step 6: Commit**

```bash
git add scripts/feature_factory/BalanceImpactModel.php scripts/feature_factory/MechanicScaffolder.php tests/FeatureFactoryScaffolderTest.php
git commit -m "feat: generate feature factory scaffolding bundles"
```

---

### Task 4: Feature Factory Orchestrator And CLI

**Files:**
- Create: `scripts/feature_factory/FeatureFactory.php`
- Create: `scripts/generate_feature_factory_bundle.php`
- Test: `tests/FeatureFactoryCliTest.php`

- [ ] **Step 1: Write the failing CLI smoke test**

Create `tests/FeatureFactoryCliTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class FeatureFactoryCliTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_feature_factory_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testCliGeneratesBundle(): void
    {
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'proposal.json';
        file_put_contents($proposalPath, json_encode($this->proposal(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_feature_factory_bundle.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'bundles');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('Feature Factory Bundle', implode(PHP_EOL, $output));
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'bundles' . DIRECTORY_SEPARATOR . 'daily_streak_pressure' . DIRECTORY_SEPARATOR . 'mechanic_brief.json');
    }

    public function testCliFailsWhenPlannedRuntimePathIsProvidedWithoutApproval(): void
    {
        $proposal = $this->proposal();
        $proposal['planned_patch_paths'] = ['includes/economy.php'];
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'proposal-blocked.json';
        file_put_contents($proposalPath, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_feature_factory_bundle.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'blocked');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(1, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('pre-approval guard blocked', implode(PHP_EOL, $output));
    }

    private function proposal(): array
    {
        return [
            'mechanic_id' => 'daily_streak_pressure',
            'title' => 'Regular play streak pressure',
            'summary' => 'Reward repeat daily participation without making hoarding dominant.',
            'player_fantasy' => 'Consistent players feel their daily rhythm matters.',
            'affected_systems' => ['ubi', 'hoarding_pressure'],
            'primary_strategy' => 'regular',
            'secondary_strategies' => ['hoarder', 'mostly_idle'],
            'counterplay' => ['Spend earlier to avoid becoming an easy hoarding target.'],
            'failure_modes' => ['dominant_strategy_risk', 'hoarding_abuse', 'onboarding_harm'],
            'tunable_parameters' => [
                ['key' => 'base_ubi_active_per_tick', 'kind' => 'existing', 'proposed_value' => 42, 'reason' => 'Model reward pressure.'],
            ],
            'proposed_new_config_keys' => [
                ['key' => 'streak_decay_rate_fp', 'type' => 'int', 'reason' => 'Future runtime key for streak decay.'],
            ],
            'required_metrics' => [
                'viability' => ['archetype_viability_min_ratio'],
                'concentration_or_diversity' => ['strategic_diversity'],
                'mechanic_specific' => ['streak_completion_density'],
            ],
            'archetype_expectations' => [
                'hoarder' => 'down',
                'mostly_idle' => 'down',
                'regular' => 'up',
                'hardcore' => 'flat',
                'boost_focused' => 'flat',
                'star_focused' => 'flat',
                'early_locker' => 'flat',
                'late_deployer' => 'down',
                'casual' => 'up',
                'aggressive_sigil_user' => 'flat',
            ],
        ];
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array)scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->deleteDir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($path);
    }
}
```

- [ ] **Step 2: Run the CLI test and verify it fails**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryCliTest.php --no-coverage
```

Expected: FAIL because `scripts/generate_feature_factory_bundle.php` does not exist.

- [ ] **Step 3: Add the orchestrator**

Create `scripts/feature_factory/FeatureFactory.php`:

```php
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

        $decoded = json_decode((string)file_get_contents($proposalPath), true);
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
```

- [ ] **Step 4: Add the CLI entrypoint**

Create `scripts/generate_feature_factory_bundle.php`:

```php
<?php

require_once __DIR__ . '/feature_factory/FeatureFactory.php';

$options = [
    'proposal' => null,
    'output' => __DIR__ . '/../simulation_output/feature_factory',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--proposal=')) {
        $options['proposal'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Feature Factory Bundle Generator

Usage:
  php scripts/generate_feature_factory_bundle.php --proposal=FILE [--output=DIR]

Inputs:
  --proposal=FILE   Mechanic proposal JSON file.

Options:
  --output=DIR      Output root. Default: simulation_output/feature_factory
  --help            Show this help.

Behavior:
  - writes simulation/config/mechanic scaffolding only
  - blocks runtime/API/frontend/database/deployment/unknown paths before approval
  - validates existing-key candidate templates against the canonical candidate surface
HELP;
        exit(0);
    }
}

if ($options['proposal'] === null || $options['proposal'] === '') {
    fwrite(STDERR, "Missing required --proposal=FILE argument.\n");
    exit(1);
}

try {
    $result = FeatureFactory::generateFromProposalFile((string)$options['proposal'], [
        'output_root' => (string)$options['output'],
    ]);
} catch (FeatureFactoryException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    foreach ($e->details() as $detail) {
        fwrite(STDERR, '- ' . json_encode($detail, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Feature Factory Bundle' . PHP_EOL;
echo 'Mechanic: ' . $result['mechanic_id'] . PHP_EOL;
echo 'Bundle hash: ' . $result['bundle_hash'] . PHP_EOL;
echo 'Candidate validation: ' . $result['candidate_validation']['status'] . PHP_EOL;
echo 'Bundle root: ' . $result['bundle_root'] . PHP_EOL;

exit(0);
```

- [ ] **Step 5: Run the CLI tests and verify they pass**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryCliTest.php --no-coverage
```

Expected: PASS with 2 tests.

- [ ] **Step 6: Commit**

```bash
git add scripts/feature_factory/FeatureFactory.php scripts/generate_feature_factory_bundle.php tests/FeatureFactoryCliTest.php
git commit -m "feat: add feature factory bundle CLI"
```

---

### Task 5: Full Verification

**Files:**
- Read: `docs/superpowers/specs/2026-04-25-two-gate-feature-factory-design.md`
- Read: `docs/superpowers/plans/2026-04-25-two-gate-feature-factory.md`
- Run: PHPUnit feature-factory tests
- Run: candidate/preflight regression tests that protect existing simulation integrity

- [ ] **Step 1: Run the focused feature factory suite**

Run:

```bash
php vendor/bin/phpunit tests/FeatureFactoryBriefTest.php tests/FeatureFactoryGuardrailTest.php tests/FeatureFactoryScaffolderTest.php tests/FeatureFactoryCliTest.php --no-coverage
```

Expected: PASS with 9 tests.

- [ ] **Step 2: Run canonical candidate validation regression tests**

Run:

```bash
php vendor/bin/phpunit tests/EconomicCandidateValidatorTest.php --no-coverage
```

Expected: PASS. This confirms the feature factory still relies on the canonical candidate surface.

- [ ] **Step 3: Run effective-config preflight regression tests**

Run:

```bash
php vendor/bin/phpunit tests/SimulationConfigPreflightTest.php --no-coverage
```

Expected: PASS. This confirms existing simulation preflight and audit artifact behavior is preserved.

- [ ] **Step 4: Run runtime parity certification regression tests**

Run:

```bash
php vendor/bin/phpunit tests/RuntimeParityCertificationTest.php --no-coverage
```

Expected: PASS. This confirms the new scaffolding workflow did not weaken parity assumptions for mechanics.

- [ ] **Step 5: Run full test suite if the focused suites pass**

Run:

```bash
php vendor/bin/phpunit --no-coverage
```

Expected: PASS. If this is too slow for the local session, record the last completed focused suites and the reason the full suite was not run.

- [ ] **Step 6: Final commit**

```bash
git status --short
git log --oneline -n 5
```

Expected: working tree clean after the task commits above. Do not merge, push, deploy, or promote from this plan.

---

## Self-Review Notes

- Spec coverage: Tasks 1-4 cover proposal normalization, scaffolding bundle generation, balance reporting, path classification, approval manifest validation, and CLI workflow. Task 5 covers candidate validation, effective-config preflight, and parity regression protection.
- Scope boundary: The plan creates only feature-factory scaffolding code, tests, and a CLI. It does not modify runtime/API/frontend/deployment/database behavior.
- Type consistency: The plan consistently uses `mechanic_id`, `bundle_hash`, `config_key_status`, `candidate_validation`, `classification`, and `artifact_paths`.
