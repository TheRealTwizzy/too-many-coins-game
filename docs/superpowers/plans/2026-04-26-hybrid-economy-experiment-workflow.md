# Hybrid Economy Experiment Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a CLI-first hybrid experiment workflow that turns economy, removal, and tiny QoL/gameplay/UI ideas into guarded capsule artifacts, dual-lane split decisions, experience-gate reports, and compact decision records.

**Architecture:** Add a thin experiment layer on top of the existing feature factory instead of changing runtime gameplay behavior. The new layer normalizes experiment briefs, evaluates tiny player-facing experience changes, generates the existing feature-factory bundle, writes capsule evidence artifacts, and exposes a CLI entrypoint. Runtime/API/frontend/database/deployment changes remain blocked unless a separate approved implementation plan opens a player-facing path.

**Tech Stack:** PHP 8.x, PHPUnit 11, existing feature factory classes, existing canonical economy candidate validator and path approval guardrails.

---

## Execution Lane

Before executing code tasks, make sure work starts from the approved working lane. The current `too-many-coins-game` checkout may be the public sandbox, and `AGENTS.md` says feature work starts in `source/dev`.

Recommended execution setup:

```powershell
git status --short
git branch --show-current
```

If the checkout is not the source/dev working lane, create or switch to an isolated worktree from source/dev before Task 1. Do not apply implementation commits directly to sandbox/live deployment lanes.

---

## File Structure

- Create `scripts/feature_factory/ExperimentBrief.php`: normalize hybrid experiment proposals into a mechanic-compatible proposal plus experiment metadata.
- Create `scripts/feature_factory/ExperienceGate.php`: evaluate tiny gameplay/UI/QoL plans, player-facing path approval, reversibility, and dual-lane split triggers.
- Create `scripts/feature_factory/ExperimentCapsuleRunner.php`: orchestrate experiment brief normalization, feature-factory bundle generation, experience gate evaluation, and decision record artifact writing.
- Create `scripts/generate_experiment_capsule.php`: CLI entrypoint for the hybrid workflow.
- Create `tests/ExperimentBriefTest.php`: experiment brief validation and mode recommendation tests.
- Create `tests/ExperienceGateTest.php`: player-facing path, approval, reversibility, and split decision tests.
- Create `tests/ExperimentCapsuleRunnerTest.php`: bundle artifact and decision record tests.
- Create `tests/ExperimentCapsuleCliTest.php`: CLI smoke tests.

Existing files that may be modified:

- `scripts/feature_factory/FeatureFactory.php`: only if the runner needs a small public helper; prefer no change.
- `scripts/feature_factory/MechanicScaffolder.php`: only if artifact metadata needs a stable extension point; prefer writing experiment artifacts from the runner.

No runtime, API, frontend, database, migration, deployment, sandbox, or live environment files are modified by this plan.

---

### Task 1: Experiment Brief Validation

**Files:**
- Create: `scripts/feature_factory/ExperimentBrief.php`
- Test: `tests/ExperimentBriefTest.php`

- [ ] **Step 1: Write the failing experiment brief tests**

