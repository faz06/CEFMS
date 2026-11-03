<?php
require __DIR__ . '/../includes/bootstrap.php';
// Payment page (the payment gateway is simulated).
$user = require_participant();
expire_reservations();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$r = get_reservation($id, $user['userId']);   // only this user's own reservation

if (!$r) {
    http_response_code(404);
    public_header('Not found');
    echo '<div class="card empty"><h2>Reservation not found</h2><a href="/dashboard.php">Go to dashboard</a></div>';
    public_footer();
    exit;
}

// The user pressed a Pay button
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $method = post('method');

    // The demo button "Simulate gateway failure" makes the payment fail on purpose
    $outcome = 'success';
    if (isset($_POST['simulate_fail'])) {
        $outcome = 'fail';
    }

    list($ok, $message) = pay_reservation($id, $user['userId'], $method, $outcome);
    if ($ok) {
        flash($message, 'ok');
        redirect('/ticket.php?id=' . $id);
    }
    flash($message, 'err');
    redirect('/payment.php?id=' . $id);
}

// Already paid: show the ticket instead
if ($r['status'] === 'Paid') {
    redirect('/ticket.php?id=' . $id);
}
public_header('Payment', 'dash');
if ($r['status'] !== 'Reserved'): ?>
  <div class="card empty"><h2>This reservation is <?= e(strtolower($r['status'])) ?></h2>
  <p>The seat hold is no longer active. Please reserve again.</p>
  <a class="btn" href="/event.php?id=<?= (int)$r['eventId'] ?>">Back to event</a></div>
<?php else: $secs = max(0, strtotime($r['expiryDate']) - time()); ?>
<div class="detail" style="grid-template-columns:3fr 2fr">
  <div class="card paybox"><h2 style="margin-top:0">Make payment</h2>
    <p>Seats are held for you. Time left: <span class="timer" id="timer" data-secs="<?= $secs ?>"><?= gmdate('i:s', $secs) ?></span></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <label>Payment method</label>
      <label class="opt"><input type="radio" name="method" value="Online Banking" checked> Online Banking (FPX)</label>
      <label class="opt"><input type="radio" name="method" value="Card"> Credit / Debit Card</label>
      <label class="opt"><input type="radio" name="method" value="E-Wallet"> E-Wallet</label>
      <p class="hint">You will be redirected to the secure payment gateway. CEFMS never stores your card details.</p>
      <p class="actions"><button class="btn" type="submit">Pay <?= e(money($r['totalAmount'])) ?></button>
      <button class="btn ghost" type="submit" name="simulate_fail" value="1" title="Demo only: simulates a failed gateway response">Simulate gateway failure (demo)</button></p>
    </form>
    <form method="post" action="/cancel.php" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn ghost sm" type="submit">Cancel reservation</button></form>
  </div>
  <aside class="card"><h2 style="margin-top:0">Order summary</h2><div class="kv">
    <b>Event</b><span><?= e($r['title']) ?></span><b>Date</b><span><?= e(dt($r['startDateTime'])) ?></span>
    <b>Venue</b><span><?= e($r['venue']) ?></span><b>Tickets</b><span><?= (int)$r['quantity'] ?> x <?= e(money($r['ticketPrice'])) ?></span>
    <b>Total</b><span class="price"><?= e(money($r['totalAmount'])) ?></span></div></aside>
</div>
<script>
(function(){var t=document.getElementById('timer');if(!t)return;var s=+t.dataset.secs;
setInterval(function(){if(s>0)s--;t.textContent=('0'+Math.floor(s/60)).slice(-2)+':'+('0'+s%60).slice(-2);
if(s===0)t.textContent='Expired - please reserve again';},1000);})();
</script>
<?php endif; public_footer();
