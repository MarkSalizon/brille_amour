<?php
/**
 * Production roles for staff accounts.
 *
 * Every staff member owns one production role, chosen at registration. The role
 * decides which Production Board columns that person may move orders through -
 * all other columns are read-only for them.
 */

require_once __DIR__ . '/order_workflow.php';

function getStaffRoles(): array {
    return [
        'production' => [
            'label'   => 'Production Artisan',
            'tagline' => 'Builds the order - cutting, assembly and fitting',
            'icon'    => 'PRD',
            'color'   => '#3B82F6',
            'stages'  => ['in_production'],
            'duties'  => [
                'Work only on verified orders released to In Production',
                'Place active production on hold or resume it when needed',
                'Send completed production work to Quality Check',
            ],
        ],
        'quality_check' => [
            'label'   => 'Quality Inspector',
            'tagline' => 'Checks every piece before it is cleared',
            'icon'    => 'QC',
            'color'   => '#8B5CF6',
            'stages'  => ['quality_check'],
            'duties'  => [
                'Inspect finish, alignment and lens clarity',
                'Approve passing orders as Ready for pickup',
                'Return failed checks to Production with a reason',
            ],
        ],
    ];
}

function isValidStaffRole(?string $role): bool {
    return $role !== null && array_key_exists($role, getStaffRoles());
}

function staffRoleLabel(?string $role): string {
    return getStaffRoles()[$role]['label'] ?? 'Unassigned';
}

function staffRoleIcon(?string $role): string {
    return getStaffRoles()[$role]['icon'] ?? '-';
}

function staffRoleColor(?string $role): string {
    return getStaffRoles()[$role]['color'] ?? '#9CA3AF';
}

/** Production Board stages the role is responsible for. */
function staffRoleStages(?string $role): array {
    return getStaffRoles()[$role]['stages'] ?? [];
}

function staffRoleOwnsStage(?string $role, string $stage): bool {
    return in_array($stage, staffRoleStages($role), true);
}

/** Which role owns a given board column (used to label locked columns). */
function getStaffRoleForStage(string $stage): ?string {
    foreach (getStaffRoles() as $key => $meta) {
        if (in_array($stage, $meta['stages'], true)) {
            return $key;
        }
    }

    return null;
}

/** Human readable list of the stages a role owns, e.g. "Pending and In Production". */
function staffRoleStageSummary(?string $role): string {
    $labels = getProductionStageLabels();
    $names = [];
    foreach (staffRoleStages($role) as $stage) {
        $names[] = $labels[$stage] ?? ucwords(str_replace('_', ' ', $stage));
    }

    if (!$names) {
        return 'no stages';
    }

    if (count($names) === 1) {
        return $names[0];
    }

    $last = array_pop($names);

    return implode(', ', $names) . ' and ' . $last;
}

/**
 * Production roles are deliberately narrow. A production artisan may hand a
 * finished order to Quality Check. A quality inspector may approve the order
 * as Ready or return a failed inspection to Production. Cancellation and final
 * completion remain administrative actions.
 */
function canStaffRoleMoveOrder(?string $role, string $fromStatus, string $toStatus): bool {
    return in_array($toStatus, staffRoleAllowedStatusTargets($role, $fromStatus), true);
}

/**
 * Server-side source of truth for staff stage transitions. Do not broaden this
 * list in page markup only; API requests are checked against the same rules.
 */
function staffRoleAllowedStatusTargets(?string $role, string $fromStatus): array {
    if (!isValidStaffRole($role) || !staffRoleOwnsStage($role, $fromStatus)) {
        return [];
    }

    if ($role === 'production' && $fromStatus === 'in_production') {
        return ['quality_check'];
    }

    if ($role === 'quality_check' && $fromStatus === 'quality_check') {
        return ['ready', 'in_production'];
    }

    return [];
}

function staffRoleActionLabel(?string $role, string $fromStatus, string $toStatus): string {
    if ($role === 'production' && $fromStatus === 'in_production' && $toStatus === 'quality_check') {
        return 'Send to Quality Check';
    }

    if ($role === 'quality_check' && $fromStatus === 'quality_check' && $toStatus === 'ready') {
        return 'Approve & Mark Ready';
    }

    if ($role === 'quality_check' && $fromStatus === 'quality_check' && $toStatus === 'in_production') {
        return 'Return to Production';
    }

    return 'Update Status';
}

function getStaffRoleForUser(mysqli $conn, int $userId): ?string {
    if ($userId <= 0 || !ensureStaffRoleColumn($conn)) {
        return null;
    }

    $stmt = $conn->prepare("SELECT staff_role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $role = $row['staff_role'] ?? null;

    return isValidStaffRole($role) ? $role : null;
}

/** Small coloured badge for tables and lists. */
function staffRoleChip(?string $role): string {
    if (!isValidStaffRole($role)) {
        return "<span class='badge badge-default' title='No production role assigned'>Unassigned</span>";
    }

    $color = staffRoleColor($role);
    $label = htmlspecialchars(staffRoleLabel($role));
    $icon  = staffRoleIcon($role);
    $style = "background:{$color}1a;color:{$color};border:1px solid {$color}40;";

    return "<span class='badge' style='{$style}'>{$icon} {$label}</span>";
}

function countStaffByStaffRole(mysqli $conn, string $role): int {
    if (!ensureStaffRoleColumn($conn)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE role = 'staff' AND staff_role = ?");
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total;
}

/**
 * Makes sure users.staff_role exists so the app keeps working even if
 * database/add_staff_role.sql has not been imported yet. The lookup is cached
 * per request.
 */
function ensureStaffRoleColumn(mysqli $conn): bool {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'staff_role'");
    if ($result && $result->num_rows > 0) {
        return $exists = true;
    }

    $added = $conn->query("
        ALTER TABLE users
        ADD COLUMN staff_role ENUM('production','quality_check') DEFAULT NULL AFTER role
    ");

    return $exists = (bool)$added;
}
