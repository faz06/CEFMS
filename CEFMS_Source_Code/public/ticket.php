<?php
// Shows the e-ticket after a successful payment.
require __DIR__ . '/../includes/bootstrap.php';
$user = require_participant();

$reservationId = (int)($_GET['id'] ?? 0);
$r = get_reservation($reservationId, $user['userId']);   // only this user's own reservation

// Not found, or not paid yet: no ticket
if (!$r || $r['status'] !== 'Paid') {
    http_response_code(404);
    public_header('Not found');
    echo '<div class="card empty"><h2>Ticket not available</h2><a href="/dashboard.php">Go to dashboard</a></div>';
    public_footer();
    exit;
}

public_header('Your Ticket', 'dash');
?>
<div class="ticket">
  <h2 style="margin-top:0">E-Ticket confirmed</h2>
  <p class="meta">Show this code at the entrance.</p>
  <div class="code" id="ticketCode"><?= e($r['ticketCode']) ?></div>
  <div class="kv">
    <b>Event</b><span><?= e($r['title']) ?></span>
    <b>Date</b><span><?= e(dt($r['startDateTime'])) ?></span>
    <b>Venue</b><span><?= e($r['venue']) ?></span>
    <b>Tickets</b><span><?= (int)$r['quantity'] ?></span>
    <b>Amount paid</b><span><?= e(money($r['totalAmount'])) ?></span>
    <b>Holder</b><span><?= e($user['fullName']) ?></span>
  </div>
  <p style="margin-top:18px" class="actions">
    <a class="btn" href="/dashboard.php">Back to dashboard</a>
    <a class="btn ghost" href="/events.php">Browse more events</a>
  </p>
</div>
<?php public_footer();
