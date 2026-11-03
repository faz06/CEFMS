<?php
// Sign-up page for new Event Participants.
require __DIR__ . '/../includes/bootstrap.php';

$user = current_user();
if ($user && $user['role'] === 'Event Participant') {
    redirect('/dashboard.php');
}

$errors = [];
$values = ['fullName' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Read what the user typed
    $values['fullName'] = post('fullName');
    $values['email']    = strtolower(post('email'));
    $values['phone']    = post('phone');
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    // Check each field
    if ($values['fullName'] === '' || strlen($values['fullName']) > 100) {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!valid_phone($values['phone'])) {
        $errors[] = 'Phone number must be a valid Malaysian number, e.g. 0123456789.';
    }
    if (!valid_password($password)) {
        $errors[] = 'Password must be at least 8 characters and contain letters and numbers.';
    }
    if ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    }

    // Is the email already used?
    if (count($errors) === 0) {
        $stmt = db()->prepare('SELECT 1 FROM user WHERE email = ?');
        $stmt->execute([$values['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'This email is already registered. Please log in instead.';
        }
    }

    // No problems: save the new account (the password is saved as a hash, never as plain text)
    if (count($errors) === 0) {
        $sql = "INSERT INTO user (fullName, email, phone, passwordHash, role, status)
                VALUES (?, ?, ?, ?, 'Event Participant', 'Active')";
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare($sql)->execute([$values['fullName'], $values['email'], $values['phone'], $hash]);

        log_event('info', 'New participant registered', ['email' => $values['email']]);
        flash('Account created successfully. Please log in.');
        redirect('/login.php');
    }
}

public_header('Sign Up');
?>
<div class="card formcard"><h2 style="margin-top:0">Create your account</h2>
<?php errors_box($errors); ?>
<form method="post" novalidate><?= csrf_field() ?>
  <label for="fullName">Full name</label>
  <input type="text" id="fullName" name="fullName" value="<?= e($values['fullName']) ?>" required>
  <label for="email">Email</label>
  <input type="email" id="email" name="email" value="<?= e($values['email']) ?>" required>
  <label for="phone">Phone number</label>
  <input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>" placeholder="0123456789" required>
  <label for="password">Password</label>
  <input type="password" id="password" name="password" required>
  <div class="hint">At least 8 characters with letters and numbers.</div>
  <label for="password2">Confirm password</label>
  <input type="password" id="password2" name="password2" required>
  <p><button class="btn block" type="submit">Sign Up</button></p>
</form>
<p class="hint">Already have an account? <a href="/login.php">Log in</a></p></div>
<?php public_footer();
