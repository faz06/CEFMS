<?php
// Event Manager: approve or cancel events, and open the edit page.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Event Manager']);
expire_reservations();
$pdo = db();

// ---- A button was pressed (Approve or Cancel) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id     = (int)($_POST['id'] ?? 0);
    $action = post('action');

    // Get the event and its schedule
    $stmt = $pdo->prepare('SELECT e.*, s.venue, s.startDateTime, s.endDateTime
                           FROM event e JOIN schedule s ON s.eventId = e.eventId
                           WHERE e.eventId = ?');
    $stmt->execute([$id]);
    $ev = $stmt->fetch();

    if (!$ev) {
        flash('Event not found.', 'err');
        redirect('/admin/events.php');
    }

    if ($action === 'approve') {
        // Check the rules one by one. The first problem stops the approval.
        $clash = null;
        if ($ev['status'] === 'Pending' && strtotime($ev['endDateTime']) >= time()) {
            $clash = schedule_clash($ev['venue'], $ev['startDateTime'], $ev['endDateTime'], $id);
        }

        if ($ev['status'] !== 'Pending') {
            flash('Only pending events can be approved.', 'err');
        } elseif (strtotime($ev['endDateTime']) < time()) {
            flash('Cannot approve: the event date has already passed. Ask the organizer to resubmit.', 'err');
        } elseif ($clash !== null) {
            flash('Cannot approve: venue "' . $ev['venue'] . '" is already booked by "' . $clash . '" at that time. Reschedule first.', 'err');
            log_event('warning', 'Approval blocked by venue clash', ['eventId' => $id, 'clashWith' => $clash]);
        } else {
            $pdo->prepare("UPDATE event SET status = 'Published' WHERE eventId = ?")->execute([$id]);
            log_event('info', 'Event approved', ['eventId' => $id]);
            flash('Event approved and published.');
        }

    } elseif ($action === 'cancel') {
        if (!in_array($ev['status'], ['Pending', 'Published'], true)) {
            flash('This event cannot be cancelled.', 'err');
        } else {
            // Cancel the event, its schedule and its bookings together (all or nothing)
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE event SET status = 'Cancelled' WHERE eventId = ?")->execute([$id]);
                $pdo->prepare("UPDATE schedule SET status = 'Cancelled' WHERE eventId = ?")->execute([$id]);

                // How many paid bookings will need a refund?
                $count = $pdo->prepare("SELECT COUNT(*) FROM reservation WHERE eventId = ? AND status = 'Paid'");
                $count->execute([$id]);
                $paidBookings = (int)$count->fetchColumn();

                $pdo->prepare("UPDATE reservation SET status = 'Cancelled'
                               WHERE eventId = ? AND status IN ('Reserved', 'Paid')")->execute([$id]);
                $pdo->commit();
            } catch (Throwable $t) {
                $pdo->rollBack();
                throw $t;
            }
            log_event('info', 'Event cancelled', ['eventId' => $id, 'paidBookingsToRefund' => $paidBookings]);

            if ($paidBookings > 0) {
                flash("Event cancelled. $paidBookings paid booking(s) need to be refunded.", 'warn');
            } else {
                flash('Event cancelled.', 'ok');
            }
        }
    }

    // Go back to the list (keep the status filter if there was one)
    $back = '/admin/events.php';
    if (isset($_GET['status'])) {
        $back = $back . '?status=' . urlencode($_GET['status']);
    }
    redirect($back);
}

// ---- Show the list of events ----
$statusFilter = (string)($_GET['status'] ?? '');
$allStatuses  = ['Pending', 'Published', 'Cancelled', 'Completed'];

$sql = "SELECT e.*, s.venue, s.startDateTime, u.fullName AS org
        FROM event e
        JOIN schedule s ON s.eventId = e.eventId
        JOIN user u ON u.userId = e.organizerId
        WHERE e.archived = 0";           // archived events are hidden here (see Event Archive)
$params = [];
if (in_array($statusFilter, $allStatuses, true)) {
    $sql .= ' AND e.status = ?';
    $params[] = $statusFilter;
}
// Pending first, so the manager sees what needs approval
$sql .= " ORDER BY FIELD(e.status, 'Pending', 'Published', 'Completed', 'Cancelled'), s.startDateTime";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_header('Manage Event Details', 'events');
?>
<div class="filters">
  <a href="?" class="<?= ($statusFilter === '') ? 'on' : '' ?>">All</a>
  <?php foreach ($allStatuses as $s) { ?>
    <a href="?status=<?= $s ?>" class="<?= ($statusFilter === $s) ? 'on' : '' ?>"><?= $s ?></a>
  <?php } ?>
</div>
<div class="card">
<p class="hint">Events that are over can be moved out of this list on the <a href="/admin/archive.php">Event Archive</a> page.</p>
<table>
  <thead><tr><th>Event</th><th>Organizer</th><th>Date</th><th>Price</th><th>Seats</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r) { ?>
    <tr>
      <td><b><?= e($r['title']) ?></b><div class="hint"><?= e($r['category']) ?> &middot; <?= e($r['venue']) ?></div></td>
      <td><?= e($r['org']) ?></td>
      <td><?= e(dt($r['startDateTime'], 'd M Y')) ?></td>
      <td><?= e(money($r['ticketPrice'])) ?></td>
      <td><?= (int)$r['availableSeats'] ?> / <?= (int)$r['totalSeats'] ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td><div class="actions">
        <?php if ($r['status'] === 'Pending' || $r['status'] === 'Published') { ?>
          <a class="btn sm gray" href="/admin/event_edit.php?id=<?= (int)$r['eventId'] ?>">Edit</a>
          <?php if ($r['status'] === 'Pending') { ?>
            <form method="post" class="inline"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$r['eventId'] ?>">
              <button class="btn sm ok" name="action" value="approve">Approve</button>
            </form>
          <?php } ?>
          <form method="post" class="inline" onsubmit="return confirm('Cancel this event? Existing bookings will be cancelled.')"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$r['eventId'] ?>">
            <button class="btn sm danger" name="action" value="cancel">Cancel</button>
          </form>
        <?php } else { ?>
          -
        <?php } ?>
      </div></td>
    </tr>
  <?php } ?>
  <?php if (count($rows) === 0) { ?>
    <tr><td colspan="7" class="hint">No events found.</td></tr>
  <?php } ?>
  </tbody>
</table>
</div>
<?php admin_footer();
