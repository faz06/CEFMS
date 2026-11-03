<?php
// Participant dashboard: bookings, totals and tickets.
require __DIR__ . '/../includes/bootstrap.php';
$user = require_participant();
expire_reservations();

// All reservations made by this user, newest first
$sql = "SELECT r.*, e.title, s.venue, s.startDateTime
        FROM reservation r
        JOIN event e ON e.eventId = r.eventId
        LEFT JOIN schedule s ON s.eventId = e.eventId
        WHERE r.userId = ?
        ORDER BY r.reservedDate DESC";
$stmt = db()->prepare($sql);
$stmt->execute([$user['userId']]);
$rows = $stmt->fetchAll();

// Count the paid and the waiting reservations, and add up the money spent
$paidCount = 0;
$heldCount = 0;
$totalSpent = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'Paid') {
        $paidCount++;
        $totalSpent = $totalSpent + (float)$r['totalAmount'];
    }
    if ($r['status'] === 'Reserved') {
        $heldCount++;
    }
}

public_header('My Dashboard', 'dash');
?>
<h2>Welcome, <?= e($user['fullName']) ?></h2>
<div class="stats">
  <div class="card stat"><b><?= $paidCount ?></b><span>Confirmed bookings</span></div>
  <div class="card stat"><b><?= $heldCount ?></b><span>Awaiting payment</span></div>
  <div class="card stat"><b><?= e(money($totalSpent)) ?></b><span>Total spent</span></div>
</div>
<div class="actions" style="margin-bottom:10px">
  <a class="btn" href="/events.php">Browse events</a>
  <a class="btn ghost" href="/calendar.php">Event calendar</a>
</div>

<h2>My reservations and tickets</h2>
<?php if (count($rows) === 0) { ?>
  <div class="card empty">You have no bookings yet. <a href="/events.php">Find an event</a>.</div>
<?php } else { ?>
<div style="overflow-x:auto">
<table>
  <thead><tr><th>Event</th><th>Date</th><th>Qty</th><th>Total</th><th>Status</th><th>Ticket / Action</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r) { ?>
    <tr>
      <td><a href="/event.php?id=<?= (int)$r['eventId'] ?>"><?= e($r['title']) ?></a><div class="meta"><?= e($r['venue']) ?></div></td>
      <td><?= e(dt($r['startDateTime'], 'd M Y')) ?></td>
      <td><?= (int)$r['quantity'] ?></td>
      <td><?= e(money($r['totalAmount'])) ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td>
        <?php if ($r['status'] === 'Paid') { ?>
          <a href="/ticket.php?id=<?= (int)$r['reservationId'] ?>"><b><?= e($r['ticketCode']) ?></b></a>
        <?php } elseif ($r['status'] === 'Reserved') { ?>
          <a class="btn sm" href="/payment.php?id=<?= (int)$r['reservationId'] ?>">Pay now</a>
          <div class="meta">Hold ends <?= e(dt($r['expiryDate'], 'h:i A')) ?></div>
        <?php } else { ?>
          -
        <?php } ?>
      </td>
    </tr>
  <?php } ?>
  </tbody>
</table>
</div>
<?php } ?>
<?php public_footer();
