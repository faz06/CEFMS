<?php
// Event Manager: sales and event report, with filters, a print view and a CSV download.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Event Manager']);
expire_reservations();

$allStatuses = ['Pending', 'Published', 'Completed', 'Cancelled'];

// Adds one event to a group total (for example the "Music" category). Each group keeps:
// the number of events, tickets sold and revenue.
function add_to_group(array $groups, string $name, int $sold, float $revenue): array {
    if (!isset($groups[$name])) {
        $groups[$name] = ['events' => 0, 'sold' => 0, 'revenue' => 0];
    }
    $groups[$name]['events']  = $groups[$name]['events'] + 1;
    $groups[$name]['sold']    = $groups[$name]['sold'] + $sold;
    $groups[$name]['revenue'] = $groups[$name]['revenue'] + $revenue;
    return $groups;
}

// ---- 1. Read the filters from the URL ----
$from     = (string)($_GET['from'] ?? '');
$to       = (string)($_GET['to'] ?? '');
$status   = (string)($_GET['status'] ?? '');
$category = (string)($_GET['category'] ?? '');

// A date must look like 2026-11-30, otherwise ignore it
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || strtotime($from) === false) {
    $from = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || strtotime($to) === false) {
    $to = '';
}
if (!in_array($status, $allStatuses, true)) {
    $status = '';
}
if (!in_array($category, CATEGORIES, true)) {
    $category = '';
}

// ---- 2. Build the query. Only paid reservations count as sold tickets and revenue ----
$sql = "SELECT e.eventId, e.title, e.category, e.status, e.archived, e.totalSeats,
               u.fullName AS org, s.venue, s.startDateTime,
               COALESCE(SUM(CASE WHEN r.status = 'Paid' THEN r.quantity END), 0) AS sold,
               COALESCE(SUM(CASE WHEN r.status = 'Paid' THEN r.totalAmount END), 0) AS revenue
        FROM event e
        JOIN schedule s ON s.eventId = e.eventId
        JOIN user u ON u.userId = e.organizerId
        LEFT JOIN reservation r ON r.eventId = e.eventId
        WHERE 1 = 1";
$params = [];

if ($from !== '') {
    $sql .= ' AND s.startDateTime >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $sql .= ' AND s.startDateTime < ?';
    $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';   // include the whole "to" day
}
if ($status !== '') {
    $sql .= ' AND e.status = ?';
    $params[] = $status;
}
if ($category !== '') {
    $sql .= ' AND e.category = ?';
    $params[] = $category;
}
$sql .= ' GROUP BY e.eventId, s.scheduleId, u.userId ORDER BY s.startDateTime';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---- 3. Add up the totals, and the totals for each status, category and organizer ----
$totalEvents  = count($rows);
$totalSold    = 0;
$totalRevenue = 0;
$totalSeats   = 0;
$byStatus     = [];
$byCategory   = [];
$byOrganizer  = [];

foreach ($rows as $r) {
    $totalSold    = $totalSold + (int)$r['sold'];
    $totalRevenue = $totalRevenue + (float)$r['revenue'];
    $totalSeats   = $totalSeats + (int)$r['totalSeats'];

    $byStatus    = add_to_group($byStatus, $r['status'], (int)$r['sold'], (float)$r['revenue']);
    $byCategory  = add_to_group($byCategory, $r['category'], (int)$r['sold'], (float)$r['revenue']);
    $byOrganizer = add_to_group($byOrganizer, $r['org'], (int)$r['sold'], (float)$r['revenue']);
}

// Percentage of all seats that were sold
$fillRate = 0;
if ($totalSeats > 0) {
    $fillRate = round($totalSold / $totalSeats * 100, 1);
}

