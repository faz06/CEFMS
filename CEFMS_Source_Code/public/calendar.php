<?php
// Event calendar page: shows one month as a grid, with the published events on their start day.
require __DIR__ . '/../includes/bootstrap.php';
expire_reservations();

// 1. Which month to show? It comes from the URL, e.g. calendar.php?m=2026-11
$month = date('Y-m');                       // default = this month
if (isset($_GET['m'])) {
    $month = (string)$_GET['m'];
}
// If the value is not in the form YYYY-MM, go back to this month
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}

// 2. Work out the dates we need
$firstDay    = strtotime($month . '-01');           // first day of the month
$daysInMonth = (int)date('t', $firstDay);           // 28, 29, 30 or 31
$prevMonth   = date('Y-m', strtotime('-1 month', $firstDay));
$nextMonth   = date('Y-m', strtotime('+1 month', $firstDay));
$monthStart  = date('Y-m-01 00:00:00', $firstDay);
$monthEnd    = date('Y-m-01 00:00:00', strtotime('+1 month', $firstDay));

// 3. Get the published events that start in this month
$sql = "SELECT e.eventId, e.title, s.startDateTime
        FROM event e
        JOIN schedule s ON s.eventId = e.eventId
        WHERE e.status = 'Published'
          AND s.status <> 'Cancelled'
          AND s.startDateTime >= ?
          AND s.startDateTime < ?
        ORDER BY s.startDateTime";
$stmt = db()->prepare($sql);
$stmt->execute([$monthStart, $monthEnd]);
$events = $stmt->fetchAll();

// 4. Group the events by day number (1-31)
$eventsByDay = [];
foreach ($events as $event) {
    $dayNumber = (int)date('j', strtotime($event['startDateTime']));
    $eventsByDay[$dayNumber][] = $event;
}

// 5. How many empty boxes before day 1? (Monday = 0 empty boxes)
$emptyBoxes = (int)date('N', $firstDay) - 1;

public_header('Event Calendar', 'calendar');
?>
<div class="cal-nav">
  <a class="btn ghost sm" href="?m=<?= $prevMonth ?>">&laquo; Previous</a>
  <h2 style="margin:0"><?= e(date('F Y', $firstDay)) ?></h2>
  <a class="btn ghost sm" href="?m=<?= $nextMonth ?>">Next &raquo;</a>
</div>

<div class="cal" role="grid" aria-label="Event calendar">
  <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName) { ?>
    <div class="h"><?= $dayName ?></div>
  <?php } ?>

  <?php for ($i = 0; $i < $emptyBoxes; $i++) { ?>
    <div class="d off"></div>
  <?php } ?>

  <?php for ($day = 1; $day <= $daysInMonth; $day++) {
      $thisDate = $month . '-' . sprintf('%02d', $day);
      $class = 'd';
      if ($thisDate === date('Y-m-d')) {
          $class = 'd today';          // highlight today
      }
  ?>
    <div class="<?= $class ?>">
      <div class="n"><?= $day ?></div>
      <?php if (isset($eventsByDay[$day])) { ?>
        <?php foreach ($eventsByDay[$day] as $event) { ?>
          <a class="ce" href="/event.php?id=<?= (int)$event['eventId'] ?>" title="<?= e($event['title']) ?>"><?= e($event['title']) ?></a>
        <?php } ?>
      <?php } ?>
    </div>
  <?php } ?>
</div>
<p class="hint">Select an event on the calendar to view its details.</p>
<?php public_footer();
