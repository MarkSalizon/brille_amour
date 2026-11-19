<?php
// includes/helpers.php – Utility functions

require_once __DIR__ . '/config.php';

/* ──────────────────────────────────────────
   Number helpers
────────────────────────────────────────── */
function money(float $n): string {
    return '₱' . number_format($n, 2);
}

function pct(float $part, float $total): string {
    if ($total == 0) return '0%';
    return round(($part / $total) * 100, 1) . '%';
}

/* ──────────────────────────────────────────
   String / sanitise helpers
────────────────────────────────────────── */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function slugify(string $s): string {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s), '-'));
}

/* ──────────────────────────────────────────
   Date helpers
────────────────────────────────────────── */
function fmt_date(?string $d, string $format = 'M d, Y'): string {
    if (!$d) return '—';
    return date($format, strtotime($d));
}

function days_until(string $due): int {
    $now = new DateTime();
    $d   = new DateTime($due);
    return (int)$now->diff($d)->days * ($now <= $d ? 1 : -1);
}

/* ──────────────────────────────────────────
   Order number generator
────────────────────────────────────────── */
function genOrderNumber(): string {
    $db   = getDB();
    $year = date('Y');
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM orders WHERE YEAR(order_date) = ?"
    );
    $stmt->execute([$year]);
    $count = (int)$stmt->fetchColumn() + 1;
    return 'BA-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/* ──────────────────────────────────────────
   Status badge HTML
────────────────────────────────────────── */
function orderBadge(string $status): string {
    $map = [
        'pending'        => ['Pending',        'badge-pending'],
        'in_production'  => ['In Production',  'badge-production'],
        'quality_check'  => ['Quality Check',  'badge-qa'],
        'completed'      => ['Completed',      'badge-done'],
        'cancelled'      => ['Cancelled',      'badge-cancelled'],
        'archived'       => ['Archived',       'badge-archived'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'badge-default'];
    return "<span class=\"badge {$cls}\">{$label}</span>";
}

function taskBadge(string $status): string {
    $map = [
        'not_started' => ['Not Started', 'badge-pending'],
        'in_progress' => ['In Progress', 'badge-production'],
        'done'        => ['Done',        'badge-done'],
        'on_hold'     => ['On Hold',     'badge-cancelled'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'badge-default'];
    return "<span class=\"badge {$cls}\">{$label}</span>";
}

if (!function_exists('priorityBadge')) {
    function priorityBadge(string $p): string {
        $map = [
            'low'    => ['Low',    'badge-low'],
            'normal' => ['Normal', 'badge-normal'],
            'high'   => ['High',   'badge-high'],
            'urgent' => ['Urgent', 'badge-urgent'],
        ];
        [$label, $cls] = $map[$p] ?? [$p, 'badge-default'];
        return "<span class=\"badge {$cls}\">{$label}</span>";
    }
}


function payBadge(string $status): string {
    $map = [
        'unpaid'  => ['Unpaid',   'badge-cancelled'],
        'partial' => ['Partial',  'badge-pending'],
        'paid'    => ['Paid',     'badge-done'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'badge-default'];
    return "<span class=\"badge {$cls}\">{$label}</span>";
}

/* ──────────────────────────────────────────
   Pagination
────────────────────────────────────────── */
function paginate(int $total, int $page, int $perPage, string $urlBase): string {
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return '';
    $html = '<div class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $html  .= "<a href=\"{$urlBase}&page={$i}\" class=\"page-btn{$active}\">{$i}</a>";
    }
    return $html . '</div>';
}