Create `tests/ExperimentBriefTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/ExperimentBrief.php';

class ExperimentBriefTest extends TestCase
{
    public function testCombinedCapsuleNormalizesExperimentAndMechanicProposal(): void
    {
        $brief = ExperimentBrief::fromProposal($this->proposal());

        $this->assertSame('streak_pressure_qol_capsule', $brief['experiment_id']);
        $this->assertSame('capsule', $brief['mode_preference']);
        $this->assertSame('capsule', $brief['recommended_mode']);
        $this->assertSame('combined', $brief['change_intent']);
        $this->assertSame('daily_streak_pressure', $brief['mechanic_brief']['mechanic_id']);
        $this->assertSame('daily_streak_pressure', $brief['mechanic_proposal']['mechanic_id']);
        $this->assertCount(1, $brief['experience_changes']);
        $this->assertSame('public/js/app.js', $brief['experience_changes'][0]['path']);
        $this->assertSame([], $brief['mode_decision_reasons']);
    }

    public function testTooManyCapsuleExperienceChangesRecommendDualLane(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'] = [
            $this->experienceChange('public/js/app.js', 'HUD copy polish'),
            $this->experienceChange('public/css/style.css', 'HUD spacing polish'),
            $this->experienceChange('public/index.html', 'View label polish'),
            $this->experienceChange('api/index.php', 'Read-only response label polish'),
        ];

        $brief = ExperimentBrief::fromProposal($proposal);

        $this->assertSame('capsule', $brief['mode_preference']);
        $this->assertSame('dual_lane', $brief['recommended_mode']);
        $this->assertSame(['too_many_experience_changes_for_capsule'], array_column($brief['mode_decision_reasons'], 'reason_code'));
    }

    public function testRemovalIntentRequiresRemovalTargetAndRollbackNotes(): void
    {
        $proposal = $this->proposal();
        $proposal['change_intent'] = 'removal';
        unset($proposal['removal_target'], $proposal['rollback_notes']);

        try {
            ExperimentBrief::fromProposal($proposal);
            $this->fail('Expected removal validation to fail.');
        } catch (FeatureFactoryException $e) {
            $codes = array_column($e->details(), 'reason_code');
            $this->assertContains('missing_removal_target', $codes);
            $this->assertContains('missing_rollback_notes', $codes);
        }
    }

    public function testInvalidModePreferenceIsRejected(): void
    {
        $proposal = $this->proposal();
        $proposal['mode_preference'] = 'fast_track';

        $this->expectException(FeatureFactoryException::class);
        $this->expectExceptionMessage('Experiment brief validation failed');

        ExperimentBrief::fromProposal($proposal);
    }

    private function proposal(): array
    {
        return [
            'experiment_id' => 'streak_pressure_qol_capsule',
            'mode_preference' => 'capsule',
            'change_intent' => 'combined',
            'economy_hypothesis' => 'Slightly stronger active UBI can improve regular-player viability without making hoarding dominant.',
            'player_facing_intent' => 'Make the daily rhythm easier to understand.',
            'rollback_notes' => 'Revert the candidate patch and remove the HUD copy polish from the capsule bundle.',
            'experience_changes' => [
                $this->experienceChange('public/js/app.js', 'Clarify the daily rhythm HUD label.'),
            ],
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

    private function experienceChange(string $path, string $summary): array
    {
        return [
            'path' => $path,
            'change_type' => 'hud_readability',
            'summary' => $summary,
            'economy_behavior' => 'none',
            'reversible' => true,
            'qa_evidence' => ['manual_hud_smoke'],
        ];
    }
}
```

- [ ] **Step 2: Run the experiment brief tests and verify they fail**

Run:

```powershell
php vendor/bin/phpunit tests/ExperimentBriefTest.php --no-coverage
```

Expected: FAIL because `scripts/feature_factory/ExperimentBrief.php` does not exist.

- [ ] **Step 3: Add experiment brief normalization**

Create `scripts/feature_factory/ExperimentBrief.php`:

