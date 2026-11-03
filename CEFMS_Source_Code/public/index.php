<?php
// Home page: hero, next event, categories and the upcoming events.
require __DIR__ . '/../includes/bootstrap.php';
expire_reservations();

$allEvents = published_events();
$events    = array_slice($allEvents, 0, 6);     // show the first 6 only

// The next event is the first one in the list (the list is sorted by date)
$next = null;
if (count($allEvents) > 0) {
    $next = $allEvents[0];
}

public_header('Home', 'home', true);
?>
<section class="hero2"><div class="wrap hero-in">
  <div>
    <h1>Cultural events and festivals across Malaysia</h1>
    <p>Browse heritage festivals, traditional dance, craft markets and folk music. Reserve your tickets online in minutes.</p>
    <form action="/events.php" method="get">
      <input type="text" name="q" placeholder="Search event or venue" aria-label="Search events">
      <select name="cat" aria-label="Category">
        <option value="">All categories</option>
        <?php foreach (CATEGORIES as $c) { ?>
          <option><?= e($c) ?></option>
        <?php } ?>
      </select>
      <button class="btn gold" type="submit">Search events</button>
    </form>
  </div>

  <?php if ($next !== null) {
      $t = strtotime($next['startDateTime']);
      if ((float)$next['ticketPrice'] == 0) {
          $priceText = 'Free';
      } else {
          $priceText = money($next['ticketPrice']);
      }
  ?>
  <article class="next">
    <a class="date" href="/event.php?id=<?= (int)$next['eventId'] ?>" aria-label="<?= e(date('j F Y', $t)) ?>"><b><?= date('j', $t) ?></b><span><?= date('M', $t) ?></span><i><?= date('D', $t) ?></i></a>
    <div class="info">
      <p class="when">Coming up next</p>
      <h3><?= e($next['title']) ?></h3>
      <div class="meta"><?= e(dt($next['startDateTime'], 'h:i A')) ?> at <?= e($next['venue']) ?></div>
      <div class="foot2" style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
        <span class="price"><?= e($priceText) ?></span>
        <a class="btn sm" href="/event.php?id=<?= (int)$next['eventId'] ?>">View details</a>
      </div>
    </div>
  </article>
  <?php } ?>
</div></section>

<div class="wrap">
<?php public_flash(); ?>
<h2>Browse by category</h2>
<div class="chips">
  <?php foreach (CATEGORIES as $c) { ?>
    <a class="chip" href="/events.php?cat=<?= urlencode($c) ?>"><?= e($c) ?></a>
  <?php } ?>
</div>

<h2>Upcoming events</h2>
<?php if (count($events) === 0) { ?>
  <div class="card empty">No upcoming events at the moment. Please check back soon.</div>
<?php } else { ?>
  <div class="stubs">
    <?php foreach ($events as $ev) { event_card($ev); } ?>
  </div>
  <p style="margin-top:22px" class="actions">
    <a class="btn" href="/events.php">See all events</a>
    <a class="btn ghost" href="/calendar.php">Open event calendar</a>
  </p>
<?php } ?>
<?php public_footer();
