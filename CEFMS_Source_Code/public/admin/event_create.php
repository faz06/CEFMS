<?php
// Organizer: create a new event. It starts as "Pending" until the Event Manager approves it.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Organizer']);

$errors = [];
$values = ['title' => '', 'description' => '', 'category' => '', 'ticketPrice' => '',
           'totalSeats' => '', 'venue' => '', 'start' => '', 'end' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Read every field from the form
    foreach ($values as $name => $oldValue) {
        $values[$name] = post($name);
    }

    // Check the fields (the checking rules are in services.php)
    $errors = validate_event_fields($values);
    $errors = array_merge($errors, validate_schedule_fields($values['venue'], $values['start'], $values['end']));
    if (count($errors) === 0 && strtotime(norm_datetime($values['start'])) < time()) {
        $errors[] = 'The event must start in the future.';
    }

    if (count($errors) === 0) {
        // Save the event and its schedule together. If one fails, neither is saved.
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $sql = "INSERT INTO event (organizerId, title, description, category, ticketPrice, totalSeats, availableSeats, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')";
            $pdo->prepare($sql)->execute([
                $user['userId'], $values['title'], $values['description'], $values['category'],
                $values['ticketPrice'], (int)$values['totalSeats'], (int)$values['totalSeats'],
            ]);
            $eventId = (int)$pdo->lastInsertId();

            $sql = "INSERT INTO schedule (eventId, venue, startDateTime, endDateTime, status)
                    VALUES (?, ?, ?, ?, 'Scheduled')";
            $pdo->prepare($sql)->execute([
                $eventId, $values['venue'], norm_datetime($values['start']), norm_datetime($values['end']),
            ]);
            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        log_event('info', 'Event created (Pending)', ['eventId' => $eventId]);
        flash('Event submitted. It is now Pending until the Event Manager approves it.');
        redirect('/admin/history.php');
    }
}

admin_header('Create Event', 'create');
?>
<div class="card" style="max-width:820px"><?php errors_box($errors); ?>
<form method="post" novalidate><?= csrf_field() ?>
  <label for="title">Event title</label>
  <input type="text" id="title" name="title" value="<?= e($values['title']) ?>" required>
  <label for="description">Description</label>
  <textarea id="description" name="description" rows="4" required><?= e($values['description']) ?></textarea>
  <div class="row2">
    <div><label for="category">Category</label>
      <select id="category" name="category" required>
        <option value="">Select...</option>
        <?php foreach (CATEGORIES as $c) { ?>
          <option <?= ($values['category'] === $c) ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php } ?>
      </select></div>
    <div><label for="ticketPrice">Ticket price (RM)</label>
      <input type="number" id="ticketPrice" name="ticketPrice" step="0.01" min="0" value="<?= e($values['ticketPrice']) ?>" required></div>
  </div>
  <div class="row2">
    <div><label for="totalSeats">Total seats</label>
      <input type="number" id="totalSeats" name="totalSeats" min="1" value="<?= e($values['totalSeats']) ?>" required></div>
    <div><label for="venue">Proposed venue</label>
      <input type="text" id="venue" name="venue" value="<?= e($values['venue']) ?>" required></div>
  </div>
  <div class="row2">
    <div><label for="start">Start date and time</label>
      <input type="datetime-local" id="start" name="start" value="<?= e($values['start']) ?>" required></div>
    <div><label for="end">End date and time</label>
      <input type="datetime-local" id="end" name="end" value="<?= e($values['end']) ?>" required></div>
  </div>
  <p class="hint">New events start as <b>Pending</b> and are published after the Event Manager approves them.</p>
  <p><button class="btn" type="submit">Submit event</button></p>
</form></div>
<?php admin_footer();