```php
<?php

require_once __DIR__ . '/FeatureFactoryException.php';
require_once __DIR__ . '/MechanicBrief.php';

class ExperimentBrief
{
    private const MODES = ['capsule', 'dual_lane'];
    private const CHANGE_INTENTS = ['mechanic_addition', 'removal', 'disable', 'nerf', 'rebalance', 'qol', 'combined'];
    private const MECHANIC_PROPOSAL_FIELDS = [
        'mechanic_id',
        'title',
        'summary',
        'player_fantasy',
        'affected_systems',
        'primary_strategy',
        'secondary_strategies',
        'counterplay',
        'failure_modes',
        'tunable_parameters',
        'proposed_new_config_keys',
        'required_metrics',
        'archetype_expectations',
    ];

    public static function fromProposal(array $proposal): array
    {
        $failures = self::validateExperimentFields($proposal);
        $mechanicFailures = MechanicBrief::validate($proposal);
        foreach ($mechanicFailures as $failure) {
            $failure['path'] = 'mechanic.' . (string)($failure['path'] ?? 'unknown');
            $failures[] = $failure;
        }

        if ($failures !== []) {
            throw new FeatureFactoryException('Experiment brief validation failed', $failures);
        }

        $mechanicProposal = self::mechanicProposal($proposal);
        $mechanicBrief = MechanicBrief::fromProposal($mechanicProposal);
        $experienceChanges = self::normalizeExperienceChanges((array)($proposal['experience_changes'] ?? []));
        $modeDecisionReasons = self::modeDecisionReasons((string)($proposal['mode_preference'] ?? 'capsule'), $experienceChanges);

        return [
            'schema_version' => 'tmc-experiment-brief.v1',
            'experiment_id' => self::cleanString($proposal['experiment_id'] ?? $proposal['mechanic_id']),
            'mode_preference' => self::cleanString($proposal['mode_preference'] ?? 'capsule'),
            'recommended_mode' => $modeDecisionReasons === [] ? self::cleanString($proposal['mode_preference'] ?? 'capsule') : 'dual_lane',
            'mode_decision_reasons' => $modeDecisionReasons,
            'change_intent' => self::cleanString($proposal['change_intent'] ?? 'mechanic_addition'),
            'economy_hypothesis' => self::cleanString($proposal['economy_hypothesis']),
            'player_facing_intent' => self::cleanString($proposal['player_facing_intent'] ?? ''),
            'rollback_notes' => self::cleanString($proposal['rollback_notes']),
            'removal_target' => self::normalizeRemovalTarget((array)($proposal['removal_target'] ?? [])),
            'experience_changes' => $experienceChanges,
            'mechanic_proposal' => $mechanicProposal,
            'mechanic_brief' => $mechanicBrief,
        ];
    }

    private static function validateExperimentFields(array $proposal): array
    {
        $failures = [];
        $experimentId = self::cleanString($proposal['experiment_id'] ?? $proposal['mechanic_id'] ?? '');
        if ($experimentId === '' || !preg_match('/^[a-z][a-z0-9_]{2,79}$/', $experimentId)) {
            $failures[] = self::failure('experiment_id', 'invalid_experiment_id', 'Use lowercase snake_case, 3-80 characters.');
        }

        $mode = self::cleanString($proposal['mode_preference'] ?? 'capsule');
        if (!in_array($mode, self::MODES, true)) {
            $failures[] = self::failure('mode_preference', 'invalid_mode_preference', 'Mode preference must be capsule or dual_lane.');
        }

        $intent = self::cleanString($proposal['change_intent'] ?? 'mechanic_addition');
        if (!in_array($intent, self::CHANGE_INTENTS, true)) {
            $failures[] = self::failure('change_intent', 'invalid_change_intent', 'Change intent is not supported by the hybrid workflow.');
        }

        if (self::cleanString($proposal['economy_hypothesis'] ?? '') === '') {
            $failures[] = self::failure('economy_hypothesis', 'missing_economy_hypothesis', 'Every experiment needs an economy hypothesis.');
        }

        if (self::cleanString($proposal['rollback_notes'] ?? '') === '') {
            $failures[] = self::failure('rollback_notes', 'missing_rollback_notes', 'Every experiment needs rollback notes.');
        }

        if (in_array($intent, ['removal', 'disable', 'nerf'], true)) {
            $target = (array)($proposal['removal_target'] ?? []);
            if (self::cleanString($target['name'] ?? '') === '') {
                $failures[] = self::failure('removal_target.name', 'missing_removal_target', 'Removal, disable, and nerf experiments need a target name.');
            }
            if (self::cleanString($target['suspected_harm'] ?? '') === '') {
                $failures[] = self::failure('removal_target.suspected_harm', 'missing_removal_harm', 'Removal, disable, and nerf experiments need suspected harm.');
            }
        }

        foreach ((array)($proposal['experience_changes'] ?? []) as $index => $change) {
            if (!is_array($change)) {
                $failures[] = self::failure('experience_changes[' . $index . ']', 'invalid_experience_change', 'Experience change must be an object.');
                continue;
            }
            if (self::cleanString($change['path'] ?? '') === '') {
                $failures[] = self::failure('experience_changes[' . $index . '].path', 'missing_experience_path', 'Experience change path is required.');
            }
            if (self::cleanString($change['summary'] ?? '') === '') {
                $failures[] = self::failure('experience_changes[' . $index . '].summary', 'missing_experience_summary', 'Experience change summary is required.');
            }
        }

        return $failures;
    }

    private static function mechanicProposal(array $proposal): array
    {
        $mechanicProposal = [];
        foreach (self::MECHANIC_PROPOSAL_FIELDS as $field) {
            if (array_key_exists($field, $proposal)) {
                $mechanicProposal[$field] = $proposal[$field];
            }
        }
        return $mechanicProposal;
    }

    private static function normalizeExperienceChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $change) {
            $normalized[] = [
                'path' => self::normalizePath((string)($change['path'] ?? '')),
                'change_type' => self::cleanString($change['change_type'] ?? 'quality_of_life'),
                'summary' => self::cleanString($change['summary'] ?? ''),
                'economy_behavior' => self::cleanString($change['economy_behavior'] ?? 'none'),
                'reversible' => (bool)($change['reversible'] ?? false),
                'qa_evidence' => array_values((array)($change['qa_evidence'] ?? [])),
            ];
        }
        return $normalized;
    }

    private static function normalizeRemovalTarget(array $target): array
    {
        if ($target === []) {
            return [];
        }

        return [
            'name' => self::cleanString($target['name'] ?? ''),
            'target_type' => self::cleanString($target['target_type'] ?? 'mechanic'),
            'suspected_harm' => self::cleanString($target['suspected_harm'] ?? ''),
            'replacement_behavior' => self::cleanString($target['replacement_behavior'] ?? ''),
        ];
    }

    private static function modeDecisionReasons(string $modePreference, array $experienceChanges): array
    {
        $reasons = [];
        if ($modePreference === 'capsule' && count($experienceChanges) > 3) {
            $reasons[] = self::failure('experience_changes', 'too_many_experience_changes_for_capsule', 'Capsule mode allows at most three tiny experience changes.');
        }
        return $reasons;
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

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
```

