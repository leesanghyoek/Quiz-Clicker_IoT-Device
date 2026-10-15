<?php
declare(strict_types=1);

ini_set('display_errors', '0');

function sendLobbyJson(int $httpStatus, array $payload): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function sendLobbyError(int $httpStatus, string $code, string $message): never
{
    sendLobbyJson($httpStatus, [
        'status' => 'ERROR',
        'code' => $code,
        'message' => $message
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    sendLobbyError(405, 'invalid_request', 'Only GET requests are accepted.');
}

require_once __DIR__ . '/../includes/security.php';
stemSessionStart();

if (!isset($_SESSION['teacher_id'])) {
    sendLobbyError(401, 'authentication_required', 'Teacher authentication is required.');
}

$teacherId = (int) $_SESSION['teacher_id'];
$sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);

if ($teacherId <= 0 || $sessionId === false || $sessionId <= 0) {
    sendLobbyError(400, 'invalid_request', 'A valid session_id is required.');
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../config/db.php';

    $stmt = $conn->prepare("
        SELECT
            ls.session_id,
            ls.status,
            ls.class_id
        FROM live_sessions ls
        JOIN quizzes q
            ON q.quiz_id = ls.quiz_id
        JOIN classes c
            ON c.class_id = ls.class_id
        WHERE ls.session_id = ?
          AND q.teacher_id = ?
          AND c.teacher_id = ?
    ");
    $stmt->bind_param('iii', $sessionId, $teacherId, $teacherId);
    $stmt->execute();
    $sessionResult = $stmt->get_result();

    if ($sessionResult->num_rows !== 1) {
        sendLobbyError(404, 'session_not_found', 'Live session not found or access denied.');
    }

    $liveSession = $sessionResult->fetch_assoc();
    $classId = (int) $liveSession['class_id'];

    $stmt = $conn->prepare("
        SELECT id, student_id, name
        FROM students
        WHERE class_id = ?
        ORDER BY name, student_id
    ");
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $studentsResult = $stmt->get_result();
    $students = [];

    while ($student = $studentsResult->fetch_assoc()) {
        $studentPk = (int) $student['id'];
        $students[$studentPk] = [
            'student_pk' => $studentPk,
            'student_id' => (string) $student['student_id'],
            'name' => (string) $student['name']
        ];
    }

    $stmt = $conn->prepare("
        SELECT student_pk
        FROM device_sessions
        WHERE session_id = ?
    ");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $joinedResult = $stmt->get_result();
    $joinedStudentIds = [];

    while ($deviceSession = $joinedResult->fetch_assoc()) {
        $joinedStudentIds[(int) $deviceSession['student_pk']] = true;
    }

    $joinedStudents = [];
    $waitingStudents = [];

    foreach ($students as $studentPk => $student) {
        if (isset($joinedStudentIds[$studentPk])) {
            $joinedStudents[] = $student;
        } else {
            $waitingStudents[] = $student;
        }
    }

    $total = count($students);
    $joined = count($joinedStudents);
    $waiting = count($waitingStudents);

    sendLobbyJson(200, [
        'session_id' => (int) $liveSession['session_id'],
        'status' => (string) $liveSession['status'],
        'total' => $total,
        'joined' => $joined,
        'waiting' => $waiting,
        'all_ready' => $joined === $total,
        'joined_students' => $joinedStudents,
        'waiting_students' => $waitingStudents
    ]);
} catch (Throwable $exception) {
    error_log(
        'Lobby status failed for teacher '
        . $teacherId
        . ': '
        . $exception->getMessage()
    );
    sendLobbyError(500, 'server_error', 'Unable to load lobby status.');
}
