<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/staff_chat.php';

$failures = [];

if (Permissions::roleRank('Player') !== 1) $failures[] = 'player rank';
if (Permissions::roleRank('Moderator') !== 2) $failures[] = 'moderator rank';
if (Permissions::roleRank('Admin') !== 3) $failures[] = 'admin rank';
if (!Permissions::canActOnTarget(['role' => 'Admin'], ['role' => 'Moderator'])) $failures[] = 'admin over moderator';
if (Permissions::canActOnTarget(['role' => 'Moderator'], ['role' => 'Admin'])) $failures[] = 'moderator over admin';
if (Permissions::canActOnTarget(['role' => 'Moderator'], ['role' => 'Moderator'])) $failures[] = 'moderator peer';
if (!class_exists('StaffChatService')) $failures[] = 'staff chat service missing';
if (!method_exists('AccountService', 'requestStaffDeletion')) $failures[] = 'staff delete request missing';
if (!method_exists('AccountService', 'confirmStaffDeletion')) $failures[] = 'staff delete confirm missing';
if (!class_exists('AdminService')) $failures[] = 'admin service missing';
if (!method_exists('AdminService', 'updateRole')) $failures[] = 'admin role update missing';
if (!method_exists('AdminService', 'globalEconomyReset')) $failures[] = 'admin global economy reset missing';
if (!method_exists('AdminService', 'playerEconomyReset')) $failures[] = 'admin player economy reset missing';

$shortPassword = ['current_password' => 'x', 'new_password' => 'abc', 'confirm_password' => 'abc'];
if ($shortPassword['new_password'] !== $shortPassword['confirm_password']) {
    $failures[] = 'password fixture mismatch';
}

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "social-account-moderation-selftest-ok" . PHP_EOL;
