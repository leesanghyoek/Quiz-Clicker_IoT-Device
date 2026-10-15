<?php
/*
 * One-time setup so a student's Android phone can answer over Bluetooth:
 * trust the local certificate, open phone.php over https, install it.
 * Linked from phone.php when the page is not on https yet.
 */
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Cài Bluetooth</title>
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
    line-height: 1.5;
    display: flex;
    justify-content: center;
    padding: 16px;
}

main { width: 100%; max-width: 480px; display: flex; flex-direction: column; gap: 14px; }
h1 { margin: 8px 0 0; font-size: 22px; }
p { margin: 0; color: var(--slate); }

ol { margin: 0; padding: 0; list-style: none; counter-reset: step; display: flex; flex-direction: column; gap: 12px; }

li {
    counter-increment: step;
    background: var(--card);
    border: 1px solid var(--rule);
    border-radius: 16px;
    padding: 14px 16px 14px 56px;
    position: relative;
}

li::before {
    content: counter(step);
    position: absolute;
    left: 14px;
    top: 14px;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--on-accent);
    font-weight: 700;
    display: grid;
    place-items: center;
}

li strong { display: block; margin-bottom: 4px; }
li p { font-size: 15px; }

.button {
    display: inline-block;
    margin-top: 10px;
    padding: 10px 16px;
    border-radius: 12px;
    background: var(--accent);
    color: var(--on-accent);
    font-weight: 600;
    text-decoration: none;
    overflow-wrap: anywhere;
}

.path { color: var(--ink); font-weight: 500; }
.note { font-size: 14px; }
.done { color: var(--good); font-weight: 600; }
</style>
</head>
<body>
<main>
    <h1>Trả lời qua Bluetooth</h1>
    <p>Làm một lần trên mỗi điện thoại Android, <b>lúc còn Wi-Fi</b>. Cần trình duyệt Chrome. iPhone không dùng được Bluetooth, vẫn trả lời qua Wi-Fi như bình thường.</p>

    <ol>
        <li>
            <strong>Tải chứng chỉ của lớp</strong>
            <p>Để Chrome tin trang https của máy giáo viên.</p>
            <a class="button" href="stem-quiz-ca.crt" download>Tải stem-quiz-ca.crt</a>
        </li>
        <li>
            <strong>Cài chứng chỉ</strong>
            <p>Mở <span class="path">Cài đặt</span>, gõ tìm <span class="path">"chứng chỉ CA"</span>, hoặc vào
                <span class="path">Bảo mật → Cài đặt bảo mật khác → Mã hoá và thông tin xác thực → Cài đặt chứng chỉ → Chứng chỉ CA</span>.
                Bấm <span class="path">Vẫn cài đặt</span> rồi chọn file <span class="path">stem-quiz-ca.crt</span> vừa tải.</p>
            <p class="note">Chứng chỉ này chỉ dùng được cho địa chỉ mạng nội bộ (như 10.42.0.1), không ảnh hưởng các trang web khác.</p>
        </li>
        <li>
            <strong>Mở trang trả lời bằng https</strong>
            <p id="https-text">Trang phải mở ra bình thường, không có cảnh báo. Có cảnh báo nghĩa là bước 2 chưa xong.</p>
            <a class="button" id="https-link" href="phone.php">Mở trang https</a>
        </li>
        <li>
            <strong>Cài thành ứng dụng</strong>
            <p>Ở trang vừa mở, bấm <span class="path">⋮ → Thêm vào màn hình chính</span> (hoặc <span class="path">Cài đặt ứng dụng</span>). Trên màn hình chính sẽ có biểu tượng <span class="path">STEM Quiz</span>, mất Wi-Fi vẫn mở được.</p>
        </li>
        <li>
            <strong>Khi mất Wi-Fi</strong>
            <p>Mở <span class="path">STEM Quiz</span>, bấm <span class="path">Dùng Bluetooth</span>, chọn <span class="path">STEM Quiz</span> trong danh sách, cho phép quyền <span class="path">Thiết bị ở gần</span> (máy cũ hỏi quyền <span class="path">Vị trí</span>). Sau đó trả lời như bình thường.</p>
        </li>
    </ol>
</main>

<script>
(function () {
    // Same address as this page, over https, keeping ?device=XX.
    var page = location.pathname.replace(/phone_setup\.php$/, 'phone.php');
    var link = document.getElementById('https-link');

    link.href = 'https://' + location.hostname + page + location.search;
    link.textContent = 'https://' + location.hostname + page;

    if (location.protocol === 'https:') {
        document.getElementById('https-text').innerHTML = '<span class="done">Trang này đã mở bằng https: bước 1–2 đã xong.</span>';
    }
})();
</script>
</body>
</html>