- [ ] **Step 4: Run the experiment brief tests and verify they pass**

Run:

```powershell
php vendor/bin/phpunit tests/ExperimentBriefTest.php --no-coverage
```

Expected: PASS with 4 tests.

- [ ] **Step 5: Commit**

Run:

```powershell
git add scripts/feature_factory/ExperimentBrief.php tests/ExperimentBriefTest.php
git commit -m "feat: add hybrid experiment brief validation"
```

---

### Task 2: Experience Gate

**Files:**
- Create: `scripts/feature_factory/ExperienceGate.php`
- Test: `tests/ExperienceGateTest.php`

- [ ] **Step 1: Write the failing experience gate tests**

Create `tests/ExperienceGateTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/ExperimentBrief.php';
require_once __DIR__ . '/../scripts/feature_factory/ExperienceGate.php';

class ExperienceGateTest extends TestCase
{
    public function testExperienceChangeWithoutApprovalIsPendingApproval(): void
    {
        $brief = ExperimentBrief::fromProposal($this->proposal());

        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('pending_approval', $report['status']);
        $this->assertSame(['experience_path_requires_approval'], array_column($report['issues'], 'reason_code'));
    }

    public function testApprovedTinyFrontendChangePasses(): void
    {
        $brief = ExperimentBrief::fromProposal($this->proposal());
        $manifest = [
            'schema_version' => 'tmc-feature-approval.v1',
            'mechanic_id' => 'daily_streak_pressure',
            'bundle_hash' => 'abc123',
            'allowed_path_classes' => ['frontend'],
            'allowed_paths' => ['public/js/app.js'],
            'approval_reason' => 'Tiny HUD readability polish approved for capsule testing.',
            'approver' => 'trent',
            'approved_at' => '2026-04-26T12:00:00Z',
        ];

        $report = ExperienceGate::evaluate($brief, $manifest, 'abc123');

        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['issues']);
    }

    public function testRuntimePathRequiresDualLane(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'][0]['path'] = 'includes/economy.php';

        $brief = ExperimentBrief::fromProposal($proposal);
        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('split_to_dual_lane', $report['status']);
        $this->assertSame(['experience_core_loop_path_requires_dual_lane'], array_column($report['issues'], 'reason_code'));
    }

    public function testNonReversibleChangeFails(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'][0]['reversible'] = false;

        $brief = ExperimentBrief::fromProposal($proposal);
        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('fail', $report['status']);
        $this->assertSame(['experience_change_not_reversible'], array_column($report['issues'], 'reason_code'));
    }

    public function testEconomyBehaviorInExperienceChangeFails(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'][0]['economy_behavior'] = 'changes_lock_in_timing';

        $brief = ExperimentBrief::fromProposal($proposal);
        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('fail', $report['status']);
        $this->assertSame(['experience_change_alters_economy_behavior'], array_column($report['issues'], 'reason_code'));
    }

    private function proposal(): array
    {
        return [
            'experiment_id' => 'streak_pressure_qol_capsule',
            'mode_preference' => 'capsule',
            'change_intent' => 'combined',
            'economy_hypothesis' => 'Slightly stronger active UBI can improve regular-player viability without making hoarding dominant.',
            'player_facing_intent' => 'Make the daily rhythm easier to understand.',
            'rollback_notes' => 'Revert the candidate patch and remove the HUD copy polish from the capsule bundle.',
            'experience_changes' => [[
                'path' => 'public/js/app.js',
                'change_type' => 'hud_readability',
                'summary' => 'Clarify the daily rhythm HUD label.',
                'economy_behavior' => 'none',
                'reversible' => true,
                'qa_evidence' => ['manual_hud_smoke'],
            ]],
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
}
```

- [ ] **Step 2: Run the experience gate tests and verify they fail**

