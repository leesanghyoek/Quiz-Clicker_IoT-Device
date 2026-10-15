<?php
/*
 * Answer from a phone: the same steps as the ESP32 answer device
 * (api/join.php -> api/session_state.php -> api/answer.php), in a browser.
 * The phone needs its own 2-digit device code, registered on devices.php;
 * the QR code there opens this page with ?device=XX filled in.
 *
 * Bluetooth (Chrome on Android): when Wi-Fi is down, the same requests go over
 * Bluetooth to ble_gateway.py on the server, which calls the same APIs. That
 * needs https (phone_setup.php explains the one-time setup), and the page is
 * installable and cached by phone-sw.js so it still opens without Wi-Fi.
 */
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>STEM Quiz Phone</title>
<link rel="manifest" href="phone.webmanifest">
<meta name="theme-color" content="#2453c9">
<link rel="icon" href="assets/phone/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #eef3ff;
    --card: #ffffff;
    --ink: #13204a;
    --slate: #56607a;
    --rule: #d8dfef;
    --accent: #2453c9;
    --on-accent: #ffffff;
    --good: #1f8f63;
    --bad: #d6452c;
    --a: #d6452c;
    --b: #2453c9;
    --c: #c98a12;
    --d: #1f8f63;
}

@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --bg: #0f1530;
        --card: #18204a;
        --ink: #eef2ff;
        --slate: #aab3cf;
        --rule: #2c376a;
        --accent: #6f95ff;
        --on-accent: #0f1530;
    }
}

* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100dvh;
    background: var(--bg);
    color: var(--ink);
    font-family: Lexend, system-ui, sans-serif;
    display: flex;
    justify-content: center;
    padding: 16px;
}

