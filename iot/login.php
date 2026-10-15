<?php
require_once __DIR__ . '/includes/security.php';
stemSessionStart();

require_once 'config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = stemClientIp();
    $pair = $ip . '|' . strtolower($email);
    // 5 wrong passwords for one email from one address, or 30 from one address, lock sign-in for 15 minutes.
    $wait = max(
        stemThrottleWait('login', $pair, 5, 900),
        stemThrottleWait('login-ip', $ip, 30, 900)
    );

    $stmt = $conn->prepare(
        "SELECT teacher_id, name, email, password_hash
         FROM teachers
         WHERE email = ?"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($wait > 0) {
        $error = 'Too many wrong attempts. Try again in ' . stemWaitText($wait) . '.';
    } elseif ($result->num_rows === 1) {

        $teacher = $result->fetch_assoc();

        if (password_verify($password, $teacher['password_hash'])) {

            stemThrottleClear('login', $pair);
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = $teacher['teacher_id'];
            $_SESSION['teacher_name'] = $teacher['name'];
            $_SESSION['teacher_email'] = $teacher['email'];

            header("Location: dashboard.php");
            exit;

        } else {
            stemThrottleHit('login', $pair, 900);
            stemThrottleHit('login-ip', $ip, 900);
            $error = "Email hoặc mật khẩu không đúng.";
        }

    } else {
        stemThrottleHit('login', $pair, 900);
        stemThrottleHit('login-ip', $ip, 900);
        $error = "Email hoặc mật khẩu không đúng.";
    }
}
?>

<?php
$page_title = 'Sign in | STEM Quiz';
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
            <h1>Welcome back</h1>
            <p>Sign in to your classroom.</p>
        </header>

        <div class="lake-waterline" data-waterline-anchor></div>

        <section class="login-container lake-card" aria-label="Sign in">
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">

                <div class="form-group">
                    <label for="email">Email</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="teacher@example.com"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
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
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit">Sign in</button>

            </form>

            <p class="switch-link">
                New here? <a href="register.php">Create a teacher account</a>
            </p>
        </section>
    </main>

<?php include 'includes/layout_end.php'; ?>
