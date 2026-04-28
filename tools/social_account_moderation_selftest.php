<?php
require_once __DIR__ . '/../includes/permissions.php';

$failures = [];

if (Permissions::roleRank('Player') !== 1) $failures[] = 'player rank';
if (Permissions::roleRank('Moderator') !== 2) $failures[] = 'moderator rank';
if (Permissions::roleRank('Admin') !== 3) $failures[] = 'admin rank';
if (!Permissions::canActOnTarget(['role' => 'Admin'], ['role' => 'Moderator'])) $failures[] = 'admin over moderator';
if (Permissions::canActOnTarget(['role' => 'Moderator'], ['role' => 'Admin'])) $failures[] = 'moderator over admin';
if (Permissions::canActOnTarget(['role' => 'Moderator'], ['role' => 'Moderator'])) $failures[] = 'moderator peer';

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "social-account-moderation-selftest-ok" . PHP_EOL;
