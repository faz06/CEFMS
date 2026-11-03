<?php
// Event detail page: shows one event and the booking box.
require __DIR__ . '/../includes/bootstrap.php';
expire_reservations();

$id = (int)($_GET['id'] ?? 0);

// Get the event together with its schedule and organizer name
$sql = "SELECT e.*, s.venue, s.startDateTime, s.endDateTime, s.status AS schedStatus, u.fullName AS organizer
        FROM event e
        JOIN schedule s ON s.eventId = e.eventId
        JOIN user u ON u.userId = e.organizerId
        WHERE e.eventId = ? AND e.status IN ('Published', 'Completed', 'Cancelled')";
$stmt = db()->prepare($sql);
$stmt->execute([$id]);
$ev = $stmt->fetch();

// No such event: show a friendly 404 page
if (!$ev) {
    http_response_code(404);
    public_header('Event not found');
    echo '<div class="card empty"><h2>Event not found</h2><p><a href="/events.php">Back to events</a></p></div>';
    public_footer();
    exit;
}

// Can people still book this event?
$bookingOpen = false;
if ($ev['status'] === 'Published' && $ev['schedStatus'] !== 'Cancelled' && strtotime($ev['endDateTime']) >= time()) {
    $bookingOpen = true;
}

$seatsLeft = (int)$ev['availableSeats'];

// Is the visitor a logged-in participant?
$user = current_user();
$isParticipant = false;
if ($user && $user['role'] === 'Event Participant') {
    $isParticipant = true;
}

// Colour class for the seat message
if ($seatsLeft == 0) {
    $seatClass = 'seats-out';
} elseif ($seatsLeft <= 20) {
    $seatClass = 'seats-low';
} else {
    $seatClass = 'seats-ok';
}

// Text for the seat message
if (!$bookingOpen) {
    $seatText = 'Booking closed';
} elseif ($seatsLeft == 0) {
    $seatText = 'Sold out';
} else {
    $seatText = $seatsLeft . ' of ' . (int)$ev['totalSeats'] . ' seats available';
}

// Price text
if ((float)$ev['ticketPrice'] == 0) {
    $priceText = 'Free';
} else {
    $priceText = money($ev['ticketPrice']);
}

public_header($ev['title'], 'events');
?>
<div class="detail">
  <div>
    <div class="banner"><span class="cat"><?= e($ev['category']) ?></span><b><?= e(dt($ev['startDateTime'], 'l, j F Y')) ?></b></div>
    <h1 style="margin:18px 0 6px"><?= e($ev['title']) ?> <?php if ($ev['status'] === 'Cancelled') { echo status_badge('Cancelled'); } ?></h1>
    <p><?= nl2br(e($ev['description'])) ?></p>
    <div class="card"><div class="kv">
      <b>Date</b><span><?= e(dt($ev['startDateTime'], 'l, d F Y')) ?></span>
      <b>Time</b><span><?= e(dt($ev['startDateTime'], 'h:i A')) ?> - <?= e(dt($ev['endDateTime'], 'h:i A')) ?></span>
      <b>Venue</b><span><?= e($ev['venue']) ?></span>
      <b>Organizer</b><span><?= e($ev['organizer']) ?></span>
      <b>Category</b><span><?= e($ev['category']) ?></span>
    </div></div>
  </div>

  <aside><div class="card">
    <div class="price" style="font-size:26px"><?= e($priceText) ?></div>
    <div class="meta">per ticket</div>
    <p class="<?= $seatClass ?>" id="seats"><?= e($seatText) ?></p>

    <?php if ($bookingOpen && $seatsLeft > 0) { ?>
      <?php if ($isParticipant) {
          $maxQty = min(MAX_TICKETS, $seatsLeft);   // cannot buy more than are left
      ?>
        <form method="post" action="/reserve.php"><?= csrf_field() ?>
          <input type="hidden" name="eventId" value="<?= (int)$ev['eventId'] ?>">
          <label for="qty">Number of tickets</label>
          <input type="number" id="qty" name="quantity" value="1" min="1" max="<?= $maxQty ?>">
          <p><button class="btn block" type="submit">Reserve / Buy Ticket</button></p>
        </form>
      <?php } else { ?>
        <p><a class="btn block" href="/login.php">Log in to buy tickets</a></p>
        <p class="hint">No account? <a href="/signup.php">Sign up free</a>.</p>
      <?php } ?>
    <?php } ?>
  </div></aside>
</div>
<?php public_footer();
