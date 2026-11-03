<?php
// Login page for the admin panel (Organizers and Event Managers).
require __DIR__ . '/../../includes/bootstrap.php';

// Already logged in as admin? Go to the dashboard.
$user = current_user();
if ($user && ($user['role'] === 'Organizer' || $user['role'] === 'Event Manager')) {
    redirect('/admin/index.php');
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = strtolower(post('email'));
    $password = (string)($_POST['password'] ?? '');

    $stmt = db()->prepare("SELECT * FROM user WHERE email = ? AND role IN ('Organizer', 'Event Manager')");
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['passwordHash'])) {
        $errors[] = 'Invalid email or password.';
        log_event('warning', 'Failed admin login', ['email' => $email]);
    } elseif ($row['status'] !== 'Active') {
        $errors[] = 'This account has been deactivated. Please contact the Event Manager.';
        log_event('warning', 'Deactivated account attempted admin login', ['email' => $email]);
    } else {
        login_user($row);
        redirect('/admin/index.php');
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Log In | CEFMS</title><link rel="stylesheet" href="/assets/admin.css"></head>
<body class="adm alone"><div class="loginbox card">
  <h2 style="font-size:20px"><span class="logo" aria-hidden="true"></span> CEFMS Admin Panel</h2>
  <p class="hint">For Organizers and Event Managers only. Participants please use the <a href="/login.php">public log in</a>.</p>
  <?php errors_box($errors); ?>
  <form method="post" novalidate><?= csrf_field() ?>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>
    <p><button class="btn" type="submit" style="width:100%">Log In</button></p>
  </form>
</div></body></html>
