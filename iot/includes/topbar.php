<?php
/*
 * The open sky above the horizon. The page header is lifted into this space
 * by world.css; this file only draws the quiet line along the top.
 */
?>
<div class="sky-band" id="sky-band">
    <div class="sky-bar">
        <?php
        $wordmark_href = 'dashboard.php';
        $wordmark_class = '';
        include __DIR__ . '/wordmark.php';
        ?>

        <?php
        // Quizzes left open (the teacher may have closed their page): one link back.
        $sky_open_sessions = [];

        if (isset($conn) && $conn instanceof mysqli && isset($_SESSION['teacher_id'])) {
            require_once __DIR__ . '/live_sessions.php';

            try {
                $sky_open_sessions = loadOpenSessions($conn, (int) $_SESSION['teacher_id']);
            } catch (Throwable $exception) {
                error_log('Open sessions lookup failed: ' . $exception->getMessage());
            }
        }

        $sky_open_count = count($sky_open_sessions);
        $sky_running = array_values(array_filter($sky_open_sessions, static fn ($s) => $s['status'] === 'ACTIVE'));
        ?>

        <div class="sky-bar-end">
            <?php if ($sky_open_count > 0): ?>
                <a
                    class="sky-live <?= $sky_running ? 'is-running' : '' ?>"
                    href="<?= $sky_open_count === 1 ? htmlspecialchars(openSessionUrl($sky_open_sessions[0]), ENT_QUOTES, 'UTF-8') : 'dashboard.php#live-now' ?>"
                    title="Quizzes that have not ended"
                >
                    <span class="sky-live-dot" aria-hidden="true"></span>
                    <span class="sky-live-long">
                        <?php if ($sky_running): ?>
                            <?= count($sky_running) === 1 ? 'Quiz running' : count($sky_running) . ' quizzes running' ?>
                        <?php else: ?>
                            <?= $sky_open_count === 1 ? '1 quiz waiting' : $sky_open_count . ' quizzes waiting' ?>
                        <?php endif; ?>
                    </span>
                    <span class="sky-live-short" aria-hidden="true"><?= $sky_running ? 'Live' : $sky_open_count ?></span>
                </a>
            <?php endif; ?>
            <a class="sky-teacher" href="account.php" title="Account settings">
                <?= htmlspecialchars(
                    (string) ($_SESSION['teacher_name'] ?? 'Teacher'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </a>
            <a class="sky-signout" href="logout.php">Sign out</a>
        </div>
    </div>
</div>
