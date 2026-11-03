<?php
// Event Manager: edit the details of a Pending or Published event.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Event Manager']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM event WHERE eventId = ?');
$stmt->execute([$id]);
$ev = $stmt->fetch();

// Only Pending and Published events can be edited
if (!$ev || !in_array($ev['status'], ['Pending', 'Published'], true)) {
    flash('This event cannot be edited.', 'err');
    redirect('/admin/events.php');
}

// Seats already booked or held (total minus available)
$seatsTaken = (int)$ev['totalSeats'] - (int)$ev['availableSeats'];

$errors = [];
$values = [
    'title'       => $ev['title'],
    'description' => $ev['description'],
    'category'    => $ev['category'],
    'ticketPrice' => $ev['ticketPrice'],
    'totalSeats'  => (string)$ev['totalSeats'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($values as $name => $oldValue) {
        $values[$name] = post($name);
    }

    $errors = validate_event_fields($values);
    // Cannot reduce the seats below the number already taken
    if (count($errors) === 0 && (int)$values['totalSeats'] < $seatsTaken) {
        $errors[] = "Total seats cannot be lower than the $seatsTaken seat(s) already booked or held.";
    }

    if (count($errors) === 0) {
        $newTotal     = (int)$values['totalSeats'];
        $newAvailable = $newTotal - $seatsTaken;
        $sql = 'UPDATE event SET title = ?, description = ?, category = ?, ticketPrice = ?, totalSeats = ?, availableSeats = ?
                WHERE eventId = ?';
        db()->prepare($sql)->execute([
            $values['title'], $values['description'], $values['category'],
            $values['ticketPrice'], $newTotal, $newAvailable, $id,
        ]);
        log_event('info', 'Event details updated', ['eventId' => $id]);
        flash('Event details updated.');
        redirect('/admin/events.php');
    }
}

admin_header('Edit Event Details', 'events');
?>
<div class="card" style="max-width:820px"><?php errors_box($errors); ?>
<form method="post" novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <label for="title">Event title</label>
  <input type="text" id="title" name="title" value="<?= e($values['title']) ?>" required>
  <label for="description">Description</label>
  <textarea id="description" name="description" rows="4"><?= e($values['description']) ?></textarea>
  <div class="row2">
    <div><label for="category">Category</label>
      <select id="category" name="category">
        <?php foreach (CATEGORIES as $c) { ?>
          <option <?= ($values['category'] === $c) ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php } ?>
      </select></div>
    <div><label for="ticketPrice">Ticket price (RM)</label>
      <input type="number" id="ticketPrice" name="ticketPrice" step="0.01" min="0" value="<?= e($values['ticketPrice']) ?>"></div>
  </div>
  <label for="totalSeats">Total seats</label>
  <input type="number" id="totalSeats" name="totalSeats" min="1" value="<?= e($values['totalSeats']) ?>">
  <div class="hint"><?= $seatsTaken ?> seat(s) currently booked or held.</div>
  <p class="actions"><button class="btn" type="submit">Save changes</button><a class="btn gray" href="/admin/events.php">Back</a></p>
</form></div>
<?php admin_footer();
