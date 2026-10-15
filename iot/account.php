<?php
declare(strict_types=1);

require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];

if (empty($_SESSION['change_password_csrf_token'])) {
    $_SESSION['change_password_csrf_token'] = bin2hex(random_bytes(32));
}

$redirect_with_flash = static function (string $type, string $message): never {
    $_SESSION['account_flash'] = [
        'type' => $type,
        'message' => $message
    ];
    header('Location: account.php');
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['change_password_csrf_token'] ?? '';
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (
        !is_string($posted_token)
        || !is_string($session_token)
        || $session_token === ''
        || !hash_equals($session_token, $posted_token)
    ) {
        $redirect_with_flash('error', 'Your form session expired. Please try again.');
    }

    if (
        !is_string($current_password)
        || !is_string($new_password)
        || !is_string($confirm_password)
        || $current_password === ''
        || $new_password === ''
        || $confirm_password === ''
    ) {
        $redirect_with_flash('error', 'Complete all password fields.');
    }

    if (strlen($new_password) < 8) {
        $redirect_with_flash('error', 'The new password must contain at least 8 characters.');
    }

    if (!hash_equals($new_password, $confirm_password)) {
        $redirect_with_flash('error', 'New password and confirmation do not match.');
    }

    try {
        $stmt = $conn->prepare("
            SELECT password_hash
            FROM teachers
            WHERE teacher_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows !== 1) {
            $redirect_with_flash('error', 'Unable to verify this teacher account.');
        }

        $current_hash = (string) $result->fetch_assoc()['password_hash'];

        if (!password_verify($current_password, $current_hash)) {
            $redirect_with_flash('error', 'Current password is incorrect.');
        }

        if (hash_equals($current_password, $new_password)) {
            $redirect_with_flash('error', 'The new password must be different from the current password.');
        }

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

        if (!is_string($new_hash) || $new_hash === '') {
            throw new RuntimeException('Password hashing failed.');
        }

        $update_stmt = $conn->prepare("
            UPDATE teachers
            SET password_hash = ?
            WHERE teacher_id = ?
        ");
        $update_stmt->bind_param('si', $new_hash, $teacher_id);
        $update_stmt->execute();

        if ($update_stmt->affected_rows !== 1) {
            throw new RuntimeException('Password update did not affect one account.');
        }

        unset($_SESSION['change_password_csrf_token']);
        $redirect_with_flash('success', 'Password changed successfully.');
    } catch (Throwable $exception) {
        error_log('Change password failed for teacher ' . $teacher_id . '.');
        $redirect_with_flash('error', 'Unable to change the password. Please try again.');
    }
}

$flash_messages = [];

if (isset($_SESSION['account_flash']) && is_array($_SESSION['account_flash'])) {
    $flash_messages[] = $_SESSION['account_flash'];
    unset($_SESSION['account_flash']);
}

$page_title = 'Teacher Account';
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page">
    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1>Teacher Account</h1>
            <p>Update the password used to sign in to your teacher portal.</p>
        </div>
    </header>

    <section class="form-card account-card" aria-labelledby="change-password-heading">
        <div class="account-card-heading">
            <span class="card-icon" aria-hidden="true">
                <i class="ti ti-lock-password"></i>
            </span>
            <div>
                <span class="section-kicker">Security</span>
                <h2 id="change-password-heading">Change Password</h2>
                <p>Use at least 8 characters and choose a password different from your current one.</p>
            </div>
        </div>

        <form method="POST" action="account.php" class="account-password-form">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    (string) $_SESSION['change_password_csrf_token'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <div class="form-group">
                <label for="current-password">Current Password</label>
                <input
                    type="password"
                    id="current-password"
                    name="current_password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >
            </div>

            <div class="form-group">
                <label for="new-password">New Password</label>
                <input
                    type="password"
                    id="new-password"
                    name="new_password"
                    class="form-control"
                    autocomplete="new-password"
                    minlength="8"
                    required
                >
            </div>

            <div class="form-group">
                <label for="confirm-password">Confirm New Password</label>
                <input
                    type="password"
                    id="confirm-password"
                    name="confirm_password"
                    class="form-control"
                    autocomplete="new-password"
                    minlength="8"
                    required
                >
            </div>

            <div class="form-actions">
                <a href="dashboard.php" class="cancel-button">Cancel</a>
                <button type="submit" class="save-button">
                    <i class="ti ti-device-floppy" aria-hidden="true"></i>
                    Change Password
                </button>
            </div>
        </form>
    </section>
</main>

<?php include 'includes/layout_end.php'; ?>
