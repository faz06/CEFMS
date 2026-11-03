<?php
declare(strict_types=1);

/* ===== PUBLIC SITE LAYOUT: the header and footer shared by all public pages ===== */
function public_flash(): void {
    $f = flash();                    // read the one-time message, if any
    if ($f) {
        echo '<div class="alert ' . e($f['type']) . '" role="alert">' . e($f['msg']) . '</div>';
    }
}
function public_header(string $title, string $active = '', bool $hero = false): void {
    $u = current_user();
    // Is a participant logged in?
    $isP = false;
    if ($u && $u['role'] === 'Event Participant') {
        $isP = true;
    }
    // Menu links: key => [address, text]
    $nav = [
        'home'     => ['/', 'Home'],
        'events'   => ['/events.php', 'Events'],
        'calendar' => ['/calendar.php', 'Event Calendar'],
    ];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | CEFMS</title>
<link rel="stylesheet" href="/assets/public.css"></head>
<body class="pub">
<header class="topnav"><div class="wrap nav-in">
  <a class="brand" href="/"><span class="logo" aria-hidden="true"></span><span>CEFMS</span></a>
  <nav class="links">
    <?php foreach ($nav as $key => $link) { ?>
      <a href="<?= $link[0] ?>" class="<?= ($active === $key) ? 'on' : '' ?>"><?= $link[1] ?></a>
    <?php } ?>
    <?php if ($isP): ?><a href="/dashboard.php" class="<?= $active === 'dash' ? 'on' : '' ?>">My Dashboard</a><?php endif; ?>
  </nav>
  <div class="auth">
    <?php if ($isP): ?>
      <span class="hello">Hi, <?= e(explode(' ', $u['fullName'])[0]) ?></span>
      <a class="btn ghost" href="/logout.php">Log Out</a>
    <?php else: ?>
      <a class="btn ghost" href="/login.php">Log In</a><a class="btn gold" href="/signup.php">Sign Up</a>
    <?php endif; ?>
  </div>
</div></header>
<main>
<?php if (!$hero) { ?><div class="wrap"><?php public_flash(); }
}
function public_footer(): void { ?>
</div></main>
<footer class="foot"><div class="wrap"><div><b>CEFMS</b><br>Cultural Events and Festivals Management System</div><div>Malaysia<br>&copy; 2026</div></div></footer>
</body></html><?php
}

/* ===== ADMIN PANEL LAYOUT: sidebar and header shared by all admin pages ===== */
function admin_header(string $title, string $active): void {
    $u = current_user();
    $menu = [];
    if ($u) {
        $menu[] = ['dash', '/admin/index.php', 'Dashboard'];
        if ($u['role'] === 'Organizer') {
            $menu[] = ['create', '/admin/event_create.php', 'Create Event'];
            $menu[] = ['history', '/admin/history.php', 'Event History'];
        } else {
            $menu[] = ['events', '/admin/events.php', 'Manage Event Details'];
            $menu[] = ['schedule', '/admin/schedule.php', 'Manage Schedule'];
            $menu[] = ['orgs', '/admin/organizers.php', 'Manage Organizer'];
            $menu[] = ['reports', '/admin/reports.php', 'Reports'];
            $menu[] = ['archive', '/admin/archive.php', 'Event Archive'];
        }
    }
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | CEFMS Admin</title>
<link rel="stylesheet" href="/assets/admin.css"></head>
<body class="adm">
<aside class="side">
  <div class="side-brand"><span class="logo" aria-hidden="true"></span>CEFMS<small>Admin Panel</small></div>
  <?php if ($u): ?>
  <nav>
    <?php foreach ($menu as $item) { ?>
      <a href="<?= $item[1] ?>" class="<?= ($active === $item[0]) ? 'on' : '' ?>"><?= $item[2] ?></a>
    <?php } ?>
  </nav>
  <div class="side-user"><strong><?= e($u['fullName']) ?></strong><span><?= e($u['role']) ?></span>
    <a href="/admin/logout.php">Log Out</a></div>
  <?php endif; ?>
</aside>
<section class="main">
  <div class="top"><h1><?= e($title) ?></h1></div>
  <div class="content">
<?php $f = flash(); if ($f) { ?><div class="alert <?= e($f['type']) ?>" role="alert"><?= e($f['msg']) ?></div><?php }
}
function admin_footer(): void { ?>
  </div>
</section>
</body></html><?php
}

function status_badge(string $s): string {
    $cls = strtolower(str_replace(' ', '-', $s));
    return '<span class="badge b-' . e($cls) . '">' . e($s) . '</span>';
}
function errors_box(array $errs): void {
    if (count($errs) === 0) {
        return;                      // nothing to show
    }
    echo '<div class="alert err" role="alert"><ul>';
    foreach ($errs as $x) {
        echo '<li>' . e($x) . '</li>';
    }
    echo '</ul></div>';
}

// One event "ticket" card, used on the home page and the events page
function event_card(array $ev): void {
    $t = strtotime($ev['startDateTime']);
    if ((float)$ev['ticketPrice'] == 0) {
        $priceText = 'Free';
    } else {
        $priceText = money($ev['ticketPrice']);
    }
?>
  <article class="stub">
    <a class="date" href="/event.php?id=<?= (int)$ev['eventId'] ?>" aria-label="<?= e(date('j F Y', $t)) ?>"><b><?= date('j', $t) ?></b><span><?= date('M', $t) ?></span><i><?= date('D', $t) ?></i></a>
    <div class="info">
      <span class="cat"><?= e($ev['category']) ?></span>
      <h3><a href="/event.php?id=<?= (int)$ev['eventId'] ?>"><?= e($ev['title']) ?></a></h3>
      <div class="meta"><?= e(dt($ev['startDateTime'], 'h:i A')) ?> at <?= e($ev['venue']) ?></div>
      <div class="foot2">
        <span class="price"><?= e($priceText) ?></span>
        <a class="btn sm" href="/event.php?id=<?= (int)$ev['eventId'] ?>">View details</a>
      </div>
    </div>
  </article>
<?php }
