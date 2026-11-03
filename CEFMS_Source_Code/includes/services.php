<?php
/**
 * Business rules of the system. The pages call these functions.
 * A "transaction" below means: do several database steps together, and if one fails, undo all of them.
 */
declare(strict_types=1);

/** Release seats of reservations whose 15-minute hold has expired (FR7). */
function expire_reservations(): int {
    complete_past_events();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Find reservations that are still unpaid but whose 15 minutes are over
        $now = date('Y-m-d H:i:s');
        $rows = $pdo->query("SELECT reservationId, eventId, quantity FROM reservation
                             WHERE status = 'Reserved' AND expiryDate < '" . $now . "' FOR UPDATE")->fetchAll();

        // Mark each one Expired and give its seats back to the event
        foreach ($rows as $r) {
            $pdo->prepare("UPDATE reservation SET status = 'Expired' WHERE reservationId = ?")->execute([$r['reservationId']]);
            $pdo->prepare("UPDATE event SET availableSeats = availableSeats + ? WHERE eventId = ?")->execute([$r['quantity'], $r['eventId']]);
        }
        $pdo->commit();
        if (count($rows) > 0) {
            log_event('info', 'Expired ' . count($rows) . ' unpaid reservation(s)');
        }
        return count($rows);
    } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
}

/** Reserve tickets with seat availability check. Returns [ok(bool), message, reservationId]. */
function reserve_tickets(int $userId, int $eventId, int $qty): array {
    // Rule 1: the number of tickets must be between 1 and the maximum
    if ($qty < 1 || $qty > MAX_TICKETS) {
        return [false, 'Quantity must be between 1 and ' . MAX_TICKETS . '.', 0];
    }
    expire_reservations();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // "FOR UPDATE" locks this event row, so two people cannot take the last seats at the same time
        $st = $pdo->prepare("SELECT e.*, s.status AS schedStatus, s.endDateTime FROM event e
                             LEFT JOIN schedule s ON s.eventId = e.eventId WHERE e.eventId=? FOR UPDATE");
        $st->execute([$eventId]);
        $ev = $st->fetch();
        // Rule 2: the event must exist and be Published
        if (!$ev || $ev['status'] !== 'Published') {
            $pdo->rollBack();
            return [false, 'This event is not open for booking.', 0];
        }
        // Rule 3: the event must not be cancelled or already over
        if ($ev['schedStatus'] === 'Cancelled' || strtotime($ev['endDateTime']) < time()) {
            $pdo->rollBack();
            return [false, 'This event is no longer available for booking.', 0];
        }
        // Rule 4: there must be enough seats left
        if ((int)$ev['availableSeats'] < $qty) {
            $pdo->rollBack();
            log_event('info', 'Reservation refused: insufficient seats', ['eventId' => $eventId, 'wanted' => $qty, 'left' => $ev['availableSeats']]);
            return [false, 'Only ' . (int)$ev['availableSeats'] . ' seat(s) left for this event.', 0];
        }
        // All rules passed: take the seats and create the reservation (valid for 15 minutes)
        $pdo->prepare('UPDATE event SET availableSeats = availableSeats - ? WHERE eventId=?')->execute([$qty, $eventId]);
        $now = date('Y-m-d H:i:s');
        $exp = date('Y-m-d H:i:s', time() + HOLD_MINUTES * 60);
        $total = round((float)$ev['ticketPrice'] * $qty, 2);
        $pdo->prepare('INSERT INTO reservation (userId,eventId,quantity,totalAmount,reservedDate,expiryDate,status)
                       VALUES (?,?,?,?,?,?,\'Reserved\')')->execute([$userId, $eventId, $qty, $total, $now, $exp]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        log_event('info', 'Reservation created', ['reservationId' => $id, 'qty' => $qty]);
        return [true, 'Seats held for ' . HOLD_MINUTES . ' minutes. Please complete payment.', $id];
    } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
}

// Get one reservation, but only if it belongs to this user
function get_reservation(int $id, int $userId): ?array {
    $st = db()->prepare("SELECT r.*, e.title, e.ticketPrice, s.venue, s.startDateTime, s.endDateTime
                         FROM reservation r JOIN event e ON e.eventId=r.eventId
                         LEFT JOIN schedule s ON s.eventId=e.eventId
                         WHERE r.reservationId=? AND r.userId=?");
    $st->execute([$id, $userId]);
    return $st->fetch() ?: null;
}

// Make a ticket code like CEF-K7M2PQ that no other ticket already has
function new_ticket_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'CEF-';
        for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $st = db()->prepare('SELECT 1 FROM reservation WHERE ticketCode=?'); $st->execute([$code]);
    } while ($st->fetch());
    return $code;
}

/**
 * Process payment through the (simulated) gateway. $outcome is 'success' or 'fail'.
 * No card data is ever received or stored (NFR security).
 */
function pay_reservation(int $resId, int $userId, string $method, string $outcome): array {
    if (!in_array($method, ['Online Banking', 'Card', 'E-Wallet'], true)) {
        return [false, 'Please choose a payment method.'];
    }
    expire_reservations();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM reservation WHERE reservationId=? AND userId=? FOR UPDATE');
        $st->execute([$resId, $userId]);
        $r = $st->fetch();
        if (!$r) {
            $pdo->rollBack();
            return [false, 'Reservation not found.'];
        }
        if ($r['status'] === 'Paid') {
            $pdo->rollBack();
            return [false, 'This reservation has already been paid.'];
        }
        if ($r['status'] !== 'Reserved') {
            $pdo->rollBack();
            return [false, 'This reservation has expired or was cancelled. Please reserve again.'];
        }

        $ref = 'TXN' . date('ymdHis') . random_int(100, 999);
        $ok = ($outcome === 'success');      // did the (simulated) gateway accept the payment?
        $pdo->prepare('INSERT INTO payment (reservationId,amount,method,transactionRef,paymentDate,status) VALUES (?,?,?,?,?,?)')
            ->execute([$resId, $r['totalAmount'], $method, $ref, date('Y-m-d H:i:s'), $ok ? 'Successful' : 'Failed']);
        if ($ok) {
            $code = new_ticket_code();
            $pdo->prepare("UPDATE reservation SET status='Paid', ticketCode=? WHERE reservationId=?")->execute([$code, $resId]);
        }
        $pdo->commit();
        if ($ok) {
            log_event('info', 'Payment successful', ['reservationId' => $resId, 'ref' => $ref]);
            return [true, 'Payment successful. Your ticket code is ready.'];
        }
        log_event('warning', 'Payment failed', ['reservationId' => $resId, 'ref' => $ref]);
        return [false, 'Payment failed at the gateway. Your seats are still held until the hold expires - please try again.'];
    } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
}