Run:

```powershell
php vendor/bin/phpunit tests/ExperienceGateTest.php --no-coverage
```

Expected: FAIL because `scripts/feature_factory/ExperienceGate.php` does not exist.

- [ ] **Step 3: Add the experience gate implementation**

Create `scripts/feature_factory/ExperienceGate.php`:

```php
<?php

require_once __DIR__ . '/ApprovalManifest.php';
require_once __DIR__ . '/FeaturePatchClassifier.php';

class ExperienceGate
{
    public static function evaluate(array $experimentBrief, ?array $approvalManifest = null, ?string $bundleHash = null): array
    {
        $changes = array_values((array)($experimentBrief['experience_changes'] ?? []));
        if ($changes === []) {
            return self::report('pass', [], []);
        }

        $issues = [];
        $paths = [];
        foreach ($changes as $index => $change) {
            $path = self::normalizePath((string)($change['path'] ?? ''));
            $paths[] = $path;

            if (empty($change['reversible'])) {
                $issues[] = self::issue('experience_changes[' . $index . '].reversible', 'experience_change_not_reversible', 'Experience changes must be reversible.');
            }

            if ((string)($change['economy_behavior'] ?? 'none') !== 'none') {
                $issues[] = self::issue('experience_changes[' . $index . '].economy_behavior', 'experience_change_alters_economy_behavior', 'QoL experience changes cannot alter economy behavior.');
            }

            if (count((array)($change['qa_evidence'] ?? [])) === 0) {
                $issues[] = self::issue('experience_changes[' . $index . '].qa_evidence', 'experience_change_missing_qa_evidence', 'Experience changes need at least one QA evidence label.');
            }
        }

        $classification = FeaturePatchClassifier::classifyPaths($paths);
        foreach ((array)$classification['paths'] as $row) {
            $class = (string)($row['class'] ?? 'unknown');
            if (in_array($class, ['runtime', 'database', 'deployment', 'unknown'], true)) {
                $issues[] = self::issue((string)$row['path'], 'experience_core_loop_path_requires_dual_lane', 'Capsule experience changes cannot touch runtime, database, deployment, or unknown paths.');
            }
        }

        if (self::hasIssue($issues, 'experience_change_not_reversible') || self::hasIssue($issues, 'experience_change_alters_economy_behavior') || self::hasIssue($issues, 'experience_change_missing_qa_evidence')) {
            return self::report('fail', $issues, $classification);
        }

        if (self::hasIssue($issues, 'experience_core_loop_path_requires_dual_lane')) {
            return self::report('split_to_dual_lane', $issues, $classification);
        }

        if ($approvalManifest === null) {
            foreach ((array)$classification['paths'] as $row) {
                $issues[] = self::issue((string)$row['path'], 'experience_path_requires_approval', 'Player-facing experience path needs approval before implementation.');
            }
            return self::report('pending_approval', $issues, $classification);
        }

        $approvalFailures = ApprovalManifest::validateForPaths(
            $approvalManifest,
            (string)($experimentBrief['mechanic_brief']['mechanic_id'] ?? ''),
            (string)$bundleHash,
            $classification
        );

        foreach ($approvalFailures as $failure) {
            $issues[] = $failure;
        }

        return self::report($issues === [] ? 'pass' : 'fail', $issues, $classification);
    }

    private static function report(string $status, array $issues, array $classification): array
    {
        return [
            'schema_version' => 'tmc-experience-gate-report.v1',
            'status' => $status,
            'issues' => array_values($issues),
            'classification' => $classification,
        ];
    }

    private static function issue(string $path, string $code, string $detail): array
    {
        return [
            'path' => $path,
            'reason_code' => $code,
            'reason_detail' => $detail,
        ];
    }

    private static function hasIssue(array $issues, string $code): bool
    {
        foreach ($issues as $issue) {
            if (($issue['reason_code'] ?? '') === $code) {
                return true;
            }
        }
        return false;
    }

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
```

- [ ] **Step 4: Run the experience gate tests and verify they pass**

Run:

```powershell
php vendor/bin/phpunit tests/ExperienceGateTest.php --no-coverage
```

Expected: PASS with 5 tests.

- [ ] **Step 5: Commit**

Run:

```powershell
git add scripts/feature_factory/ExperienceGate.php tests/ExperienceGateTest.php
git commit -m "feat: add hybrid experience gate"
```

---

### Task 3: Experiment Capsule Runner

**Files:**
- Create: `scripts/feature_factory/ExperimentCapsuleRunner.php`
- Test: `tests/ExperimentCapsuleRunnerTest.php`

