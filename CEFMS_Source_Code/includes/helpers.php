<?php
declare(strict_types=1);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return 'RM ' . number_format((float)$v, 2); }
function dt(string $s, string $fmt = 'd M Y, h:i A'): string { return date($fmt, strtotime($s)); }

function redirect(string $url): never { header('Location: ' . $url); exit; }

// flash('text') stores a one-time message. flash() with no text reads it back.
function flash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    // Reading the message also removes it, so it is shown only once
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---- CSRF ---------------------------------------------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    // The token in the form must match the token saved in the session
    $ok = false;
    if (isset($_POST['csrf']) && isset($_SESSION['csrf'])) {
        $ok = hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
    }
    if (!$ok) {
        log_event('warning', 'CSRF validation failed');
        http_response_code(403);
        exit('Invalid or expired form. Please go back, refresh the page and try again.');
    }
}

// ---- Authentication / authorisation -------------------------------------------------------
/**
 * Returns the logged-in user. The account status is re-checked in the database on every request so that
 * a deactivated account loses access immediately instead of at the end of its session.
 */
function current_user(): ?array {
    static $checked = null;          // remembers that we already checked during this request
    if (empty($_SESSION['user'])) {
        return null;                 // nobody is logged in
    }
    if ($checked !== $_SESSION['user']['userId']) {
        $st = db()->prepare('SELECT status, role FROM user WHERE userId=?');
        $st->execute([$_SESSION['user']['userId']]);
        $row = $st->fetch();
        // If the account was deleted or deactivated, log the person out straight away
        if (!$row || $row['status'] !== 'Active') {
            log_event('warning', 'Session ended: account inactive or removed', ['userId' => $_SESSION['user']['userId']]);
            unset($_SESSION['user']);
            return null;
        }
        $_SESSION['user']['role'] = $row['role'];
        $checked = $_SESSION['user']['userId'];
    }
    return $_SESSION['user'];
}

function login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user'] = ['userId' => (int)$u['userId'], 'fullName' => $u['fullName'],
                         'email' => $u['email'], 'role' => $u['role']];
}
function logout_user(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

/** Public site: participant must be logged in. */
function require_participant(): array {
    $u = current_user();
    if (!$u || $u['role'] !== 'Event Participant') {
        flash('Please log in to continue.', 'warn');
        $_SESSION['next'] = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('/login.php');
    }
    return $u;
}
/** Admin panel: organizer / event manager. $roles limits which one may enter the page. */
function require_admin(array $roles = ['Organizer', 'Event Manager']): array {
    $u = current_user();
    if (!$u || !in_array($u['role'], ['Organizer', 'Event Manager'], true)) {
        redirect('/admin/login.php');
    }
    if (!in_array($u['role'], $roles, true)) {
        log_event('warning', 'Forbidden admin page for role ' . $u['role']);
        http_response_code(403);
        admin_header('Access denied', '');
        echo '<div class="card"><h2>Access denied</h2><p>Your role (' . e($u['role']) . ') is not allowed to open this page.</p></div>';
        admin_footer();
        exit;
    }
    return $u;
}

// ---- Validation ----------------------------------------------------------------------------------
function valid_password(string $p): bool {
    // At least 8 characters, with at least one letter and one number
    if (strlen($p) < 8) {
        return false;
    }
    if (!preg_match('/[A-Za-z]/', $p)) {
        return false;
    }
    if (!preg_match('/\d/', $p)) {
        return false;
    }
    return true;
}
// Malaysian phone number: starts with 0, then 8 to 10 digits
function valid_phone(string $p): bool {
    return (bool)preg_match('/^0\d{8,10}$/', $p);
}

// Read one text field from a submitted form, with spaces trimmed
function post(string $k): string {
    return trim((string)($_POST[$k] ?? ''));
}

const CATEGORIES = ['Music', 'Dance', 'Craft', 'Food', 'Religious', 'Multi-cultural'];