function cancel_reservation(int $resId, int $userId): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM reservation WHERE reservationId=? AND userId=? FOR UPDATE');
        $st->execute([$resId, $userId]);
        $r = $st->fetch();
        if (!$r || $r['status'] !== 'Reserved') {
            $pdo->rollBack();
            return [false, 'Only unpaid reservations can be cancelled.'];
        }
        $pdo->prepare("UPDATE reservation SET status='Cancelled' WHERE reservationId=?")->execute([$resId]);
        $pdo->prepare('UPDATE event SET availableSeats = availableSeats + ? WHERE eventId=?')->execute([$r['quantity'], $r['eventId']]);
        $pdo->commit();
        return [true, 'Reservation cancelled and seats released.'];
    } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
}

/**
 * Venue clash check. Returns the title of the event that already uses this venue at an overlapping
 * time, or null if the venue is free. Two time slots overlap when: start < other end AND end > other start.
 */
function schedule_clash(string $venue, string $start, string $end, int $excludeEventId = 0): ?string {
    $st = db()->prepare("SELECT e.title FROM schedule s JOIN event e ON e.eventId=s.eventId
                         WHERE s.venue=? AND s.status<>'Cancelled' AND e.status='Published'
                           AND s.startDateTime < ? AND s.endDateTime > ? AND e.eventId<>? LIMIT 1");
    $st->execute([$venue, $end, $start, $excludeEventId]);
    $r = $st->fetch();
    return $r ? $r['title'] : null;
}

function valid_datetime(string $s): bool {
    // Accept the three date formats the system uses
    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        if (DateTime::createFromFormat($format, $s) !== false) {
            return true;
        }
    }
    return false;
}
function norm_datetime(string $s): string { return date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $s))); }

