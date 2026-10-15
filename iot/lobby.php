<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$session_id = (int) ($_GET['session_id'] ?? 0);
$error = '';
$live_session = null;
$students = [];
$joined_students = [];
$waiting_students = [];
$total_students = 0;
$joined_count = 0;
$waiting_count = 0;
$device_sessions_available = false;

if (empty($_SESSION['start_quiz_csrf_token'])) {
    $_SESSION['start_quiz_csrf_token'] = bin2hex(random_bytes(32));
}

$start_quiz_csrf_token = $_SESSION['start_quiz_csrf_token'];

if ($session_id <= 0) {
    $error = 'Invalid live session.';
} else {
    try {
        $stmt = $conn->prepare("
            SELECT
                ls.session_id,
                ls.join_code,
                ls.status,
                q.quiz_id,
                q.title AS quiz_title,
                c.class_id,
                c.name AS class_name
            FROM live_sessions ls
            JOIN quizzes q
                ON q.quiz_id = ls.quiz_id
            JOIN classes c
                ON c.class_id = ls.class_id
            WHERE ls.session_id = ?
              AND q.teacher_id = ?
              AND c.teacher_id = ?
        ");
        $stmt->bind_param('iii', $session_id, $teacher_id, $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows !== 1) {
            http_response_code(403);
            $error = 'Live session not found or access denied.';
        } else {
            $live_session = $result->fetch_assoc();
            $class_id = (int) $live_session['class_id'];

            $table_result = $conn->query("
                SELECT COUNT(*) AS total
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = 'device_sessions'
            ");
            $device_sessions_available = (int) $table_result
                ->fetch_assoc()['total'] === 1;

            if ($device_sessions_available) {
                $student_stmt = $conn->prepare("
                    SELECT
                        s.id,
                        s.student_id,
                        s.name,
                        CASE
                            WHEN ds.device_session_id IS NULL THEN 0
                            ELSE 1
                        END AS is_joined
                    FROM students s
                    LEFT JOIN device_sessions ds
                        ON ds.student_pk = s.id
                       AND ds.session_id = ?
                    WHERE s.class_id = ?
                    ORDER BY s.name, s.student_id
                ");
                $student_stmt->bind_param(
                    'ii',
                    $session_id,
                    $class_id
                );
            } else {
                $student_stmt = $conn->prepare("
                    SELECT
                        id,
                        student_id,
                        name,
                        0 AS is_joined
                    FROM students
                    WHERE class_id = ?
                    ORDER BY name, student_id
                ");
                $student_stmt->bind_param(
                    'i',
                    $class_id
                );
            }

            $student_stmt->execute();
            $student_result = $student_stmt->get_result();

            while ($student = $student_result->fetch_assoc()) {
                $student['is_joined'] = (int) $student['is_joined'] === 1;
                $students[] = $student;

                if ($student['is_joined']) {
                    $joined_students[] = $student;
                } else {
                    $waiting_students[] = $student;
                }
            }

            $total_students = count($students);
            $joined_count = count($joined_students);
            $waiting_count = count($waiting_students);
        }
    } catch (Throwable $exception) {
        error_log(
            'Lobby load failed for teacher '
            . $teacher_id
            . ': '
            . $exception->getMessage()
        );
        $error = 'Unable to load the live session.';
    }
}

if ($error === '') {
    $start_error = $_GET['error'] ?? '';

    if ($start_error === 'status') {
        $error = 'Only a waiting session can be started.';
    } elseif ($start_error === 'access') {
        $error = 'Live session not found or access denied.';
    } elseif ($start_error === 'csrf') {
        $error = 'Your form session expired. Please try again.';
    } elseif ($start_error === 'no_questions') {
        $error = 'This quiz has no questions to start.';
    } elseif ($start_error === 'start') {
        $error = 'Unable to start the quiz. Please try again.';
    }
}
?>

<?php
$page_title = 'Quiz Lobby';
$page_styles = ['assets/css/quiz.css?v=20261006-12'];
$layout_body_class = 'lobby-page';
$layout_show_navigation = false;
include 'includes/layout_start.php';
?>

<main class="lobby-screen">
    <section class="lobby-card">
        <header class="lobby-presentation-header">
            <div class="lobby-brandline">
                <?php
                $wordmark_href = '';
                $wordmark_class = '';
                include 'includes/wordmark.php';
                ?>
            </div>
            <?php if ($live_session !== null): ?>
                <span class="lobby-status" id="session-status">
                    <?= htmlspecialchars($live_session['status'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endif; ?>
        </header>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($live_session !== null): ?>
            <div class="lobby-sky" data-sky-content>
                <div class="lobby-session-summary">
                    <div class="lobby-quiz">
                        <span>Quiz</span>
                        <strong>
                            <?= htmlspecialchars(
                                $live_session['quiz_title'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>
                    </div>
                    <div class="lobby-class">
                        <span>Class</span>
                        <strong>
                            <?= htmlspecialchars(
                                $live_session['class_name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>
                    </div>
                    <div class="lobby-code">
                        <span>Join code</span>
                        <strong>
                            <?= htmlspecialchars(
                                (string) ($live_session['join_code'] ?? '--'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>
                    </div>
                </div>

                <div class="lobby-joined-count">
                    <strong id="joined-count"><?= $joined_count ?></strong>
                    <span>of</span>
                    <strong id="total-count"><?= $total_students ?></strong>
                    <span>students have joined</span>
                </div>

                <div
                    class="success-message lobby-all-ready"
                    id="all-ready-message"
                    <?= $joined_count === $total_students ? '' : 'hidden' ?>
                >
                    <i class="ti ti-circle-check-filled" aria-hidden="true"></i>
                    Everyone is here
                </div>

                <p
                    class="lobby-polling-status"
                    id="lobby-polling-status"
                    role="status"
                    aria-live="polite"
                ></p>
            </div>

            <div class="lake-waterline" data-waterline-anchor></div>

            <div class="lobby-dock" data-lantern-floor>
                <div class="lobby-student-columns">
                    <section class="lobby-student-group" aria-labelledby="ready-heading">
                        <div class="lobby-group-heading">
                            <h2 id="ready-heading">Joined</h2>
                            <span id="ready-count"><?= $joined_count ?></span>
                        </div>

                        <ul
                            class="lobby-student-list ready-list"
                            id="ready-list"
                            <?= empty($joined_students) ? 'hidden' : '' ?>
                        >
                            <?php if (!empty($joined_students)): ?>
                                <?php foreach ($joined_students as $student): ?>
                                    <li>
                                        <span class="student-state-icon" aria-hidden="true">✓</span>
                                        <strong>
                                            <?= htmlspecialchars(
                                                $student['student_id'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </strong>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                        <p
                            class="lobby-empty-group"
                            id="ready-empty"
                            <?= !empty($joined_students) ? 'hidden' : '' ?>
                        >
                            No students have joined yet.
                        </p>
                    </section>

                    <section class="lobby-student-group" aria-labelledby="waiting-heading">
                        <div class="lobby-group-heading">
                            <h2 id="waiting-heading">Not joined yet</h2>
                            <span id="waiting-count"><?= $waiting_count ?></span>
                        </div>

                        <ul
                            class="lobby-student-list waiting-list"
                            id="waiting-list"
                            <?= empty($waiting_students) ? 'hidden' : '' ?>
                        >
                            <?php if (!empty($waiting_students)): ?>
                                <?php foreach ($waiting_students as $student): ?>
                                    <li>
                                        <span class="student-state-icon" aria-hidden="true">○</span>
                                        <strong>
                                            <?= htmlspecialchars(
                                                $student['student_id'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </strong>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                        <p
                            class="lobby-empty-group"
                            id="waiting-empty"
                            <?= !empty($waiting_students) ? 'hidden' : '' ?>
                        >
                            Every student is ready.
                        </p>
                    </section>
                </div>

                <div class="form-actions lobby-actions">
                    <a
                        href="quiz.php?id=<?= (int) $live_session['quiz_id'] ?>"
                        class="cancel-button"
                    >
                        Back to Quiz
                    </a>

                    <form method="POST" action="start_quiz.php">
                        <input
                            type="hidden"
                            name="session_id"
                            value="<?= (int) $live_session['session_id'] ?>"
                        >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $start_quiz_csrf_token,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >
                        <button type="submit" class="save-button lobby-start-button">
                            <i class="ti ti-player-play-filled" aria-hidden="true"></i>
                            Start Quiz
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="form-actions">
                <a href="quizzes.php" class="cancel-button">
                    Back to Quiz Library
                </a>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php if ($live_session !== null): ?>
<script>
const lobbySessionId = <?= (int) $live_session['session_id'] ?>;
const pollingStatus = document.getElementById('lobby-polling-status');
const sessionStatus = document.getElementById('session-status');
const joinedCount = document.getElementById('joined-count');
const totalCount = document.getElementById('total-count');
const readyCount = document.getElementById('ready-count');
const waitingCount = document.getElementById('waiting-count');
const readyList = document.getElementById('ready-list');
const waitingList = document.getElementById('waiting-list');
const readyEmpty = document.getElementById('ready-empty');
const waitingEmpty = document.getElementById('waiting-empty');
const allReadyMessage = document.getElementById('all-ready-message');
let pollingTimer = null;

function createStudentRow(student, isReady) {
    const row = document.createElement('li');
    const icon = document.createElement('span');
    const studentId = document.createElement('strong');

    icon.className = 'student-state-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = isReady ? '✓' : '○';
    studentId.textContent = student.student_id;
    row.append(icon, studentId);

    return row;
}

function replaceStudentList(list, emptyMessage, students, isReady) {
    const rows = students.map((student) => createStudentRow(student, isReady));
    list.replaceChildren(...rows);
    list.hidden = rows.length === 0;
    emptyMessage.hidden = rows.length !== 0;
}

function renderLobbyStatus(data) {
    sessionStatus.textContent = data.status;
    joinedCount.textContent = data.joined;
    totalCount.textContent = data.total;
    readyCount.textContent = data.joined;
    waitingCount.textContent = data.waiting;
    allReadyMessage.hidden = !data.all_ready;

    replaceStudentList(
        readyList,
        readyEmpty,
        data.joined_students,
        true
    );
    replaceStudentList(
        waitingList,
        waitingEmpty,
        data.waiting_students,
        false
    );
}

function scheduleLobbyPoll(delay) {
    window.clearTimeout(pollingTimer);
    pollingTimer = window.setTimeout(pollLobbyStatus, delay);
}

async function pollLobbyStatus() {
    try {
        const response = await fetch(
            `api/lobby_status.php?session_id=${encodeURIComponent(lobbySessionId)}`,
            {
                headers: {Accept: 'application/json'},
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );

        if (!response.ok) {
            throw new Error('Lobby status request failed');
        }

        const data = await response.json();
        renderLobbyStatus(data);

        if (data.status === 'WAITING') {
            pollingStatus.textContent = '';
            scheduleLobbyPoll(1500);
        } else {
            pollingStatus.textContent = 'Live updates paused because this session is no longer waiting.';
        }
    } catch (error) {
        pollingStatus.textContent = 'Live update temporarily unavailable. Retrying…';
        scheduleLobbyPoll(2500);
    }
}

scheduleLobbyPoll(1500);
</script>
<?php endif; ?>

<?php include 'includes/layout_end.php'; ?>
