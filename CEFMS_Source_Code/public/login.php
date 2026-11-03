<?php
// Login page for Event Participants.
require __DIR__ . '/../includes/bootstrap.php';

// Already logged in? Go straight to the dashboard.
$user = current_user();
if ($user && $user['role'] === 'Event Participant') {
    redirect('/dashboard.php');
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = strtolower(post('email'));
    $password = (string)($_POST['password'] ?? '');

    // Find the participant with this email
    $stmt = db()->prepare("SELECT * FROM user WHERE email = ? AND role = 'Event Participant'");
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['passwordHash'])) {
        // Same message for wrong email or wrong password, so nobody can guess which accounts exist
        $errors[] = 'Invalid email or password.';
        log_event('warning', 'Failed participant login', ['email' => $email]);
    } elseif ($row['status'] !== 'Active') {
        $errors[] = 'Your account is inactive. Please contact the administrator.';
    } else {
        login_user($row);

        // If the user was sent here from another page, go back to it afterwards
        $next = '/dashboard.php';
        if (isset($_SESSION['next'])) {
            $next = $_SESSION['next'];
            unset($_SESSION['next']);
        }
        // Only allow addresses inside this website
        if (!preg_match('#^/[A-Za-z0-9_./?=&%-]*$#', $next) || substr($next, 0, 2) === '//') {
            $next = '/dashboard.php';
        }
        redirect($next);
    }
}

public_header('Log In');
?>
<div class="card formcard"><h2 style="margin-top:0">Welcome back</h2>
<?php errors_box($errors); ?>
<form method="post" novalidate><?= csrf_field() ?>
  <label for="email">Email</label>
  <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
  <label for="password">Password</label>
  <input type="password" id="password" name="password" required>
  <p><button class="btn block" type="submit">Log In</button></p>
</form>
<p class="hint">New here? <a href="/signup.php">Create an account</a> &middot; Organizer or manager? <a href="/admin/login.php">Admin log in</a></p></div>
<?php public_footer();
