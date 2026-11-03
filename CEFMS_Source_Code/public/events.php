<?php
// All events page, with a category filter and a search box.
require __DIR__ . '/../includes/bootstrap.php';
expire_reservations();

$category = (string)($_GET['cat'] ?? '');
$search   = trim((string)($_GET['q'] ?? ''));

$events = published_events($category, $search);   // see services.php

public_header('Events', 'events');
?>
<h2>All events</h2>

<div class="chips" style="margin-bottom:14px">
  <?php $allClass = ($category === '') ? 'chip on' : 'chip'; ?>
  <a class="<?= $allClass ?>" href="/events.php">All</a>
  <?php foreach (CATEGORIES as $c) {
      $chipClass = ($category === $c) ? 'chip on' : 'chip';
  ?>
    <a class="<?= $chipClass ?>" href="/events.php?cat=<?= urlencode($c) ?>"><?= e($c) ?></a>
  <?php } ?>
</div>

<form method="get" style="display:flex;gap:8px;margin-bottom:18px;max-width:520px">
  <input type="hidden" name="cat" value="<?= e($category) ?>">
  <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search event or venue" aria-label="Search">
  <button class="btn" type="submit">Search</button>
</form>

<?php if (count($events) === 0) { ?>
  <div class="card empty">No events match your search.</div>
<?php } else { ?>
  <div class="stubs">
    <?php foreach ($events as $ev) { event_card($ev); } ?>
  </div>
<?php } ?>
<?php public_footer();
