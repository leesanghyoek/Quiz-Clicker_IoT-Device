<?php

ini_set('display_errors', '0');

// Answer devices are in the classroom; nothing from the Internet (tunnel) may use this.
require_once __DIR__ . '/../includes/security.php';
stemBlockInternet();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once '../config/db.php';


// ========================================
// 1. Chỉ chấp nhận GET
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    http_response_code(405);

    echo json_encode([
        'status' => 'ERROR',
        'reason' => 'method_not_allowed'
    ]);

    exit;
}


// ========================================
// 2. Lấy tham số từ URL
// Ví dụ:
// ?device_id=07&session_id=15
// ========================================

$device_code = $_GET['device_id'] ?? '';
$session_id  = isset($_GET['session_id'])
    ? (int) $_GET['session_id']
    : 0;


// ========================================
// 3. Validate
// ========================================

if (
    !preg_match('/^\d{2}$/', $device_code) ||
    $session_id <= 0
) {

    http_response_code(400);

    echo json_encode([
        'status' => 'ERROR',
        'reason' => 'invalid_request'
    ]);

    exit;
}


// ========================================
// 4. Tìm device_id nội bộ
// ========================================

$stmt = $conn->prepare("
    SELECT device_id, status
    FROM devices
    WHERE device_code = ?
    LIMIT 1
");

$stmt->bind_param("s", $device_code);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {

    echo json_encode([
        'status' => 'ERROR',
        'reason' => 'device_not_registered'
    ]);

    exit;
}

$device = $result->fetch_assoc();

if ($device['status'] !== 'ACTIVE') {

    echo json_encode([
        'status' => 'ERROR',
        'reason' => 'device_inactive'
    ]);

    exit;
}

$device_id = (int) $device['device_id'];


// ========================================
// 5. Kiểm tra device đã join session chưa
// ========================================

$stmt = $conn->prepare("
    SELECT device_session_id
    FROM device_sessions
    WHERE session_id = ?
      AND device_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $session_id,
    $device_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {

    echo json_encode([
        'status' => 'ERROR',
        'reason' => 'device_not_joined'
    ]);

    exit;
}


// ========================================
// 6. Lấy trạng thái session
// ========================================

$stmt = $conn->prepare("
    SELECT
        session_id,
        quiz_id,
        status,
        current_question_id
    FROM live_sessions
    WHERE session_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $session_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {

    echo json_encode([
        'status' => 'ERROR',
        'reason' => 'session_not_found'
    ]);

    exit;
}

$session = $result->fetch_assoc();


// ========================================
// 7. WAITING
// ========================================

if ($session['status'] === 'WAITING') {

    echo json_encode([
        'status' => 'WAITING',
        'session_id' => $session_id
    ]);

    exit;
}


// ========================================
// 8. ENDED
// ========================================

if ($session['status'] === 'ENDED') {

    echo json_encode([
        'status' => 'ENDED',
        'session_id' => $session_id
    ]);

    exit;
}


// ========================================
// 9. ACTIVE
// ========================================

if ($session['status'] === 'ACTIVE') {

    $question_id = (int) $session['current_question_id'];
    $quiz_id = (int) $session['quiz_id'];

    if ($question_id <= 0) {

        echo json_encode([
            'status' => 'ERROR',
            'reason' => 'no_current_question'
        ]);

        exit;
    }


    // Lấy question_order
    $stmt = $conn->prepare("
        SELECT question_order
        FROM questions
        WHERE question_id = ?
          AND quiz_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("ii", $question_id, $quiz_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {

        echo json_encode([
            'status' => 'ERROR',
            'reason' => 'question_not_found'
        ]);

        exit;
    }

    $question = $result->fetch_assoc();


    echo json_encode([
        'status' => 'ACTIVE',
        'session_id' => $session_id,
        'question_id' => $question_id,
        'question_order' => (int) $question['question_order']
    ]);

    exit;
}


// ========================================
// 10. Status không hợp lệ
// ========================================

echo json_encode([
    'status' => 'ERROR',
    'reason' => 'invalid_session_status'
]);
