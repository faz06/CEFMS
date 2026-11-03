<?php
// Receives the "Reserve / Buy Ticket" form and holds the seats.
require __DIR__ . '/../includes/bootstrap.php';

// This page only accepts a form submission, not a plain link
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/events.php');
}

$user = require_participant();   // must be a logged-in participant
csrf_check();                    // check the hidden security token

$eventId  = (int)($_POST['eventId'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);

// The rules (enough seats? valid quantity?) are in services.php
list($ok, $message, $reservationId) = reserve_tickets($user['userId'], $eventId, $quantity);

if ($ok) {
    flash($message, 'ok');
    redirect('/payment.php?id=' . $reservationId);   // go and pay
}
flash($message, 'err');
redirect('/event.php?id=' . $eventId);               // back to the event with the error
