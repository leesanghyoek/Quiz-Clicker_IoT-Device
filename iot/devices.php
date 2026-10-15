<?php
/*
 * Devices: the 2-digit codes answer devices (ESP32 keypads and phones on
 * phone.php) send when they join a quiz. api/join.php and api/answer.php
 * accept only codes listed here with status ACTIVE.
 *
 * Codes are shared by every teacher on this server. Adding a code inserts a
 * row in devices; deleting it removes the row. Past results survive: the
 * student's device_sessions rows stay and only lose the device link
 * (ON DELETE SET NULL). A code in a session that has not ended cannot be
 * deleted, or that student could no longer answer.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';

if (empty($_SESSION['devices_csrf_token'])) {
    $_SESSION['devices_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['devices_csrf_token'];

function devicesRedirect(array $query): void
{
    header('Location: devices.php?' . http_build_query($query));
    exit;
}

function deviceCode(string $value): ?string
{
    $value = trim($value);

    if (!preg_match('/^\d{1,2}$/', $value)) {
        return null;
    }

    return str_pad($value, 2, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------
 * Actions
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? '');

    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        devicesRedirect(['error' => 'token']);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        if ($action === 'add') {
            $from = deviceCode((string) ($_POST['from'] ?? ''));
            $to_raw = trim((string) ($_POST['to'] ?? ''));
            $to = $to_raw === '' ? $from : deviceCode($to_raw);

            if ($from === null || $to === null || (int) $from < 1 || (int) $to < (int) $from) {
                devicesRedirect(['error' => 'range']);
            }

            $insert = $conn->prepare("INSERT IGNORE INTO devices (device_code, status) VALUES (?, 'ACTIVE')");
            $added = 0;

            for ($n = (int) $from; $n <= (int) $to; $n++) {
                $code = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
                $insert->bind_param('s', $code);
                $insert->execute();
                $added += $insert->affected_rows;
            }

            $count = (int) $to - (int) $from + 1;
            devicesRedirect(['added' => $added, 'existing' => $count - $added]);
        }

        if ($action === 'delete') {
            $ids = is_array($_POST['device_ids'] ?? null)
                ? $_POST['device_ids']
                : [$_POST['device_id'] ?? 0];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));

            if (empty($ids)) {
                devicesRedirect(['error' => 'none']);
            }

            $list = implode(',', $ids); // every value is an int
            $conn->begin_transaction();

            $busy = array_column($conn->query("
                SELECT DISTINCT d.device_code
                FROM devices d
                JOIN device_sessions ds ON ds.device_id = d.device_id
                JOIN live_sessions ls ON ls.session_id = ds.session_id
                WHERE d.device_id IN ($list)
                  AND ls.status IN ('WAITING', 'ACTIVE')
                FOR UPDATE
            ")->fetch_all(MYSQLI_NUM), 0);

            $conn->query("
                DELETE d FROM devices d
                WHERE d.device_id IN ($list)
                  AND NOT EXISTS (
                      SELECT 1
                      FROM device_sessions ds
                      JOIN live_sessions ls ON ls.session_id = ds.session_id
                      WHERE ds.device_id = d.device_id
                        AND ls.status IN ('WAITING', 'ACTIVE')
                  )
            ");
            $deleted = $conn->affected_rows;
            $conn->commit();

            devicesRedirect(array_filter([
                'deleted' => $deleted,
                'busy' => implode(',', $busy)
            ], static fn ($v) => $v !== '' && $v !== 0));
        }

        $device_id = (int) ($_POST['device_id'] ?? 0);
        $stmt = $conn->prepare('SELECT device_id, device_code FROM devices WHERE device_id = ?');
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $device = $stmt->get_result()->fetch_assoc();

        if ($device === null) {
            devicesRedirect(['error' => 'missing']);
        }

        if ($action === 'lock' || $action === 'unlock') {
            $status = $action === 'lock' ? 'INACTIVE' : 'ACTIVE';
            $stmt = $conn->prepare('UPDATE devices SET status = ? WHERE device_id = ?');
            $stmt->bind_param('si', $status, $device_id);
            $stmt->execute();
            devicesRedirect([$action === 'lock' ? 'locked' : 'unlocked' => $device['device_code']]);
        }

        devicesRedirect(['error' => 'action']);
    } catch (mysqli_sql_exception $exception) {
        error_log('Devices action ' . $action . ' failed: ' . $exception->getMessage());
        devicesRedirect(['error' => 'server']);
    }
}

/* ------------------------------------------------------------------
 * List
 * ---------------------------------------------------------------- */
