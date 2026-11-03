<?php
// Admin dashboard. The Event Manager and the Organizer see different numbers.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin();
expire_reservations();
$pdo = db();

$isManager = ($user['role'] === 'Event Manager');
$pendingEvents = [];

if ($isManager) {
    // Numbers for the whole system
    $stats = $pdo->query("SELECT
        (SELECT COUNT(*) FROM event WHERE status = 'Pending') AS pending,
        (SELECT COUNT(*) FROM event WHERE status = 'Published') AS published,
        (SELECT COUNT(*) FROM user WHERE role = 'Organizer' AND status = 'Active') AS orgs,
        (SELECT COALESCE(SUM(quantity), 0) FROM reservation WHERE status = 'Paid') AS sold,
        (SELECT COALESCE(SUM(totalAmount), 0) FROM reservation WHERE status = 'Paid') AS revenue")->fetch();

    // Events waiting for the manager to approve
    $pendingEvents = $pdo->query("SELECT e.eventId, e.title, u.fullName, s.venue, s.startDateTime
        FROM event e
        JOIN user u ON u.userId = e.organizerId
        JOIN schedule s ON s.eventId = e.eventId
        WHERE e.status = 'Pending'
        ORDER BY e.createdDate")->fetchAll();
} else {
    // Numbers for this organizer only
    $id = $user['userId'];
    $stmt = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM event WHERE organizerId = ? AND status = 'Pending') AS pending,
        (SELECT COUNT(*) FROM event WHERE organizerId = ? AND status = 'Published') AS published,
        (SELECT COUNT(*) FROM event WHERE organizerId = ?) AS total,
        (SELECT COALESCE(SUM(r.quantity), 0) FROM reservation r
           JOIN event e ON e.eventId = r.eventId
           WHERE e.organizerId = ? AND r.status = 'Paid') AS sold,
        (SELECT COALESCE(SUM(r.totalAmount), 0) FROM reservation r
           JOIN event e ON e.eventId = r.eventId
           WHERE e.organizerId = ? AND r.status = 'Paid') AS revenue");
    $stmt->execute([$id, $id, $id, $id, $id]);
    $stats = $stmt->fetch();
}

admin_header('Dashboard', 'dash');
?>
<div class="stats">
<?php if ($isManager) { ?>
  <div class="stat"><b><?= (int)$stats['pending'] ?></b><span>Events awaiting approval</span></div>
  <div class="stat"><b><?= (int)$stats['published'] ?></b><span>Published events</span></div>
  <div class="stat"><b><?= (int)$stats['orgs'] ?></b><span>Active organizers</span></div>
<?php } else { ?>
  <div class="stat"><b><?= (int)$stats['total'] ?></b><span>My events</span></div>
  <div class="stat"><b><?= (int)$stats['pending'] ?></b><span>Awaiting approval</span></div>
  <div class="stat"><b><?= (int)$stats['published'] ?></b><span>Published</span></div>
<?php } ?>
  <div class="stat"><b><?= (int)$stats['sold'] ?></b><span>Tickets sold</span></div>
  <div class="stat"><b><?= e(money($stats['revenue'])) ?></b><span>Revenue</span></div>
</div>

<?php if ($isManager) { ?>
<div class="card"><h2>Pending approval</h2>
  <?php if (count($pendingEvents) === 0) { ?>
    <p class="hint">No events are waiting for approval.</p>
  <?php } else { ?>
    <table>
      <thead><tr><th>Event</th><th>Organizer</th><th>Venue</th><th>Start</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pendingEvents as $p) { ?>
        <tr>
          <td><?= e($p['title']) ?></td>
          <td><?= e($p['fullName']) ?></td>
          <td><?= e($p['venue']) ?></td>
          <td><?= e(dt($p['startDateTime'])) ?></td>
          <td><a class="btn sm" href="/admin/events.php?status=Pending">Review</a></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  <?php } ?>
</div>
<?php } else { ?>
<div class="card"><h2>Quick actions</h2>
  <div class="actions">
    <a class="btn" href="/admin/event_create.php">Create a new event</a>
    <a class="btn gray" href="/admin/history.php">View event history</a>
  </div>
</div>
<?php } ?>
<?php admin_footer();
