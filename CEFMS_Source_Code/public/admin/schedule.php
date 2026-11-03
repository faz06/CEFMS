<?php
// Event Manager: change the venue and time of an event.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Event Manager']);
expire_reservations();
$pdo = db();

$errors = [];

// Which schedule is being edited? (0 = none, just show the list)
$editId = (int)($_GET['edit'] ?? $_POST['scheduleId'] ?? 0);
$stmt = $pdo->prepare("SELECT s.*, e.title, e.status AS estatus
                       FROM schedule s JOIN event e ON e.eventId = s.eventId
                       WHERE s.scheduleId = ?");
$stmt->execute([$editId]);
$current = $stmt->fetch();

// Start with the saved values in the form
$values = [];
if ($current) {
    $values['venue'] = $current['venue'];
    $values['start'] = date('Y-m-d\TH:i', strtotime($current['startDateTime']));
    $values['end']   = date('Y-m-d\TH:i', strtotime($current['endDateTime']));
}

// ---- The form was submitted ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $current) {
    csrf_check();
    $values['venue'] = post('venue');
    $values['start'] = post('start');
    $values['end']   = post('end');

    $errors = validate_schedule_fields($values['venue'], $values['start'], $values['end']);

    if (count($errors) === 0 && $current['status'] === 'Cancelled') {
        $errors[] = 'A cancelled schedule cannot be changed.';
    }
    if (count($errors) === 0 && strtotime(norm_datetime($values['end'])) < time()) {
        $errors[] = 'The new schedule must not be in the past.';
    }

    // Is another published event using this venue at the same time?
    if (count($errors) === 0) {
        $clash = schedule_clash($values['venue'], norm_datetime($values['start']),
                                norm_datetime($values['end']), (int)$current['eventId']);
        if ($clash !== null) {
            $errors[] = 'Venue clash: "' . $values['venue'] . '" is already booked by "' . $clash . '" during that time.';
            log_event('warning', 'Schedule update blocked by venue clash', ['scheduleId' => $editId, 'clashWith' => $clash]);
        }
    }

    if (count($errors) === 0) {
        $sql = "UPDATE schedule SET venue = ?, startDateTime = ?, endDateTime = ?, status = 'Rescheduled'
                WHERE scheduleId = ?";
        $pdo->prepare($sql)->execute([
            $values['venue'], norm_datetime($values['start']), norm_datetime($values['end']), $editId,
        ]);
        log_event('info', 'Schedule updated', ['scheduleId' => $editId]);
        flash('Schedule updated successfully.');
        redirect('/admin/schedule.php');
    }
}

// All schedules for the list
$rows = $pdo->query("SELECT s.*, e.title, e.status AS estatus
                     FROM schedule s JOIN event e ON e.eventId = s.eventId
                     ORDER BY s.startDateTime")->fetchAll();

admin_header('Manage Schedule', 'schedule');
?>
<?php if ($current) { ?>
<div class="card" style="max-width:820px"><h2>Edit schedule: <?= e($current['title']) ?></h2><?php errors_box($errors); ?>
<form method="post" novalidate><?= csrf_field() ?><input type="hidden" name="scheduleId" value="<?= $editId ?>">
  <label for="venue">Venue</label>
  <input type="text" id="venue" name="venue" value="<?= e($values['venue']) ?>" required>
  <div class="row2">
    <div><label for="start">Start</label><input type="datetime-local" id="start" name="start" value="<?= e($values['start']) ?>" required></div>
    <div><label for="end">End</label><input type="datetime-local" id="end" name="end" value="<?= e($values['end']) ?>" required></div>
  </div>
  <p class="hint">The system prevents two published events from using the same venue at overlapping times.</p>
  <p class="actions"><button class="btn" type="submit">Save schedule</button><a class="btn gray" href="/admin/schedule.php">Cancel</a></p>
</form></div>
<?php } ?>

<div class="card">
<table>
  <thead><tr><th>Event</th><th>Venue</th><th>Start</th><th>End</th><th>Schedule</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r) { ?>
    <tr>
      <td><b><?= e($r['title']) ?></b> <?= status_badge($r['estatus']) ?></td>
      <td><?= e($r['venue']) ?></td>
      <td><?= e(dt($r['startDateTime'])) ?></td>
      <td><?= e(dt($r['endDateTime'])) ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td>
        <?php if ($r['status'] !== 'Cancelled') { ?>
          <a class="btn sm" href="?edit=<?= (int)$r['scheduleId'] ?>">Edit</a>
        <?php } ?>
      </td>
    </tr>
  <?php } ?>
  </tbody>
</table>
</div>
<?php admin_footer();