$devices = $conn->query("
    SELECT
        d.device_id,
        d.device_code,
        d.status,
        (SELECT COUNT(*) FROM device_sessions ds WHERE ds.device_id = d.device_id) AS session_count,
        (SELECT MAX(ds.joined_at) FROM device_sessions ds WHERE ds.device_id = d.device_id) AS last_used,
        (
            SELECT CONCAT(c.name, ' · STT ', s.student_id, ' ', s.name)
            FROM device_sessions ds
            JOIN live_sessions ls ON ls.session_id = ds.session_id
            JOIN classes c ON c.class_id = ls.class_id
            JOIN students s ON s.id = ds.student_pk
            WHERE ds.device_id = d.device_id
              AND ls.status IN ('WAITING', 'ACTIVE')
            ORDER BY ds.joined_at DESC
            LIMIT 1
        ) AS in_session
    FROM devices d
    ORDER BY d.device_code
")->fetch_all(MYSQLI_ASSOC);

$used_codes = array_map(static fn ($d) => (int) $d['device_code'], $devices);
$next_free = 1;

while (in_array($next_free, $used_codes, true) && $next_free < 99) {
    $next_free++;
}

/*
 * Address phones should open. When the teacher browses via localhost the
 * request host is useless to a phone, so use the address of the network
 * interface that reaches the LAN (a UDP "connect" sends no packet).
 */
function devicesLanHost(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    if (!preg_match('/^(localhost|127\.|\[?::1)/i', $host)) {
        return $host;
    }

    $socket = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);

    if ($socket) {
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $ip = preg_replace('/:\d+$/', '', $name);

        if (filter_var($ip, FILTER_VALIDATE_IP) && !str_starts_with($ip, '127.')) {
            return $ip;
        }
    }

    return $host;
}

$base_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$phone_url = 'http://' . devicesLanHost() . $base_path . '/phone.php';

$messages = [];
$errors = [
    'token' => 'Your form session expired. Please try again.',
    'range' => 'Enter a code from 01 to 99; for a range, the second code must not be smaller than the first.',
    'missing' => 'That device no longer exists.',
    'none' => 'Tick at least one device to delete.',
    'action' => 'Unknown action.',
    'server' => 'The change could not be saved. Please try again.'
];

if (isset($_GET['added'])) {
    $added = (int) $_GET['added'];
    $existing = (int) ($_GET['existing'] ?? 0);
    $messages[] = $added === 1 ? '1 device added.' : $added . ' devices added.';

    if ($existing > 0) {
        $messages[count($messages) - 1] .= ' ' . $existing . ($existing === 1 ? ' code was' : ' codes were') . ' already registered.';
    }
}

if (isset($_GET['deleted'])) {
    $deleted = (int) $_GET['deleted'];
    $messages[] = ($deleted === 1 ? '1 device' : $deleted . ' devices') . ' deleted. Past results are kept.';
}

if (isset($_GET['busy']) && $_GET['busy'] !== '') {
    $error_busy = 'Not deleted, still in a quiz that has not ended: ' . str_replace(',', ', ', (string) $_GET['busy'])
        . '. End or cancel that quiz first.';
}