main {
    width: 100%;
    max-width: 440px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

h1 { margin: 8px 0 0; font-size: 22px; }
p { margin: 0; color: var(--slate); }

.screen {
    background: var(--card);
    border: 1px solid var(--rule);
    border-radius: 20px;
    padding: 20px;
    text-align: center;
}

.display {
    font-size: 72px;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}

.message { margin-top: 6px; min-height: 1.4em; }
.message.is-good { color: var(--good); }
.message.is-bad { color: var(--bad); }

form { display: flex; flex-direction: column; gap: 12px; }

label { display: flex; flex-direction: column; gap: 6px; font-weight: 500; text-align: left; }

input {
    font: inherit;
    font-size: 22px;
    padding: 12px 14px;
    border: 1px solid var(--rule);
    border-radius: 12px;
    background: var(--card);
    color: var(--ink);
}

button {
    font: inherit;
    font-weight: 600;
    border: 0;
    border-radius: 14px;
    cursor: pointer;
}

.primary { padding: 14px; font-size: 18px; background: var(--accent); color: var(--on-accent); }
.link { background: none; color: var(--slate); text-decoration: underline; padding: 8px; }

.answers {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.answers button {
    aspect-ratio: 1.3;
    font-size: 48px;
    color: #fff;
}

.answers button[data-answer="A"] { background: var(--a); }
.answers button[data-answer="B"] { background: var(--b); }
.answers button[data-answer="C"] { background: var(--c); }
.answers button[data-answer="D"] { background: var(--d); }
.answers button:disabled { opacity: 0.35; cursor: default; }
.answers button.is-picked { opacity: 1; outline: 4px solid var(--ink); outline-offset: 3px; }

.transport {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: var(--card);
    border: 1px solid var(--rule);
    border-radius: 16px;
    padding: 10px 12px 10px 16px;
}

.transport p { font-size: 15px; }
.transport.is-ble { border-color: var(--accent); }
.transport.is-ble p { color: var(--ink); font-weight: 600; }

.secondary {
    flex: none;
    padding: 10px 14px;
    background: var(--bg);
    color: var(--accent);
    border: 1px solid var(--accent);
}

.hint { font-size: 14px; text-align: center; }
.hint a { color: var(--accent); }

[hidden] { display: none !important; }
</style>
</head>
<body>
<main>
    <h1>STEM Quiz</h1>

    <section class="screen" aria-live="polite">
        <div class="display" id="display">--</div>
        <p class="message" id="message">Nhập mã máy, STT và mã phòng để vào.</p>
    </section>

    <div class="transport" id="transport" hidden>
        <p id="transport-text">Đang gửi qua Wi-Fi</p>
        <button type="button" class="secondary" id="ble-toggle">Dùng Bluetooth</button>
    </div>
    <p class="hint" id="ble-hint" hidden></p>

    <form id="join-form">
        <label>Mã máy (2 số, giáo viên cấp ở trang Devices)
            <input id="device" inputmode="numeric" pattern="\d{2}" maxlength="2" required>
        </label>
        <label>STT của em (2 số)
            <input id="student" inputmode="numeric" pattern="\d{2}" maxlength="2" required>
        </label>
        <label>Mã phòng (2 số trên màn hình giáo viên)
            <input id="code" inputmode="numeric" pattern="\d{2}" maxlength="2" required>
        </label>
        <button type="submit" class="primary">Vào phòng</button>
    </form>

    <div class="answers" id="answers" hidden>
        <button type="button" data-answer="A">A</button>
        <button type="button" data-answer="B">B</button>
        <button type="button" data-answer="C">C</button>
        <button type="button" data-answer="D">D</button>
    </div>

    <button type="button" class="link" id="leave" hidden>Rời phòng (nhập lại)</button>
</main>

<script>
(function () {
    var API = 'api/';
    var STORE = 'stem-quiz-phone';
    var REASONS = {
        device_not_registered: 'Mã máy chưa được đăng ký.',
        device_inactive: 'Mã máy đang bị khoá.',
        invalid_join_code: 'Sai mã phòng.',
        session_not_waiting: 'Phòng đã bắt đầu, không vào thêm được.',
        student_not_in_class: 'STT này không có trong lớp.',
        device_already_joined: 'Mã máy này đã vào phòng rồi.',
        student_already_joined: 'STT này đã vào phòng bằng máy khác.',
        already_answered: 'Câu này em đã trả lời rồi.',
        stale_question: 'Giáo viên đã chuyển câu khác.',
        session_not_active: 'Phòng chưa bắt đầu hoặc đã kết thúc.',
        device_not_joined: 'Máy chưa vào phòng.',
        network: 'Không kết nối được máy chủ.',
        server_unreachable: 'Máy chủ không trả lời (Apache tắt?).'
    };

    // Bluetooth: phải giống hệt phone_server.py trong ble_gateway
    var BLE_SERVICE = '8dfce843-d5ee-4929-9805-9d684b50476e';
    var BLE_EXCHANGE = 'c872976b-bd47-4675-b021-10604327e5eb';
    var bleSupported = !!(navigator.bluetooth && navigator.bluetooth.requestDevice);
    var bleDevice = null;
    var bleChar = null;          // có giá trị = đang gửi qua Bluetooth
    var bleQueue = Promise.resolve();
    var bleLeaving = false;      // người dùng tự bấm ngắt

    var el = function (id) { return document.getElementById(id); };
    var display = el('display');
    var message = el('message');
    var form = el('join-form');
    var answers = el('answers');
    var leave = el('leave');
    var state = load();
    var questionId = null;
    var timer = null;

    function load() {
        try { return JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { return {}; }
    }

    function save() {
        try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) {}
    }

    function say(text, tone) {
        message.textContent = text;
        message.className = 'message' + (tone ? ' is-' + tone : '');
    }

    function reason(data) {
        return REASONS[data && data.reason] || ('Lỗi: ' + ((data && data.reason) || 'không kết nối được máy chủ'));
    }

    // Bluetooth chỉ chở được gói nhỏ: đổi yêu cầu API thành lệnh ngắn cho ble_gateway.
    function bleCommand(path, body) {
        if (path === 'join.php') {
            return ['J', body.device_id, body.student_id, body.join_code].join(',');
        }
        if (path === 'answer.php') {
            return ['A', body.device_id, body.session_id, body.question_id, body.answer].join(',');
        }
        var match = /^session_state\.php\?device_id=(\d+)&session_id=(\d+)$/.exec(path);
        return match ? ['S', match[1], match[2]].join(',') : null;
    }

    // Ghi lệnh rồi đọc câu trả lời. Mỗi lần chỉ một yêu cầu, vì Bluetooth không chạy hai việc cùng lúc.
    function bleRequest(path, body) {
        var command = bleCommand(path, body);
        var chr = bleChar;
        var run = function () {
            var data = new TextEncoder().encode(command);
            var write = chr.writeValueWithResponse ? chr.writeValueWithResponse(data) : chr.writeValue(data);
            return write
                .then(function () { return chr.readValue(); })
                .then(function (view) { return JSON.parse(new TextDecoder().decode(view)); });
        };
        var result = bleQueue.then(run, run);

        bleQueue = result.catch(function () {});
        return result.catch(function () { return { status: 'ERROR', reason: 'network' }; });
    }

    function request(path, body) {
        if (bleChar) {
            return bleRequest(path, body);
        }

        var options = body
            ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
            : { cache: 'no-store' };

        return fetch(API + path, options)
            .then(function (r) { return r.json(); })
            .catch(function () { return { status: 'ERROR', reason: 'network' }; });
    }

    function setAnswering(open, picked) {
        answers.querySelectorAll('button').forEach(function (b) {
            b.disabled = !open;
            b.classList.toggle('is-picked', b.dataset.answer === picked);
        });
    }

    function showJoined(joined) {
        form.hidden = joined;
        answers.hidden = !joined;
        leave.hidden = !joined;
    }

    function poll() {
        request('session_state.php?device_id=' + state.device + '&session_id=' + state.session).then(function (data) {
            if (data.status === 'WAITING') {
                display.textContent = '--';
                say('Đã vào phòng. Chờ giáo viên bắt đầu…');
                setAnswering(false);
            } else if (data.status === 'ACTIVE') {
                if (data.question_id !== questionId) {
                    questionId = data.question_id;
                    state.answered = null;
                    save();
                    display.textContent = String(data.question_order).padStart(2, '0');
                    say('Câu ' + data.question_order + ': chọn đáp án.');
                    setAnswering(true);
                }
            } else if (data.status === 'ENDED') {
                display.textContent = 'En';
                say('Bài đã kết thúc.', 'good');
                setAnswering(false);
                stop();
                return;
            } else if (data.reason !== 'network') {
                display.textContent = 'E';
                say(reason(data), 'bad');
            } else {
                say(bleSupported && !bleChar
                    ? 'Mất kết nối Wi-Fi. Bấm "Dùng Bluetooth" để tiếp tục.'
                    : 'Mất kết nối, đang thử lại…', 'bad');
            }
            timer = setTimeout(poll, 1000);
        });
    }

    function stop() {
        clearTimeout(timer);
        timer = null;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var body = { device_id: el('device').value, student_id: el('student').value, join_code: el('code').value };

        say('Đang vào phòng…');
        request('join.php', body).then(function (data) {
            if (data.status !== 'OK') {
                display.textContent = 'E';
                say(reason(data), 'bad');
                return;
            }
            state = { device: body.device_id, student: body.student_id, session: data.session_id };
            save();
            questionId = null;
            showJoined(true);
            poll();
        });
    });

    answers.addEventListener('click', function (event) {
        var button = event.target.closest('button');

        if (!button || button.disabled || questionId === null) {
            return;
        }
        setAnswering(false, button.dataset.answer);
        request('answer.php', {
            device_id: state.device,
            session_id: state.session,
            question_id: questionId,
            answer: button.dataset.answer
        }).then(function (data) {
            if (data.status === 'OK') {
                say('Đã gửi đáp án ' + button.dataset.answer + '.', 'good');
            } else {
                say(reason(data), 'bad');
                if (data.reason === 'network') { setAnswering(true); }
            }
        });
    });

    leave.addEventListener('click', function () {
        stop();
        state = { device: state.device };
        save();
        questionId = null;
        display.textContent = '--';
        say('Nhập mã máy, STT và mã phòng để vào.');
        showJoined(false);
    });

    // ---- Bluetooth ----
    function showTransport() {
        var box = el('transport');

        box.classList.toggle('is-ble', !!bleChar);
        el('transport-text').textContent = bleChar ? 'Đang gửi qua Bluetooth' : 'Đang gửi qua Wi-Fi';
        el('ble-toggle').textContent = bleChar ? 'Ngắt Bluetooth' : 'Dùng Bluetooth';
    }

    function openExchange(device) {
        return device.gatt.connect()
            .then(function (server) { return server.getPrimaryService(BLE_SERVICE); })
            .then(function (service) { return service.getCharacteristic(BLE_EXCHANGE); })
            .then(function (chr) {
                bleChar = chr;
                bleQueue = Promise.resolve();
                showTransport();
            });
    }

    function connectBluetooth() {
        say('Chọn "STEM Quiz" trong danh sách…');
        navigator.bluetooth.requestDevice({ filters: [{ services: [BLE_SERVICE] }] })
            .then(function (device) {
                bleDevice = device;
                device.addEventListener('gattserverdisconnected', bluetoothLost);
                say('Đang kết nối Bluetooth…');
                return openExchange(device);
            })
            .then(function () {
                say('Đã kết nối Bluetooth.', 'good');
                if (state.session && !timer) { poll(); }
            })
            .catch(function (error) {
                if (error.name === 'NotFoundError') {
                    say('Chưa chọn thiết bị. Máy chủ cần bật Bluetooth và chạy ble_gateway.', 'bad');
                } else {
                    say('Không kết nối được Bluetooth: ' + error.message, 'bad');
                }
            });
    }

    // Mất Bluetooth giữa chừng: quay về Wi-Fi và thử nối lại thiết bị cũ vài lần.
    function bluetoothLost() {
        bleChar = null;
        showTransport();
        if (bleLeaving) {
            bleLeaving = false;
            say('Đã ngắt Bluetooth, gửi qua Wi-Fi.');
            return;
        }

        var tries = 0;
        var retry = function () {
            if (bleChar || !bleDevice || ++tries > 3) { return; }
            say('Mất Bluetooth, đang nối lại…', 'bad');
            openExchange(bleDevice)
                .then(function () { say('Đã nối lại Bluetooth.', 'good'); })
                .catch(function () { setTimeout(retry, 2000); });
        };
        setTimeout(retry, 1000);
    }

    el('ble-toggle').addEventListener('click', function () {
        if (bleChar) {
            bleLeaving = true;
            bleDevice.gatt.disconnect();
        } else {
            connectBluetooth();
        }
    });

    if (bleSupported) {
        el('transport').hidden = false;
        showTransport();
    } else {
        var hint = el('ble-hint');

        hint.hidden = false;
        hint.innerHTML = window.isSecureContext
            ? 'Trình duyệt này không có Bluetooth (iPhone, Firefox). Bluetooth chỉ dùng được trên Chrome Android.'
            : 'Muốn trả lời qua Bluetooth khi mất Wi-Fi (Android)? <a href="phone_setup.php' + location.search.replace(/[^?=&\w]/g, '') + '">Xem cách cài</a>';
    }

    // Lưu sẵn trang trong máy để mất Wi-Fi vẫn mở được (chỉ chạy trên https).
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('phone-sw.js', { scope: 'phone.php' }).catch(function () {});
    }

    // Remember the device code; resume a joined session after a reload, like the ESP32 does.
    var linked = new URLSearchParams(location.search).get('device');

    if (state.device) { el('device').value = state.device; }
    if (linked && /^\d{2}$/.test(linked) && !state.session) { el('device').value = linked; }
    if (state.student) { el('student').value = state.student; }
    if (state.session) {
        showJoined(true);
        poll();
    }
})();
</script>
</body>
</html>
