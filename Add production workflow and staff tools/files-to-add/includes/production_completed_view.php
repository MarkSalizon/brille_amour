<?php
/**
 * production_completed_view.php
 * ─────────────────────────────────────────────────────────
 * "Completed Today" table under the board. Rendered through
 * renderProductionCompletedToday() so a live poll can refresh it with the
 * same markup the page printed.
 *
 * In scope: $completedToday
 */

$completedToday = $completedToday ?? [];

if (!$completedToday) {
    return;
}
?>
<div class="card mt-4">
  <div class="card-header"><span class="card-title">Completed Today (<?= count($completedToday) ?>)</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Order #</th><th>Customer</th><th>Type</th><th>Total</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($completedToday as $order): ?>
        <tr>
          <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
          <td><?= htmlspecialchars($order['customer_name']) ?></td>
          <td><?= htmlspecialchars($order['order_type'] ?? '-') ?></td>
          <td><?= formatMoney($order['total_amount']) ?></td>
          <td class="text-sm text-muted"><?= date('h:i A', strtotime($order['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