// ---- 4. CSV download (opens in Excel) ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    log_event('info', 'Report exported as CSV', ['rows' => $totalEvents]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cefms_report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Event', 'Organizer', 'Category', 'Venue', 'Start', 'Status', 'Archived', 'Tickets sold', 'Total seats', 'Revenue (RM)']);
    foreach ($rows as $r) {
        $line = [$r['title'], $r['org'], $r['category'], $r['venue'], $r['startDateTime'], $r['status'],
                 ((int)$r['archived'] === 1) ? 'Yes' : 'No', $r['sold'], $r['totalSeats'], number_format((float)$r['revenue'], 2, '.', '')];
        // A text cell that starts with = + - @ could run as a formula in Excel, so put a ' in front
        foreach ($line as $i => $cell) {
            if (is_string($cell) && $cell !== '' && strpos('=+-@', $cell[0]) !== false) {
                $line[$i] = "'" . $cell;
            }
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

// The same filters, to put into the CSV download link
$csvLink = '?' . http_build_query(['from' => $from, 'to' => $to, 'status' => $status, 'category' => $category, 'export' => 'csv']);

admin_header('Reports', 'reports');
?>
<form method="get" class="card noprint">
  <div class="row2">
    <div><label for="from">Event start from</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label for="to">Event start to</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  </div>
  <div class="row2">
    <div><label for="status">Status</label>
      <select id="status" name="status">
        <option value="">All statuses</option>
        <?php foreach ($allStatuses as $s) { ?>
          <option <?= ($status === $s) ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php } ?>
      </select></div>
    <div><label for="category">Category</label>
      <select id="category" name="category">
        <option value="">All categories</option>
        <?php foreach (CATEGORIES as $c) { ?>
          <option <?= ($category === $c) ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php } ?>
      </select></div>
  </div>
  <p class="actions">
    <button class="btn" type="submit">Show report</button>
    <a class="btn gray" href="/admin/reports.php">Clear filters</a>
    <a class="btn gray" href="<?= e($csvLink) ?>">Download CSV</a>
    <button class="btn gray" type="button" onclick="window.print()">Print</button>
  </p>
</form>

<p class="hint">Report created <?= e(date('d M Y, h:i A')) ?> by <?= e($user['fullName']) ?>. Tickets sold and revenue count paid bookings only. Archived events are included.</p>

<div class="stats">
  <div class="stat"><b><?= $totalEvents ?></b><span>Events</span></div>
  <div class="stat"><b><?= $totalSold ?></b><span>Tickets sold</span></div>
  <div class="stat"><b><?= e(money($totalRevenue)) ?></b><span>Revenue</span></div>
  <div class="stat"><b><?= $fillRate ?>%</b><span>Seats sold</span></div>
</div>

<?php if ($totalEvents === 0) { ?>
  <div class="card"><p class="hint">No events match these filters.</p></div>
<?php } else { ?>

<div class="card"><h2>Event details</h2>
<table>
  <thead><tr><th>Event</th><th>Organizer</th><th>Date</th><th>Status</th><th>Sold / Seats</th><th>Revenue</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r) { ?>
    <tr>
      <td><b><?= e($r['title']) ?></b><div class="hint"><?= e($r['category']) ?> &middot; <?= e($r['venue']) ?></div></td>
      <td><?= e($r['org']) ?></td>
      <td><?= e(dt($r['startDateTime'], 'd M Y')) ?></td>
      <td><?= status_badge($r['status']) ?><?php if ((int)$r['archived'] === 1) { echo ' <span class="hint">archived</span>'; } ?></td>
      <td><?= (int)$r['sold'] ?> / <?= (int)$r['totalSeats'] ?></td>
      <td><?= e(money($r['revenue'])) ?></td>
    </tr>
  <?php } ?>
  </tbody>
  <tfoot><tr><th colspan="4">Total</th><th><?= $totalSold ?> / <?= $totalSeats ?></th><th><?= e(money($totalRevenue)) ?></th></tr></tfoot>
</table></div>

<?php
// Three small summary tables that look the same, so draw them in a loop
$summaries = [
    ['By status', 'Status', $byStatus],
    ['By category', 'Category', $byCategory],
    ['By organizer', 'Organizer', $byOrganizer],
];
foreach ($summaries as $summary) { ?>
<div class="card"><h2><?= e($summary[0]) ?></h2>
<table>
  <thead><tr><th><?= e($summary[1]) ?></th><th>Events</th><th>Tickets sold</th><th>Revenue</th></tr></thead>
  <tbody>
  <?php foreach ($summary[2] as $name => $g) { ?>
    <tr><td><?= e($name) ?></td><td><?= (int)$g['events'] ?></td><td><?= (int)$g['sold'] ?></td><td><?= e(money($g['revenue'])) ?></td></tr>
  <?php } ?>
  </tbody>
</table></div>
<?php } ?>

<?php } ?>
<?php admin_footer();