foreach (['locked' => 'locked; it can no longer join or answer.', 'unlocked' => 'unlocked.'] as $key => $text) {
    if (isset($_GET[$key])) {
        $messages[] = 'Device ' . $_GET[$key] . ' ' . $text;
    }
}

$error = $errors[$_GET['error'] ?? ''] ?? ($error_busy ?? '');

$page_title = 'Devices';
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page devices-page">
    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1>Devices</h1>
            <p>
                Each answer device, an ESP32 keypad or a phone, needs its own 2-digit code.
                Only codes listed here can join a quiz.
            </p>
        </div>
    </header>

    <?php foreach ($messages as $message): ?>
        <div class="success-message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <?php if ($error !== ''): ?>
        <div class="error-message"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="devices-setup">
        <form method="POST" action="devices.php" class="devices-add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add">
            <h2>Add devices</h2>
            <p>One code, or a range such as 02 to 30 for a whole class set. Codes already registered are skipped.</p>
            <div class="devices-add-fields">
                <div class="form-group">
                    <label for="device-from">Code</label>
                    <input type="text" id="device-from" name="from" inputmode="numeric" pattern="\d{1,2}" maxlength="2"
                        value="<?= str_pad((string) $next_free, 2, '0', STR_PAD_LEFT) ?>" required>
                </div>
                <div class="form-group">
                    <label for="device-to">to (optional)</label>
                    <input type="text" id="device-to" name="to" inputmode="numeric" pattern="\d{1,2}" maxlength="2" placeholder="—">
                </div>
                <button type="submit" class="save-button"><i class="ti ti-plus"></i>Add</button>
            </div>
        </form>

        <div class="devices-phone">
            <h2>Use a phone</h2>
            <p>On the same Wi-Fi as this server, open:</p>
            <p class="devices-url"><a href="<?= htmlspecialchars($phone_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($phone_url, ENT_QUOTES, 'UTF-8') ?></a></p>
            <p>Or press <strong>QR</strong> next to a device: scanning it opens the page with that code filled in.</p>
        </div>
    </section>

    <section class="devices-list">
        <div class="section-header">
            <div>
                <h2>Registered codes</h2>
                <p><?= count($devices) ?> <?= count($devices) === 1 ? 'device' : 'devices' ?></p>
            </div>
            <?php if (!empty($devices)): ?>
                <form method="POST" action="devices.php" id="bulk-delete"
                    onsubmit="return confirm('Delete the ticked devices? Their codes stop working at once; past results are kept.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="danger-button" id="bulk-delete-button" disabled>
                        <i class="ti ti-trash"></i><span>Delete selected</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($devices)): ?>
            <div class="empty-state">
                <h3>No devices yet</h3>
                <p>Add a code above, then set the same code on the ESP32 (DEVICE_ID) or type it on the phone page.</p>
            </div>
        <?php else: ?>
            <div class="table-card">
                <div class="table-responsive">
                    <table class="devices-table">
                        <thead>
                            <tr>
                                <th class="devices-pick">
                                    <input type="checkbox" id="pick-all" aria-label="Tick every device that is not in a quiz">
                                </th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Now</th>
                                <th>Used</th>
                                <th class="table-actions-heading">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $device): ?>
                                <?php
                                $code = $device['device_code'];
                                $active = $device['status'] === 'ACTIVE';
                                $sessions = (int) $device['session_count'];
                                ?>
                                <?php $busy = $device['in_session'] !== null; ?>
                                <tr class="<?= $active ? '' : 'is-locked' ?>">
                                    <td class="devices-pick">
                                        <input type="checkbox" name="device_ids[]" value="<?= (int) $device['device_id'] ?>" form="bulk-delete"
                                            <?= $busy ? 'disabled title="In a quiz that has not ended"' : '' ?>
                                            aria-label="Tick device <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                    </td>
                                    <td><span class="devices-code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <?php if ($active): ?>
                                            <span class="devices-status is-active"><i class="ti ti-circle-check" aria-hidden="true"></i>Active</span>
                                        <?php else: ?>
                                            <span class="devices-status is-locked"><i class="ti ti-lock" aria-hidden="true"></i>Locked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($device['in_session'] !== null): ?>
                                            <span class="devices-live"><i class="ti ti-broadcast" aria-hidden="true"></i><?= htmlspecialchars($device['in_session'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="devices-muted">Free</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($sessions > 0): ?>
                                            <?= $sessions ?> <?= $sessions === 1 ? 'session' : 'sessions' ?>
                                            <span class="devices-muted">· last <?= htmlspecialchars(date('d/m/Y H:i', strtotime($device['last_used'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="devices-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><div class="devices-actions">
                                        <button type="button" class="secondary-button" data-qr="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><i class="ti ti-qrcode"></i>QR</button>
                                        <form method="POST" action="devices.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="device_id" value="<?= (int) $device['device_id'] ?>">
                                            <?php if ($active): ?>
                                                <button type="submit" name="action" value="lock" class="secondary-button"><i class="ti ti-lock"></i>Lock</button>
                                            <?php else: ?>
                                                <button type="submit" name="action" value="unlock" class="secondary-button"><i class="ti ti-lock-open"></i>Unlock</button>
                                            <?php endif; ?>
                                            <button type="submit" name="action" value="delete" class="secondary-button devices-delete"
                                                <?= $busy ? 'disabled title="In a quiz that has not ended; end it first"' : '' ?>
                                                onclick="return confirm('Delete device <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>? The code stops working at once; past results are kept.');"><i class="ti ti-trash"></i>Delete</button>
                                        </form>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <dialog class="confirmation-dialog devices-qr-dialog" id="qr-dialog" aria-labelledby="qr-title">
        <h2 id="qr-title">Device <span id="qr-code"></span></h2>
        <p>Scan with the phone's camera.</p>
        <div id="qr-image" class="devices-qr"></div>
        <p class="devices-url" id="qr-url"></p>
        <div class="form-actions">
            <button type="button" class="secondary-button" onclick="this.closest('dialog').close()">Close</button>
        </div>
    </dialog>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    var picks = Array.prototype.slice.call(document.querySelectorAll('input[name="device_ids[]"]:not(:disabled)'));
    var pickAll = document.getElementById('pick-all');
    var bulkButton = document.getElementById('bulk-delete-button');

    function updateBulk() {
        var n = picks.filter(function (p) { return p.checked; }).length;

        bulkButton.disabled = n === 0;
        bulkButton.querySelector('span').textContent = n === 0 ? 'Delete selected' : 'Delete ' + n + (n === 1 ? ' device' : ' devices');
        pickAll.checked = n > 0 && n === picks.length;
        pickAll.indeterminate = n > 0 && n < picks.length;
    }

    if (pickAll && bulkButton) {
        pickAll.addEventListener('change', function () {
            picks.forEach(function (p) { p.checked = pickAll.checked; });
            updateBulk();
        });
        picks.forEach(function (p) { p.addEventListener('change', updateBulk); });
        updateBulk();
    }

    var phoneUrl = <?= json_encode($phone_url, JSON_UNESCAPED_SLASHES) ?>;
    var dialog = document.getElementById('qr-dialog');
    var image = document.getElementById('qr-image');

    document.querySelectorAll('[data-qr]').forEach(function (button) {
        button.addEventListener('click', function () {
            var url = phoneUrl + '?device=' + encodeURIComponent(button.dataset.qr);

            document.getElementById('qr-code').textContent = button.dataset.qr;
            document.getElementById('qr-url').textContent = url;
            image.innerHTML = '';

            if (window.QRCode) {
                new QRCode(image, { text: url, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
            } else {
                image.textContent = 'QR code could not load; type the address below instead.';
            }

            dialog.showModal();
        });
    });
})();
</script>

<?php include 'includes/layout_end.php'; ?>