/** Validate event fields shared by create and edit. Returns array of error strings. */
function validate_event_fields(array $f): array {
    $err = [];
    if ($f['title'] === '' || strlen($f['title']) > 150) {
        $err[] = 'Event title is required (max 150 characters).';
    }
    if (strlen($f['description']) < 10) {
        $err[] = 'Description must be at least 10 characters.';
    }
    if (!in_array($f['category'], CATEGORIES, true)) {
        $err[] = 'Please choose a valid category.';
    }
    if (!is_numeric($f['ticketPrice']) || (float)$f['ticketPrice'] < 0 || (float)$f['ticketPrice'] > 5000) {
        $err[] = 'Ticket price must be between 0 and 5000.';
    }
    if (!ctype_digit($f['totalSeats']) || (int)$f['totalSeats'] < 1 || (int)$f['totalSeats'] > 100000) {
        $err[] = 'Total seats must be a whole number of at least 1.';
    }
    return $err;
}
function validate_schedule_fields(string $venue, string $start, string $end): array {
    $err = [];
    if ($venue === '' || strlen($venue) > 120) {
        $err[] = 'Venue is required.';
    }
    if (!valid_datetime($start) || !valid_datetime($end)) {
        $err[] = 'Start and end date/time are required.';
        return $err;
    }
    if (strtotime(norm_datetime($end)) <= strtotime(norm_datetime($start))) {
        $err[] = 'End time must be after the start time.';
    }
    return $err;
}

/** Published, non-cancelled events that have not ended yet (FR1/FR6). */
function published_events(string $cat = '', string $q = ''): array {
    $sql = "SELECT e.*, s.venue, s.startDateTime, s.endDateTime, s.status AS schedStatus
            FROM event e JOIN schedule s ON s.eventId=e.eventId
            WHERE e.status='Published' AND s.status<>'Cancelled' AND s.endDateTime >= ?";
    $p = [date('Y-m-d H:i:s')];
    // Optional filter by category
    if ($cat !== '' && in_array($cat, CATEGORIES, true)) {
        $sql .= ' AND e.category=?';
        $p[] = $cat;
    }
    // Optional search in the title or venue
    if ($q !== '') {
        $sql .= ' AND (e.title LIKE ? OR s.venue LIKE ?)';
        $p[] = "%$q%";
        $p[] = "%$q%";
    }
    $sql .= ' ORDER BY s.startDateTime';
    $st = db()->prepare($sql); $st->execute($p);
    return $st->fetchAll();
}

/** Mark published events as Completed once their schedule has ended (SDD data dictionary: Event.status). */
function complete_past_events(): void {
    db()->prepare("UPDATE event e JOIN schedule s ON s.eventId=e.eventId
                   SET e.status='Completed' WHERE e.status='Published' AND s.endDateTime < ?")
        ->execute([date('Y-m-d H:i:s')]);
}

/**
 * Archive: moves an event that is already over out of the manager's working list.
 * Only Completed or Cancelled events whose end time has passed can be archived.
 * Returns [true/false, message].
 */
function archive_event(int $eventId): array {
    complete_past_events();
    $stmt = db()->prepare("SELECT e.status, e.archived, s.endDateTime
                           FROM event e JOIN schedule s ON s.eventId = e.eventId
                           WHERE e.eventId = ?");
    $stmt->execute([$eventId]);
    $ev = $stmt->fetch();

    if (!$ev) {
        return [false, 'Event not found.'];
    }
    if ((int)$ev['archived'] === 1) {
        return [false, 'This event is already archived.'];
    }
    if ($ev['status'] !== 'Completed' && $ev['status'] !== 'Cancelled') {
        return [false, 'Only completed or cancelled events can be archived.'];
    }
    if (strtotime($ev['endDateTime']) >= time()) {
        return [false, 'This event has not finished yet, so it cannot be archived.'];
    }

    db()->prepare('UPDATE event SET archived = 1 WHERE eventId = ?')->execute([$eventId]);
    log_event('info', 'Event archived', ['eventId' => $eventId]);
    return [true, 'Event archived.'];
}

// Put an archived event back into the normal list.
function restore_event(int $eventId): array {
    $stmt = db()->prepare('UPDATE event SET archived = 0 WHERE eventId = ? AND archived = 1');
    $stmt->execute([$eventId]);
    if ($stmt->rowCount() === 0) {
        return [false, 'This event is not in the archive.'];
    }
    log_event('info', 'Event restored from archive', ['eventId' => $eventId]);
    return [true, 'Event restored from the archive.'];
}

// Archive every Completed or Cancelled event that has ended. Returns how many were archived.
function archive_all_past(): int {
    complete_past_events();
    $stmt = db()->prepare("UPDATE event e JOIN schedule s ON s.eventId = e.eventId
                           SET e.archived = 1
                           WHERE e.archived = 0 AND e.status IN ('Completed', 'Cancelled') AND s.endDateTime < ?");
    $stmt->execute([date('Y-m-d H:i:s')]);
    $count = $stmt->rowCount();
    if ($count > 0) {
        log_event('info', 'Archived past events', ['count' => $count]);
    }
    return $count;
}
