<?php
/**
 * Seeds one active staff account per production role so the Production Board
 * can be demonstrated end to end.
 *
 * Run from the project root:  php scripts/seed_staff_roles.php
 *
 * Safe to re-run: existing usernames keep their password and are only
 * re-pointed at the right production role and set active.
 */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/staff_roles.php';

const SEED_PASSWORD = 'staff123';

$seeds = [
    ['username' => 'staff_production', 'full_name' => 'Mika Torres',   'email' => 'mika.torres@brilleamour.test',   'staff_role' => 'production'],
    ['username' => 'staff_quality',    'full_name' => 'Dana Reyes',    'email' => 'dana.reyes@brilleamour.test',    'staff_role' => 'quality_check'],
];

if (!ensureStaffRoleColumn($conn)) {
    fwrite(STDERR, "Could not create users.staff_role - import database/add_staff_role.sql first.\n");
    exit(1);
}

foreach ($seeds as $seed) {
    if (!isValidStaffRole($seed['staff_role'])) {
        fwrite(STDERR, "Unknown production role: {$seed['staff_role']}\n");
        exit(1);
    }

    $find = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $find->bind_param('s', $seed['username']);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();

    if ($existing) {
        $update = $conn->prepare("UPDATE users SET full_name=?, email=?, role='staff', staff_role=?, status='active' WHERE id=?");
        $update->bind_param('sssi', $seed['full_name'], $seed['email'], $seed['staff_role'], $existing['id']);
        $ok = $update->execute();
        $update->close();
        $verb = 'updated';
    } else {
        $hash = password_hash(SEED_PASSWORD, PASSWORD_DEFAULT);
        $insert = $conn->prepare("INSERT INTO users (username,password,full_name,email,role,staff_role,status) VALUES (?,?,?,?,'staff',?,'active')");
        $insert->bind_param('sssss', $seed['username'], $hash, $seed['full_name'], $seed['email'], $seed['staff_role']);
        $ok = $insert->execute();
        $insert->close();
        $verb = 'created';
    }

    if (!$ok) {
        fwrite(STDERR, "Failed to seed {$seed['username']}: {$conn->error}\n");
        exit(1);
    }

    printf(
        "%-9s %-18s %-22s owns %s%s",
        $verb,
        $seed['username'],
        staffRoleLabel($seed['staff_role']),
        staffRoleStageSummary($seed['staff_role']),
        PHP_EOL
    );
}

echo PHP_EOL . 'Password for newly created accounts: ' . SEED_PASSWORD . PHP_EOL;