- [ ] **Step 1: Write the failing capsule runner tests**

Create `tests/ExperimentCapsuleRunnerTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/ExperimentCapsuleRunner.php';

class ExperimentCapsuleRunnerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_experiment_capsule_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testEconomyOnlyCapsuleWritesExperimentArtifactsAndPassDecision(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'] = [];

        $result = ExperimentCapsuleRunner::generate($proposal, [
            'output_root' => $this->tempDir,
        ]);

        $this->assertSame('streak_pressure_qol_capsule', $result['experiment_id']);
        $this->assertSame('pass', $result['decision_record']['status']);
        $this->assertFileExists($result['experiment_artifact_paths']['experiment_brief_json']);
        $this->assertFileExists($result['experiment_artifact_paths']['experience_gate_report_json']);
        $this->assertFileExists($result['experiment_artifact_paths']['decision_record_json']);
    }

    public function testCapsuleWithUnapprovedExperienceChangeNeedsRevision(): void
    {
        $result = ExperimentCapsuleRunner::generate($this->proposal(), [
            'output_root' => $this->tempDir,
        ]);

        $this->assertSame('revise', $result['decision_record']['status']);
        $this->assertSame('pending_approval', $result['experience_gate_report']['status']);
    }

    public function testCapsuleWithApprovedExperienceChangePasses(): void
    {
        $result = ExperimentCapsuleRunner::generate($this->proposal(), [
            'output_root' => $this->tempDir,
            'approval_manifest' => [
                'schema_version' => 'tmc-feature-approval.v1',
                'mechanic_id' => 'daily_streak_pressure',
                'bundle_hash' => 'deferred',
                'allowed_path_classes' => ['frontend'],
                'allowed_paths' => ['public/js/app.js'],
                'approval_reason' => 'Tiny HUD readability polish approved for capsule testing.',
                'approver' => 'trent',
                'approved_at' => '2026-04-26T12:00:00Z',
            ],
            'approval_manifest_bundle_hash_mode' => 'use_generated_hash',
        ]);

        $this->assertSame('pass', $result['decision_record']['status']);
        $this->assertSame('pass', $result['experience_gate_report']['status']);
    }

    public function testTooBroadCapsuleSplitsToDualLane(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'] = [
            $this->experienceChange('public/js/app.js', 'HUD copy polish'),
            $this->experienceChange('public/css/style.css', 'HUD spacing polish'),
            $this->experienceChange('public/index.html', 'View label polish'),
            $this->experienceChange('api/index.php', 'Read-only response label polish'),
        ];

        $result = ExperimentCapsuleRunner::generate($proposal, [
            'output_root' => $this->tempDir,
        ]);

        $this->assertSame('split_to_dual_lane', $result['decision_record']['status']);
        $this->assertSame('dual_lane', $result['experiment_brief']['recommended_mode']);
    }

    private function proposal(): array
    {
        return [
            'experiment_id' => 'streak_pressure_qol_capsule',
            'mode_preference' => 'capsule',
            'change_intent' => 'combined',
            'economy_hypothesis' => 'Slightly stronger active UBI can improve regular-player viability without making hoarding dominant.',
            'player_facing_intent' => 'Make the daily rhythm easier to understand.',
            'rollback_notes' => 'Revert the candidate patch and remove the HUD copy polish from the capsule bundle.',
            'experience_changes' => [
                $this->experienceChange('public/js/app.js', 'Clarify the daily rhythm HUD label.'),
            ],
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

    private function experienceChange(string $path, string $summary): array
    {
        return [
            'path' => $path,
            'change_type' => 'hud_readability',
            'summary' => $summary,
            'economy_behavior' => 'none',
            'reversible' => true,
            'qa_evidence' => ['manual_hud_smoke'],
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

- [ ] **Step 2: Run the capsule runner tests and verify they fail**

Run:

```powershell
php vendor/bin/phpunit tests/ExperimentCapsuleRunnerTest.php --no-coverage
```

Expected: FAIL because `scripts/feature_factory/ExperimentCapsuleRunner.php` does not exist.

- [ ] **Step 3: Add the capsule runner implementation**

Create `scripts/feature_factory/ExperimentCapsuleRunner.php`:

```php
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
```

- [ ] **Step 4: Run the capsule runner tests and verify they pass**

Run:

```powershell
php vendor/bin/phpunit tests/ExperimentCapsuleRunnerTest.php --no-coverage
```

Expected: PASS with 4 tests.

- [ ] **Step 5: Commit**

Run:

```powershell
git add scripts/feature_factory/ExperimentCapsuleRunner.php tests/ExperimentCapsuleRunnerTest.php
git commit -m "feat: add experiment capsule runner"
```

---

### Task 4: Experiment Capsule CLI

**Files:**
- Create: `scripts/generate_experiment_capsule.php`
- Test: `tests/ExperimentCapsuleCliTest.php`

- [ ] **Step 1: Write the failing CLI tests**

Create `tests/ExperimentCapsuleCliTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class ExperimentCapsuleCliTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_experiment_capsule_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testCliGeneratesExperimentCapsule(): void
    {
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'proposal.json';
        file_put_contents($proposalPath, json_encode($this->proposal(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_experiment_capsule.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'bundles');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('Experiment Capsule', implode(PHP_EOL, $output));
        $this->assertStringContainsString('Decision: revise', implode(PHP_EOL, $output));
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'bundles' . DIRECTORY_SEPARATOR . 'daily_streak_pressure' . DIRECTORY_SEPARATOR . 'decision_record.json');
    }

    public function testCliFailsForInvalidExperimentProposal(): void
    {
        $proposal = $this->proposal();
        $proposal['mode_preference'] = 'fast_track';
        $proposalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid.json';
        file_put_contents($proposalPath, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate_experiment_capsule.php')
            . ' --proposal=' . escapeshellarg($proposalPath)
            . ' --output=' . escapeshellarg($this->tempDir . DIRECTORY_SEPARATOR . 'invalid-bundles');

        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(1, $exitCode, implode(PHP_EOL, $output));
        $this->assertStringContainsString('Experiment brief validation failed', implode(PHP_EOL, $output));
        $this->assertStringContainsString('invalid_mode_preference', implode(PHP_EOL, $output));
    }

    private function proposal(): array
    {
        return [
            'experiment_id' => 'streak_pressure_qol_capsule',
            'mode_preference' => 'capsule',
            'change_intent' => 'combined',
            'economy_hypothesis' => 'Slightly stronger active UBI can improve regular-player viability without making hoarding dominant.',
            'player_facing_intent' => 'Make the daily rhythm easier to understand.',
            'rollback_notes' => 'Revert the candidate patch and remove the HUD copy polish from the capsule bundle.',
            'experience_changes' => [[
                'path' => 'public/js/app.js',
                'change_type' => 'hud_readability',
                'summary' => 'Clarify the daily rhythm HUD label.',
                'economy_behavior' => 'none',
                'reversible' => true,
                'qa_evidence' => ['manual_hud_smoke'],
            ]],
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

- [ ] **Step 2: Run the CLI tests and verify they fail**

Run:

```powershell
php vendor/bin/phpunit tests/ExperimentCapsuleCliTest.php --no-coverage
```

Expected: FAIL because `scripts/generate_experiment_capsule.php` does not exist.

- [ ] **Step 3: Add the CLI entrypoint**

Create `scripts/generate_experiment_capsule.php`:

```php
<?php

require_once __DIR__ . '/feature_factory/ExperimentCapsuleRunner.php';

$options = [
    'proposal' => null,
    'output' => __DIR__ . '/../simulation_output/feature_factory',
    'approval' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--proposal=')) {
        $options['proposal'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--approval=')) {
        $options['approval'] = substr($arg, 11);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Experiment Capsule Generator

Usage:
  php scripts/generate_experiment_capsule.php --proposal=FILE [--output=DIR] [--approval=FILE]

Inputs:
  --proposal=FILE   Hybrid experiment proposal JSON file.
  --approval=FILE   Optional approval manifest JSON for tiny player-facing paths.

Options:
  --output=DIR      Output root. Default: simulation_output/feature_factory
  --help            Show this help.

Behavior:
  - starts every experiment as a capsule
  - generates the existing feature-factory bundle
  - writes experiment_brief.json, experience_gate_report.json, and decision_record.json
  - marks broad or coupled capsules as split_to_dual_lane
  - blocks player-facing implementation readiness without approval
HELP;
        exit(0);
    }
}

if ($options['proposal'] === null || $options['proposal'] === '') {
    fwrite(STDERR, "Missing required --proposal=FILE argument.\n");
    exit(1);
}

$runnerOptions = [
    'output_root' => (string)$options['output'],
];

if ($options['approval'] !== null && $options['approval'] !== '') {
    $approvalJson = (string)file_get_contents((string)$options['approval']);
    $approval = json_decode($approvalJson, true);
    if (!is_array($approval)) {
        fwrite(STDERR, "Approval manifest JSON must decode to an object.\n");
        exit(1);
    }
    $runnerOptions['approval_manifest'] = $approval;
    $runnerOptions['approval_manifest_bundle_hash_mode'] = 'use_generated_hash';
}

try {
    $result = ExperimentCapsuleRunner::generateFromProposalFile((string)$options['proposal'], $runnerOptions);
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

echo 'Experiment Capsule' . PHP_EOL;
echo 'Experiment: ' . $result['experiment_id'] . PHP_EOL;
echo 'Mechanic: ' . $result['mechanic_id'] . PHP_EOL;
echo 'Bundle hash: ' . $result['bundle_hash'] . PHP_EOL;
echo 'Candidate validation: ' . $result['candidate_validation']['status'] . PHP_EOL;
echo 'Experience gate: ' . $result['experience_gate_report']['status'] . PHP_EOL;
echo 'Decision: ' . $result['decision_record']['status'] . PHP_EOL;
echo 'Bundle root: ' . $result['bundle_root'] . PHP_EOL;

exit(0);
```

- [ ] **Step 4: Run the CLI tests and verify they pass**

Run:

```powershell
php vendor/bin/phpunit tests/ExperimentCapsuleCliTest.php --no-coverage
```

Expected: PASS with 2 tests.

- [ ] **Step 5: Commit**

Run:

```powershell
git add scripts/generate_experiment_capsule.php tests/ExperimentCapsuleCliTest.php
git commit -m "feat: add experiment capsule CLI"
```

---

### Task 5: Full Verification

**Files:**
- Read: `docs/superpowers/specs/2026-04-26-hybrid-economy-experiment-workflow-design.md`
- Read: `docs/superpowers/plans/2026-04-26-hybrid-economy-experiment-workflow.md`
- Run: focused hybrid workflow tests
- Run: existing feature factory and config-integrity regressions

- [ ] **Step 1: Run the focused hybrid workflow tests**

Run:

```powershell
php vendor/bin/phpunit tests/ExperimentBriefTest.php tests/ExperienceGateTest.php tests/ExperimentCapsuleRunnerTest.php tests/ExperimentCapsuleCliTest.php --no-coverage
```

Expected: PASS with 15 tests.

- [ ] **Step 2: Run the existing feature factory tests**

Run:

```powershell
php vendor/bin/phpunit tests/FeatureFactoryBriefTest.php tests/FeatureFactoryGuardrailTest.php tests/FeatureFactoryScaffolderTest.php tests/FeatureFactoryCliTest.php --no-coverage
```

Expected: PASS with 10 tests.

- [ ] **Step 3: Run canonical candidate validation regressions**

Run:

```powershell
php vendor/bin/phpunit tests/EconomicCandidateValidatorTest.php --no-coverage
```

Expected: PASS with 11 tests.

- [ ] **Step 4: Run effective-config preflight regressions**

Run:

```powershell
php vendor/bin/phpunit tests/SimulationConfigPreflightTest.php --no-coverage
```

Expected: PASS with 9 tests.

- [ ] **Step 5: Run runtime parity certification regressions**

Run:

```powershell
php vendor/bin/phpunit tests/RuntimeParityCertificationTest.php --no-coverage
```

Expected: PASS. This confirms the new workflow did not weaken parity assumptions.

- [ ] **Step 6: Run the full test suite**

Run:

```powershell
php vendor/bin/phpunit --no-coverage
```

Expected: PASS. If this is too slow for the execution session, record which focused suites passed and the exact reason the full suite was not completed.

- [ ] **Step 7: Inspect final git state**

Run:

```powershell
git status --short
git log --oneline -n 8
```

Expected: working tree clean after the task commits. Do not merge, push, deploy, or promote from this plan.

---

## Self-Review Notes

- Spec coverage: Tasks 1-4 implement experiment brief normalization, capsule mode orchestration, experience gating, dual-lane split metadata, decision-record output, and the CLI-first entrypoint. Task 5 verifies existing feature-factory, candidate-validation, effective-config, and parity guardrails.
- Scope boundary: The plan does not modify runtime, API, frontend, database, migration, deployment, sandbox, or live environment behavior.
- Type consistency: The plan consistently uses `experiment_id`, `mode_preference`, `recommended_mode`, `change_intent`, `experience_changes`, `experience_gate_report`, and `decision_record`.
- Release discipline: Implementation must begin from source/dev or an isolated worktree from source/dev before code tasks execute.
