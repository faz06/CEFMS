<?php
// Cancels an unpaid reservation and gives the seats back.
require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/dashboard.php');
}

$user = require_participant();
csrf_check();

$reservationId = (int)($_POST['id'] ?? 0);
list($ok, $message) = cancel_reservation($reservationId, $user['userId']);

if ($ok) {
    flash($message, 'ok');
} else {
    flash($message, 'err');
}
redirect('/dashboard.php');
