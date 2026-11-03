<?php
// Event Manager: archive events that are already over, and restore them if needed.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Event Manager']);
expire_reservations();      // this also marks finished events as Completed
$pdo = db();

// ---- A button was pressed ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'archive') {
        list($ok, $message) = archive_event($id);
    } elseif ($action === 'restore') {
        list($ok, $message) = restore_event($id);
    } elseif ($action === 'archive_all') {
        $count = archive_all_past();
        $ok = true;
        if ($count > 0) {
            $message = $count . ' past event(s) archived.';
        } else {
            $message = 'There are no past events to archive.';
        }
    } else {
        $ok = false;
        $message = 'Unknown action.';
    }

    if ($ok) {
        flash($message, 'ok');
    } else {
        flash($message, 'err');
    }
    redirect('/admin/archive.php');
}

// Events that are over but not archived yet
$sql = "SELECT e.*, s.venue, s.startDateTime, s.endDateTime, u.fullName AS org
        FROM event e
        JOIN schedule s ON s.eventId = e.eventId
        JOIN user u ON u.userId = e.organizerId
        WHERE e.archived = ? AND e.status IN ('Completed', 'Cancelled')
        ORDER BY s.startDateTime DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([0]);
$readyRows = $stmt->fetchAll();

// Events that are already archived
$stmt->execute([1]);
$archivedRows = $stmt->fetchAll();

admin_header('Event Archive', 'archive');
?>
<div class="card"><h2>Past events ready to archive</h2>
  <p class="hint">Completed and cancelled events that are over. Archiving hides them from Manage Event Details. Their bookings and report figures are kept.</p>
  <?php if (count($readyRows) === 0) { ?>
    <p class="hint">No past events are waiting to be archived.</p>
  <?php } else { ?>
    <table>
      <thead><tr><th>Event</th><th>Organizer</th><th>Ended</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($readyRows as $r) { ?>
        <tr>
          <td><b><?= e($r['title']) ?></b><div class="hint"><?= e($r['venue']) ?></div></td>
          <td><?= e($r['org']) ?></td>
          <td><?= e(dt($r['endDateTime'], 'd M Y, h:i A')) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td>
            <form method="post" class="inline"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$r['eventId'] ?>">
              <button class="btn sm" name="action" value="archive">Archive</button>
            </form>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
    <form method="post" style="margin-top:14px"><?= csrf_field() ?>
      <button class="btn gray" name="action" value="archive_all">Archive all past events</button>
    </form>
  <?php } ?>
</div>

<div class="card"><h2>Archived events</h2>
  <?php if (count($archivedRows) === 0) { ?>
    <p class="hint">The archive is empty.</p>
  <?php } else { ?>
    <table>
      <thead><tr><th>Event</th><th>Organizer</th><th>Date</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($archivedRows as $r) { ?>
        <tr>
          <td><b><?= e($r['title']) ?></b><div class="hint"><?= e($r['venue']) ?></div></td>
          <td><?= e($r['org']) ?></td>
          <td><?= e(dt($r['startDateTime'], 'd M Y')) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td>
            <form method="post" class="inline"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$r['eventId'] ?>">
              <button class="btn sm gray" name="action" value="restore">Restore</button>
            </form>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  <?php } ?>
</div>
<?php admin_footer();
