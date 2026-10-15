<?php
declare(strict_types=1);

final class JoinCodeExhaustedException extends RuntimeException
{
}

final class JoinCodeLockException extends RuntimeException
{
}

function acquireJoinCodeAllocationLock(mysqli $conn, int $timeoutSeconds = 5): void
{
    $lock_name = 'iot_live_session_join_code_allocator';
    $stmt = $conn->prepare('SELECT GET_LOCK(?, ?) AS lock_acquired');
    $stmt->bind_param('si', $lock_name, $timeoutSeconds);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ((int) ($result['lock_acquired'] ?? 0) !== 1) {
        throw new JoinCodeLockException('Unable to acquire the join-code allocation lock.');
    }
}

function releaseJoinCodeAllocationLock(mysqli $conn): void
{
    $lock_name = 'iot_live_session_join_code_allocator';
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $lock_name);
    $stmt->execute();
}

function allocateLiveSessionJoinCode(mysqli $conn): string
{
    $result = $conn->query("
        SELECT join_code
        FROM live_sessions
        WHERE status IN ('WAITING', 'ACTIVE')
          AND join_code IS NOT NULL
        FOR UPDATE
    ");
    $occupied = [];

    while ($row = $result->fetch_assoc()) {
        $code = (string) $row['join_code'];

        if (preg_match('/^\d{2}$/', $code)) {
            $occupied[$code] = true;
        }
    }

    if (count($occupied) >= 100) {
        throw new JoinCodeExhaustedException(
            'All two-digit join codes are currently in use.'
        );
    }

    $start = random_int(0, 99);

    for ($offset = 0; $offset < 100; $offset++) {
        $candidate = sprintf('%02d', ($start + $offset) % 100);

        if (!isset($occupied[$candidate])) {
            return $candidate;
        }
    }

    throw new JoinCodeExhaustedException(
        'All two-digit join codes are currently in use.'
    );
}
