<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
stemSessionStart();

if (isset($_SESSION['teacher_id'])) {
    header("Location: dashboard.php");
    exit;
}

require_once 'config/db.php';

if (empty($_SESSION['register_csrf_token'])) {
    $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['register_csrf_token'];
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $invite_code = (string) ($_POST['invite_code'] ?? '');
    $register_wait = stemThrottleWait('register', stemClientIp(), 8, 3600);

    if (!is_string($posted_token) || !hash_equals($session_token, $posted_token)) {
        $error = 'Your form session expired. Please try again.';
    } elseif ($register_wait > 0) {
        $error = 'Too many attempts. Try again in ' . stemWaitText($register_wait) . '.';
    } elseif (stemInviteCode() === null) {
        $error = 'Registration is closed. Ask the person who runs this site for an account.';
    } elseif (!stemInviteCodeValid($invite_code)) {
        stemThrottleHit('register', stemClientIp(), 3600);
        $error = 'The invite code is not correct. Ask the person who runs this site for it.';
    } elseif (!preg_match('/^.{1,100}$/u', $name)) {
        $error = 'Enter your name (up to 100 characters).';
    } elseif (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address.';
    } elseif (!is_string($password) || strlen($password) < 8) {
        $error = 'The password must contain at least 8 characters.';
    } elseif (!is_string($confirm_password) || !hash_equals($password, $confirm_password)) {
        $error = 'Password and confirmation do not match.';
    }

    if ($error === '') {
        $stmt = $conn->prepare("
            SELECT teacher_id
            FROM teachers
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            $error = 'An account with this email already exists.';
        }
    }

    if ($error === '') {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $conn->prepare("
                INSERT INTO teachers (name, email, password_hash)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param("sss", $name, $email, $password_hash);
            $stmt->execute();

            session_regenerate_id(true);
            unset($_SESSION['register_csrf_token']);

            $_SESSION['teacher_id'] = $conn->insert_id;
            $_SESSION['teacher_name'] = $name;
            $_SESSION['teacher_email'] = $email;

            header("Location: dashboard.php");
            exit;

        } catch (mysqli_sql_exception $exception) {
            // Another request registered the same email in the meantime.
            if ($exception->getCode() === 1062) {
                $error = 'An account with this email already exists.';
            } else {
                error_log('Teacher registration failed with error code ' . $exception->getCode() . '.');
                $error = 'Unable to create the account. Please try again later.';
            }
        }
    }
}
?>

<?php
$page_title = 'Create account | STEM Quiz';
$layout_show_navigation = false;
include 'includes/layout_start.php';
?>

    <?php
    $wordmark_href = 'login.php';
    $wordmark_class = 'lake-wordmark';
    include 'includes/wordmark.php';
    ?>

    <main class="lake-entry">
        <header class="lake-title" data-sky-content>
            <h1>Create your account</h1>
            <p>Set up classes and run live quizzes with your students.</p>
        </header>

        <div class="lake-waterline" data-waterline-anchor></div>

        <section class="login-container lake-card" aria-label="Create an account">
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['register_csrf_token']) ?>"
                >

                <div class="form-group">
                    <label for="invite_code">Invite code</label>

                    <input
                        type="text"
                        id="invite_code"
                        name="invite_code"
                        placeholder="STEM-XXXX-XXXX"
                        value="<?= htmlspecialchars($_POST['invite_code'] ?? '') ?>"
                        maxlength="40"
                        autocomplete="off"
                        autocapitalize="characters"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="name">Full name</label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="Nguyen Van A"
                        value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                        maxlength="100"
                        autocomplete="name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="teacher@example.com"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        maxlength="255"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="At least 8 characters"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm password</label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Enter the password again"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit">Create account</button>

            </form>

            <p class="switch-link">
                Already have an account? <a href="login.php">Sign in</a>
            </p>
        </section>
    </main>

<?php include 'includes/layout_end.php'; ?>
