<?php
/*
 * Protection for when the site is also reachable from the Internet through a
 * Cloudflare tunnel:
 *
 *  - Requests that came through the tunnel carry a Cf-Connecting-Ip header
 *    (Cloudflare always sets it, the classroom network never does), so the
 *    answer-device APIs, meant for the classroom only, can refuse them.
 *  - Sign-in and registration attempts are rate limited.
 *  - Registration needs an invite code (site_config.php, outside the web root).
 *  - Session cookies are HttpOnly, SameSite and, over https, Secure.
 *
 * State (attempt counters) lives in /var/www/iot-private/state, written by the web server.
 */

const STEM_PRIVATE_DIR = '/var/www/iot-private';
const STEM_STATE_DIR = STEM_PRIVATE_DIR . '/state';

/** True when the request arrived through the Cloudflare tunnel (from the Internet). */
function stemViaTunnel(): bool
{
    return !empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_CF_RAY']);
}

/** The visitor's address: the real one behind the tunnel, otherwise the direct peer. */
function stemClientIp(): string
{
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $forwarded = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');

    // Only the local tunnel program is trusted to say who the visitor is.
    if (in_array($peer, ['127.0.0.1', '::1'], true) && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
        return $forwarded;
    }

    return $peer;
}

/** For APIs that only the classroom network may use (answer devices). */
function stemBlockInternet(): void
{
    if (!stemViaTunnel()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ERROR', 'reason' => 'classroom_network_only']);
    exit;
}

/** session_start() with safe cookie settings. */
function stemSessionStart(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (stemViaTunnel() && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ------------------------------------------------------------------
 * Rate limiting: a small JSON file per kind of attempt
 * ---------------------------------------------------------------- */

/** Run $change(array $hits): array under an exclusive lock on the bucket's file. */
function stemThrottleEdit(string $bucket, callable $change): mixed
{
    $handle = @fopen(STEM_STATE_DIR . '/throttle-' . preg_replace('/\W/', '', $bucket) . '.json', 'c+');

    if ($handle === false) {
        error_log('Rate limit state is not writable in ' . STEM_STATE_DIR);
        return null;
    }

    flock($handle, LOCK_EX);
    $hits = json_decode((string) stream_get_contents($handle), true);
    [$hits, $result] = $change(is_array($hits) ? $hits : []);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($hits));
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

/** Seconds the visitor must still wait, 0 when attempts are allowed. */
function stemThrottleWait(string $bucket, string $key, int $max, int $window): int
{
    $key = sha1($key);

    return (int) stemThrottleEdit($bucket, static function (array $hits) use ($key, $max, $window): array {
        $now = time();
        $recent = array_values(array_filter($hits[$key] ?? [], static fn ($at) => $at > $now - $window));
        $hits[$key] = $recent;
        $wait = count($recent) >= $max ? max(1, $recent[0] + $window - $now) : 0;

        return [$hits, $wait];
    });
}

/** Count one failed attempt. */
function stemThrottleHit(string $bucket, string $key, int $window): void
{
    $key = sha1($key);

    stemThrottleEdit($bucket, static function (array $hits) use ($key, $window): array {
        $now = time();

        foreach ($hits as $name => $times) {
            $hits[$name] = array_values(array_filter($times, static fn ($at) => $at > $now - $window));
            if (empty($hits[$name])) {
                unset($hits[$name]);
            }
        }
        $hits[$key][] = $now;

        return [$hits, null];
    });
}

function stemThrottleClear(string $bucket, string $key): void
{
    $key = sha1($key);

    stemThrottleEdit($bucket, static function (array $hits) use ($key): array {
        unset($hits[$key]);

        return [$hits, null];
    });
}

function stemWaitText(int $seconds): string
{
    return $seconds >= 90 ? ceil($seconds / 60) . ' minutes' : $seconds . ' seconds';
}

/* ------------------------------------------------------------------
 * Registration invite code
 * ---------------------------------------------------------------- */

/** null when registration is closed (no code set). */
function stemInviteCode(): ?string
{
    $file = STEM_PRIVATE_DIR . '/site_config.php';
    $config = is_readable($file) ? require $file : null;
    $code = is_array($config) ? trim((string) ($config['register_invite_code'] ?? '')) : '';

    return $code === '' ? null : $code;
}

function stemInviteCodeValid(string $given): bool
{
    $code = stemInviteCode();

    return $code !== null && hash_equals(strtoupper($code), strtoupper(trim($given)));
}
