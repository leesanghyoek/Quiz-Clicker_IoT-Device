# Draws docs/diagrams/00-kien-truc-tong-the-chi-tiet.svg: client -> server -> data layers.
from html import escape
W, H = 1600, 1410
out = []
def add(s): out.append(s)

def rect(x, y, w, h, fill, stroke, rx=10, dash=None, sw=1.5):
    d = f' stroke-dasharray="{dash}"' if dash else ''
    add(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"{d}/>')

def text(x, y, s, size=13, weight='normal', color='#1d2a44', anchor='middle', halo=None):
    h = f' stroke="{halo}" stroke-width="7" stroke-linejoin="round" paint-order="stroke"' if halo else ''
    add(f'<text x="{x}" y="{y}" font-size="{size}" font-weight="{weight}" fill="{color}" text-anchor="{anchor}"{h}>{escape(s)}</text>')

def chip(x, y, w, h, lines, fill='#f6f9ff', stroke='#c4d3ef', dash=None, size=12.5, bold_first=False):
    rect(x, y, w, h, fill, stroke, rx=8, dash=dash, sw=1.2)
    n = len(lines)
    lh = size + 4
    top = y + h / 2 - (n - 1) * lh / 2 + size * 0.36
    for i, line in enumerate(lines):
        text(x + w / 2, top + i * lh, line, size, 'bold' if (bold_first and i == 0) else 'normal')

def arrow(points, color, dash=None, halo=False, marker='a'):
    d = ' '.join(f'{"M" if i == 0 else "L"}{x},{y}' for i, (x, y) in enumerate(points))
    if halo:
        add(f'<path d="{d}" fill="none" stroke="#ffffff" stroke-width="8"/>')
    ds = f' stroke-dasharray="{dash}"' if dash else ''
    add(f'<path d="{d}" fill="none" stroke="{color}" stroke-width="2.2"{ds} marker-end="url(#{marker})"/>')

def label(x, y, s, color, anchor='start'):
    text(x, y, s, 12.5, 'bold', color, anchor)

WIFI, BLE, MDNS, SQL, INNER = '#2f5bb7', '#7b4fd1', '#1f8f63', '#b06a12', '#5b6b8a'

add(f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}" font-family="DejaVu Sans, Arial, sans-serif">')
add('<defs>' + ''.join(
    f'<marker id="{n}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{c}"/></marker>'
    for n, c in [('a', WIFI), ('b', BLE), ('m', MDNS), ('s', SQL), ('i', INNER)]) + '</defs>')
add(f'<rect width="{W}" height="{H}" fill="#ffffff"/>')
text(W / 2, 40, 'Kiến trúc tổng thể hệ thống STEM Quiz', 26, 'bold')

# ---------------- CLIENT ----------------
rect(20, 64, 1560, 290, '#eaf2ff', '#b9cdf0', rx=16)
text(40, 88, 'TẦNG CLIENT', 15, 'bold', '#2d4a8a', 'start')
cols = [
    (45, 'Giáo viên · trình duyệt trên laptop', [
        (['Giao diện quản lý & phân tích', 'HTML + form POST'], None),
        (['Màn hình trình chiếu (máy chiếu)', 'lobby · quiz_run'], None),
        (['Nền 3D three.js', 'stem-world.js'], None),
        (['sessionStorage / localStorage', 'trạng thái cảnh 3D · kiểu giao diện'], '5 4')]),
    (560, 'Học sinh · điện thoại', [
        (['phone.php', 'nút A/B/C/D · hỏi trạng thái mỗi giây'], None),
        (['Web Bluetooth', 'Chrome trên Android'], None),
        (['Service Worker cache', 'mở được trang khi mất Wi-Fi'], '5 4'),
        (['localStorage', 'mã máy · STT · phiên đang làm'], '5 4')]),
    (1075, 'Học sinh · thiết bị ESP32', [
        (['uiTask (FreeRTOS)', 'bàn phím 4×4 · LED 7 đoạn · LED A–D'], None),
        (['loop(): máy trạng thái', 'sendRequest() chọn Wi-Fi hoặc BLE'], None),
        (['findServer()', 'mDNS → IP cũ → gateway → dự phòng'], None),
        (['Flash (Preferences)', 'phiên đang làm · IP máy chủ'], '5 4')]),
]
for x, title, items in cols:
    rect(x, 100, 490, 240, '#ffffff', '#8fb0e8', rx=12)
    text(x + 245, 124, title, 15, 'bold', '#2d4a8a')
    for i, (lines, dash) in enumerate(items):
        chip(x + 20, 136 + i * 50, 450, 42, lines, dash=dash, size=12.5, bold_first=True)

# ---------------- SERVER ----------------
rect(20, 470, 1560, 600, '#effaf2', '#b5dcc1', rx=16)


rect(45, 510, 820, 110, '#ffffff', '#7fbf96', rx=12)
text(455, 534, 'Web server · Apache 2', 14, 'bold', '#1f6b45')
chip(65, 552, 380, 50, ['VirtualHost :80', 'HTTP'], fill='#f3fbf6', stroke='#a9d8b9', bold_first=True)
chip(465, 552, 380, 50, ['VirtualHost :443', 'HTTPS · chứng chỉ do CA nội bộ ký'], fill='#f3fbf6', stroke='#a9d8b9', bold_first=True)

rect(905, 510, 410, 110, '#ffffff', '#b59ae6', rx=12)
text(1110, 534, 'Cầu nối Bluetooth · Python 3.12', 14, 'bold', '#5a36a8')
chip(920, 546, 380, 30, ['ble_gateway.py · BLE central (bleak) → ESP32'], fill='#f7f3ff', stroke='#d3c4f2', size=12)
chip(920, 582, 380, 30, ['phone_server.py · BLE peripheral (BlueZ) → điện thoại'], fill='#f7f3ff', stroke='#d3c4f2', size=12)

rect(1345, 510, 220, 110, '#ffffff', '#9fb4c9', rx=12)
text(1455, 532, 'Dịch vụ hệ điều hành', 13.5, 'bold', '#3d5470')
for i, s in enumerate(['Hotspot 10.42.0.1', 'avahi · mDNS *.local', 'BlueZ 5.72 · Bluetooth']):
    chip(1355, 542 + i * 25, 200, 21, [s], fill='#f4f7fa', stroke='#cdd8e3', size=10.5)

# PHP application
rect(45, 650, 1520, 400, '#ffffff', '#7fbf96', rx=14)
text(805, 674, 'Ứng dụng PHP 8.3 · /var/www/html/iot', 15, 'bold', '#1f6b45')

rect(65, 688, 700, 196, '#fbfdfb', '#a9d8b9', rx=10)
text(415, 708, 'Trang web giáo viên · cần đăng nhập', 13.5, 'bold', '#1f6b45')
for i, s in enumerate([
        'Tài khoản — register · login · account · logout',
        'Quản lý — dashboard · classes · class · students · quizzes · quiz · devices',
        'Thao tác (POST + CSRF) — add/edit/delete_* · import_* · assign · launch · start · next · end · cancel',
        'Trình chiếu — lobby · quiz_run',
        'Phân tích — results · session_results · result_question · progress · review']):
    chip(80, 718 + i * 32, 670, 27, [s], fill='#f3fbf6', stroke='#c6e6d1', size=11.5)

rect(785, 688, 380, 196, '#fbfdfb', '#a9d8b9', rx=10)
text(975, 708, 'API JSON', 13.5, 'bold', '#1f6b45')
chip(800, 718, 350, 70, ['Cho thiết bị · công khai', 'api/join · api/session_state', 'api/answer'], fill='#f3fbf6', stroke='#c6e6d1', size=12, bold_first=True)
chip(800, 798, 350, 76, ['Cập nhật màn hình · cần đăng nhập', 'api/lobby_status · api/question_status', 'api/reorder_questions'], fill='#f3fbf6', stroke='#c6e6d1', size=12, bold_first=True)

rect(1185, 688, 360, 196, '#fbfdfb', '#a9d8b9', rx=10)
text(1365, 708, 'Trang học sinh & tài nguyên · công khai', 13.5, 'bold', '#1f6b45')
chip(1200, 718, 330, 70, ['phone.php · phone_setup.php', 'phone-sw.js (Service Worker)', 'phone.webmanifest (PWA)'], fill='#f3fbf6', stroke='#c6e6d1', size=12)
chip(1200, 798, 330, 76, ['assets/css · assets/js (three.js)', 'icon · stem-quiz-ca.crt', '(tải bằng trình duyệt)'], fill='#f3fbf6', stroke='#c6e6d1', size=12)

rect(65, 904, 1480, 132, '#fbfdfb', '#a9d8b9', rx=10)
text(805, 924, 'Thư viện dùng chung · includes/ và config/', 13.5, 'bold', '#1f6b45')
libs = [
    ['auth.php', 'phiên đăng nhập · CSRF'], ['join_code.php', 'cấp mã phòng (GET_LOCK)'],
    ['quiz_lock.php', 'khoá sửa quiz đã chạy'], ['student_rules.php', 'STT đúng 2 chữ số'],
    ['results_data · progress_data', 'review_data · cách tính điểm'], ['live_sessions.php', 'phiên chưa kết thúc'],
    ['layout · topbar · sidebar', 'places · flash · wordmark'], ['config/db.php', 'kết nối mysqli · utf8mb4'],
]
for i, lines in enumerate(libs):
    cx = 80 + (i % 4) * 365
    cy = 934 + (i // 4) * 50
    hl = i == 7
    chip(cx, cy, 350, 44, lines, fill='#fff6e6' if hl else '#f3fbf6', stroke='#e3b56a' if hl else '#c6e6d1', size=12, bold_first=True)

# inner arrows: Apache -> app, groups -> includes
arrow([(455, 620), (455, 650)], INNER, marker='i')
label(465, 642, '③ định tuyến URL → file .php', INNER)
for x in (415, 975):
    arrow([(x, 884), (x, 904)], INNER, marker='i')
arrow([(905, 597), (867, 597)], BLE, marker='b')
label(870, 590, '②', BLE)
arrow([(1315, 597), (1343, 597)], BLE, marker='b')

# ---------------- DATA ----------------
rect(20, 1106, 1560, 250, '#fff7e8', '#ecd2a2', rx=16)
text(40, 1130, 'TẦNG DỮ LIỆU · MariaDB 10.11 · database iot (InnoDB, utf8mb4)', 15, 'bold', '#8a5a12', 'start')
groups = [
    ['Người dùng', 'teachers'],
    ['Lớp học', 'classes', 'students'],
    ['Ngân hàng câu hỏi', 'quizzes · questions', 'question_options', 'class_quizzes'],
    ['Thiết bị', 'devices'],
    ['Phiên trực tiếp', 'live_sessions', 'device_sessions', 'responses'],
]
cw, gap = 270, 27
start = (W - (5 * cw + 4 * gap)) / 2
for i, lines in enumerate(groups):
    x = start + i * (cw + gap)
    top, body = 1162, 128
    add(f'<path d="M{x},{top} L{x},{top + body} A{cw / 2},16 0 0 0 {x + cw},{top + body} L{x + cw},{top} " fill="#fffaf0" stroke="#d9a650" stroke-width="1.5"/>')
    add(f'<ellipse cx="{x + cw / 2}" cy="{top}" rx="{cw / 2}" ry="16" fill="#fdebc8" stroke="#d9a650" stroke-width="1.5"/>')
    n = len(lines)
    y0 = top + body / 2 + 10 - (n - 1) * 9
    for j, s in enumerate(lines):
        text(x + cw / 2, y0 + j * 19, s, 14 if j == 0 else 12.5, 'bold' if j == 0 else 'normal', '#5c3b08')
text(W / 2, 1338, 'Khoá ngoại RESTRICT · UNIQUE chống vào phòng / trả lời 2 lần · transaction + SELECT … FOR UPDATE · ON DELETE SET NULL giữ kết quả khi xoá thiết bị',
     12.5, 'normal', '#8a5a12')

# SQL arrow: config/db.php -> data
dbx = 80 + 3 * 365 + 175
arrow([(dbx, 1028), (dbx, 1142)], SQL, marker='s')
label(dbx + 10, 1080, '① SQL · prepared statement', SQL)
label(dbx + 10, 1098, '    transaction · khoá hàng', SQL)

# ---------------- CLIENT -> SERVER ----------------
arrow([(200, 340), (200, 508)], WIFI)
label(208, 410, '④ Wi-Fi · HTML, form POST', WIFI)
arrow([(430, 340), (430, 508)], WIFI)
label(438, 440, '⑤ JSON mỗi giây', WIFI)
arrow([(640, 340), (640, 508)], WIFI)
label(648, 410, '⑨ Wi-Fi · 3 API', WIFI)
arrow([(1000, 340), (1000, 508)], BLE, dash='7 5')
label(1008, 440, '⑩ BLE · lệnh J / S / A', BLE)
arrow([(1120, 340), (1120, 395), (820, 395), (820, 508)], WIFI, halo=True)
label(828, 388, '⑥ Wi-Fi · 3 API', WIFI)
arrow([(1240, 340), (1240, 508)], BLE, dash='7 5')
label(1248, 410, '⑦ BLE (bấm **)', BLE)
arrow([(1460, 340), (1460, 508)], MDNS, dash='2 4')
label(1468, 440, '⑧ mDNS', MDNS)

# ---------------- LEGEND ----------------
ly = 1390
add(f'<line x1="300" y1="{ly}" x2="350" y2="{ly}" stroke="{WIFI}" stroke-width="2.2"/>')
text(358, ly + 4, 'Wi-Fi · HTTP/HTTPS + JSON', 12.5, 'normal', '#1d2a44', 'start')
add(f'<line x1="600" y1="{ly}" x2="650" y2="{ly}" stroke="{BLE}" stroke-width="2.2" stroke-dasharray="7 5"/>')
text(658, ly + 4, 'Bluetooth Low Energy (GATT)', 12.5, 'normal', '#1d2a44', 'start')
add(f'<line x1="900" y1="{ly}" x2="950" y2="{ly}" stroke="{MDNS}" stroke-width="2.2" stroke-dasharray="2 4"/>')
text(958, ly + 4, 'mDNS', 12.5, 'normal', '#1d2a44', 'start')
add(f'<rect x="1060" y="{ly - 9}" width="40" height="18" rx="4" fill="#f6f9ff" stroke="#c4d3ef" stroke-dasharray="5 4"/>')
text(1108, ly + 4, 'lưu trữ phía client', 12.5, 'normal', '#1d2a44', 'start')

text(40, 494, 'TẦNG MÁY CHỦ · laptop giáo viên (Ubuntu)', 15, 'bold', '#1f6b45', 'start', halo='#effaf2')
add('</svg>')
open('/var/www/html/iot/docs/diagrams/00-kien-truc-tong-the-chi-tiet.svg', 'w', encoding='utf-8').write('\n'.join(out))
print('svg ok')
