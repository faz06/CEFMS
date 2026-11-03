<?php
// Organizer: list of my own events with tickets sold and revenue.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Organizer']);
expire_reservations();

$statusFilter = (string)($_GET['status'] ?? '');
$allStatuses  = ['Pending', 'Published', 'Cancelled', 'Completed'];

// "sold" and "revenue" only count reservations that are Paid
$sql = "SELECT e.*, s.venue, s.startDateTime,
          (SELECT COALESCE(SUM(quantity), 0) FROM reservation r
            WHERE r.eventId = e.eventId AND r.status = 'Paid') AS sold,
          (SELECT COALESCE(SUM(totalAmount), 0) FROM reservation r
            WHERE r.eventId = e.eventId AND r.status = 'Paid') AS revenue
        FROM event e
        JOIN schedule s ON s.eventId = e.eventId
        WHERE e.organizerId = ?";
$params = [$user['userId']];

// Optional filter by status
if (in_array($statusFilter, $allStatuses, true)) {
    $sql .= ' AND e.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY e.createdDate DESC, e.eventId DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_header('Event History', 'history');
?>
<div class="filters">
  <a href="?" class="<?= ($statusFilter === '') ? 'on' : '' ?>">All</a>
  <?php foreach ($allStatuses as $s) { ?>
    <a href="?status=<?= $s ?>" class="<?= ($statusFilter === $s) ? 'on' : '' ?>"><?= $s ?></a>
  <?php } ?>
</div>
<div class="card">
<?php if (count($rows) === 0) { ?>
  <p class="hint">No events found. <a href="/admin/event_create.php">Create your first event</a>.</p>
<?php } else { ?>
  <table>
    <thead><tr><th>Event</th><th>Venue</th><th>Date</th><th>Status</th><th>Sold / Seats</th><th>Revenue</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { ?>
      <tr>
        <td><b><?= e($r['title']) ?></b><div class="hint"><?= e($r['category']) ?></div></td>
        <td><?= e($r['venue']) ?></td>
        <td><?= e(dt($r['startDateTime'], 'd M Y, h:i A')) ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td><?= (int)$r['sold'] ?> / <?= (int)$r['totalSeats'] ?></td>
        <td><?= e(money($r['revenue'])) ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
<?php } ?>
</div>
<?php admin_footer();
