<?php
// Event Manager: add, edit, activate and deactivate organizers.
require __DIR__ . '/../../includes/bootstrap.php';
$user = require_admin(['Event Manager']);
$pdo = db();

$errors = [];
$values = ['fullName' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'add') {
        // ---- Add a new organizer ----
        $values['fullName'] = post('fullName');
        $values['email']    = strtolower(post('email'));
        $values['phone']    = post('phone');
        $password = (string)($_POST['password'] ?? '');

        if ($values['fullName'] === '') {
            $errors[] = 'Full name is required.';
        }
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!valid_phone($values['phone'])) {
            $errors[] = 'Phone number must be a valid Malaysian number, e.g. 0123456789.';
        }
        if (!valid_password($password)) {
            $errors[] = 'Temporary password must be at least 8 characters with letters and numbers.';
        }

        // Email must not be used already
        if (count($errors) === 0) {
            $check = $pdo->prepare('SELECT 1 FROM user WHERE email = ?');
            $check->execute([$values['email']]);
            if ($check->fetch()) {
                $errors[] = 'This email is already registered.';
            }
        }

        if (count($errors) === 0) {
            $sql = "INSERT INTO user (fullName, email, phone, passwordHash, role, status)
                    VALUES (?, ?, ?, ?, 'Organizer', 'Active')";
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare($sql)->execute([$values['fullName'], $values['email'], $values['phone'], $hash]);
            log_event('info', 'Organizer added', ['email' => $values['email']]);
            flash('Organizer account created.');
            redirect('/admin/organizers.php');
        }

    } elseif ($action === 'activate' || $action === 'deactivate') {
        // ---- Switch an organizer on or off ----
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'activate') {
            $newStatus = 'Active';
        } else {
            $newStatus = 'Inactive';
        }
        $pdo->prepare("UPDATE user SET status = ? WHERE userId = ? AND role = 'Organizer'")->execute([$newStatus, $id]);
        log_event('info', 'Organizer ' . $action . 'd', ['userId' => $id]);
        flash('Organizer ' . $action . 'd.');
        redirect('/admin/organizers.php');
    }
}

// All organizers, with how many events each one has
$rows = $pdo->query("SELECT u.*,
                       (SELECT COUNT(*) FROM event e WHERE e.organizerId = u.userId) AS evCount
                     FROM user u
                     WHERE u.role = 'Organizer'
                     ORDER BY u.status, u.fullName")->fetchAll();

admin_header('Manage Organizer', 'orgs');
?>
<div class="card"><h2>Organizers</h2>
<table>
  <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Events</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r) { ?>
    <tr>
      <td><?= e($r['fullName']) ?></td>
      <td><?= e($r['email']) ?></td>
      <td><?= e($r['phone']) ?></td>
      <td><?= (int)$r['evCount'] ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td><div class="actions">
        <a class="btn sm gray" href="/admin/organizer_edit.php?id=<?= (int)$r['userId'] ?>">Edit</a>
        <form method="post" class="inline"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['userId'] ?>">
          <?php if ($r['status'] === 'Active') { ?>
            <button class="btn sm danger" name="action" value="deactivate">Deactivate</button>
          <?php } else { ?>
            <button class="btn sm ok" name="action" value="activate">Activate</button>
          <?php } ?>
        </form>
      </div></td>
    </tr>
  <?php } ?>
  </tbody>
</table></div>

<div class="card" style="max-width:820px"><h2>Add organizer</h2><?php errors_box($errors); ?>
<form method="post" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="add">
  <div class="row2">
    <div><label for="fullName">Full name</label><input type="text" id="fullName" name="fullName" value="<?= e($values['fullName']) ?>"></div>
    <div><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($values['email']) ?>"></div>
  </div>
  <div class="row2">
    <div><label for="phone">Phone</label><input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>"></div>
    <div><label for="password">Temporary password</label><input type="password" id="password" name="password"></div>
  </div>
  <p><button class="btn" type="submit">Add organizer</button></p>
</form></div>
<?php admin_footer();
