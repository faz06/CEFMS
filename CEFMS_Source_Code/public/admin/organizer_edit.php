<?php
// Event Manager: edit an organizer's name, email and phone.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Event Manager']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM user WHERE userId = ? AND role = 'Organizer'");
$stmt->execute([$id]);
$organizer = $stmt->fetch();

if (!$organizer) {
    flash('Organizer not found.', 'err');
    redirect('/admin/organizers.php');
}

$errors = [];
$values = ['fullName' => $organizer['fullName'], 'email' => $organizer['email'], 'phone' => $organizer['phone']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values['fullName'] = post('fullName');
    $values['email']    = strtolower(post('email'));
    $values['phone']    = post('phone');

    if ($values['fullName'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!valid_phone($values['phone'])) {
        $errors[] = 'Phone number must be a valid Malaysian number, e.g. 0123456789.';
    }

    // Is the email used by a different account?
    if (count($errors) === 0) {
        $check = db()->prepare('SELECT 1 FROM user WHERE email = ? AND userId <> ?');
        $check->execute([$values['email'], $id]);
        if ($check->fetch()) {
            $errors[] = 'This email is used by another account.';
        }
    }

    if (count($errors) === 0) {
        $sql = 'UPDATE user SET fullName = ?, email = ?, phone = ? WHERE userId = ?';
        db()->prepare($sql)->execute([$values['fullName'], $values['email'], $values['phone'], $id]);
        log_event('info', 'Organizer updated', ['userId' => $id]);
        flash('Organizer details updated.');
        redirect('/admin/organizers.php');
    }
}

admin_header('Edit Organizer', 'orgs');
?>
<div class="card" style="max-width:620px"><?php errors_box($errors); ?>
<form method="post" novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <label for="fullName">Full name</label>
  <input type="text" id="fullName" name="fullName" value="<?= e($values['fullName']) ?>">
  <label for="email">Email</label>
  <input type="email" id="email" name="email" value="<?= e($values['email']) ?>">
  <label for="phone">Phone</label>
  <input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>">
  <p class="actions"><button class="btn" type="submit">Save changes</button><a class="btn gray" href="/admin/organizers.php">Back</a></p>
</form></div>
<?php admin_footer();
