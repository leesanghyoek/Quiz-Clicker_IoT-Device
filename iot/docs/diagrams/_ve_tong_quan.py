# Sơ đồ kiến trúc tổng quan (bản gọn): người dùng -> máy chủ -> database.
# Chạy: python3 _ve_tong_quan.py  -> 00-kien-truc-tong-quan.svg
from html import escape
W, H = 1400, 640
o = []
def add(s): o.append(s)
def box(x, y, w, h, fill, stroke, rx=14, sw=2, dash=None):
    d = f' stroke-dasharray="{dash}"' if dash else ''
    add(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"{d}/>')
def text(x, y, s, size=16, weight='normal', color='#1d2a44', anchor='middle'):
    add(f'<text x="{x}" y="{y}" font-size="{size}" font-weight="{weight}" fill="{color}" text-anchor="{anchor}">{escape(s)}</text>')
def card(x, y, w, h, title, sub, fill, stroke, color):
    box(x, y, w, h, fill, stroke)
    text(x + w / 2, y + h / 2 - 4, title, 18, 'bold', color)
    text(x + w / 2, y + h / 2 + 20, sub, 14, 'normal', '#4a5670')
def arrow(pts, color, dash=None, mk='a'):
    d = ' '.join(f'{"M" if i == 0 else "L"}{x},{y}' for i, (x, y) in enumerate(pts))
    ds = f' stroke-dasharray="{dash}"' if dash else ''
    add(f'<path d="{d}" fill="none" stroke="{color}" stroke-width="2.6"{ds} marker-end="url(#{mk})"/>')

BLUE, PURPLE, GREEN, ORANGE = '#2f5bb7', '#7b4fd1', '#1f6b45', '#a8650f'
add(f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}" font-family="DejaVu Sans, Arial, sans-serif">')
add('<defs>' + ''.join(f'<marker id="{n}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="{c}"/></marker>'
                       for n, c in [('a', BLUE), ('b', PURPLE), ('g', GREEN), ('o', ORANGE)]) + '</defs>')
add(f'<rect width="{W}" height="{H}" fill="#ffffff"/>')
text(W / 2, 46, 'Kiến trúc tổng quan hệ thống STEM Quiz', 26, 'bold')

# Người dùng
text(190, 110, 'NGƯỜI DÙNG', 15, 'bold', '#2d4a8a')
card(40, 140, 300, 120, 'Giáo viên', 'trình duyệt web trên laptop', '#eaf2ff', '#8fb0e8', '#2d4a8a')
box(40, 290, 300, 240, '#eaf2ff', '#8fb0e8')
text(190, 322, 'Học sinh', 18, 'bold', '#2d4a8a')
for i, (t, s) in enumerate([('Thiết bị ESP32', 'bàn phím bấm A / B / C / D'), ('Điện thoại', 'trang web phone.php')]):
    box(60, 340 + i * 90, 260, 76, '#ffffff', '#b9cdf0', rx=10, sw=1.5)
    text(190, 372 + i * 90, t, 16, 'bold')
    text(190, 396 + i * 90, s, 13.5, 'normal', '#4a5670')

# Máy chủ
box(480, 90, 560, 450, '#effaf2', '#9fd3b0', rx=18)
text(760, 122, 'MÁY CHỦ · laptop giáo viên', 16, 'bold', GREEN)
box(505, 140, 510, 220, '#ffffff', '#7fbf96')
text(760, 168, 'Web server Apache + PHP', 17, 'bold', GREEN)
card(530, 185, 460, 70, 'Website giáo viên', 'quản lý lớp, câu hỏi · chạy quiz · xem kết quả', '#f3fbf6', '#a9d8b9', GREEN)
card(530, 270, 460, 70, 'API cho thiết bị (JSON)', 'vào phòng · nhận câu hỏi · gửi đáp án', '#f3fbf6', '#a9d8b9', GREEN)
card(505, 410, 510, 105, 'Cầu nối Bluetooth (Python)', 'chuyển yêu cầu Bluetooth thành lời gọi API', '#f7f3ff', '#b59ae6', '#5a36a8')

# Database
cx, top, cw, body = 1110, 210, 250, 170
add(f'<path d="M{cx},{top} L{cx},{top + body} A{cw / 2},20 0 0 0 {cx + cw},{top + body} L{cx + cw},{top}" fill="#fff7e8" stroke="#d9a650" stroke-width="2"/>')
add(f'<ellipse cx="{cx + cw / 2}" cy="{top}" rx="{cw / 2}" ry="20" fill="#fdebc8" stroke="#d9a650" stroke-width="2"/>')
text(cx + cw / 2, 110, 'DỮ LIỆU', 15, 'bold', ORANGE)
text(cx + cw / 2, top + 62, 'MariaDB', 20, 'bold', '#5c3b08')
for i, s in enumerate(['giáo viên · lớp · học sinh', 'câu hỏi · thiết bị', 'phiên quiz · đáp án']):
    text(cx + cw / 2, top + 94 + i * 24, s, 14, 'normal', '#5c3b08')

# Mũi tên
arrow([(340, 200), (440, 200), (440, 220), (528, 220)], BLUE)
text(390, 190, 'Wi-Fi', 14, 'bold', BLUE)
arrow([(340, 380), (440, 380), (440, 305), (528, 305)], BLUE)
text(392, 368, 'Wi-Fi', 14, 'bold', BLUE)
arrow([(340, 470), (503, 470)], PURPLE, dash='9 6', mk='b')
text(422, 458, 'Bluetooth', 14, 'bold', PURPLE)
text(422, 492, '(dự phòng)', 13, 'normal', PURPLE)
arrow([(760, 410), (760, 342)], PURPLE, mk='b')
text(772, 382, 'gọi API', 13, 'bold', PURPLE, 'start')
arrow([(1015, 250), (1060, 250), (1060, 300), (1108, 300)], ORANGE, mk='o')
text(1062, 238, 'SQL', 14, 'bold', ORANGE)

# Chú thích
ly = 595
add(f'<line x1="330" y1="{ly}" x2="390" y2="{ly}" stroke="{BLUE}" stroke-width="2.6"/>')
text(400, ly + 5, 'Wi-Fi · HTTP + JSON', 14, 'normal', '#1d2a44', 'start')
add(f'<line x1="620" y1="{ly}" x2="680" y2="{ly}" stroke="{PURPLE}" stroke-width="2.6" stroke-dasharray="9 6"/>')
text(690, ly + 5, 'Bluetooth Low Energy', 14, 'normal', '#1d2a44', 'start')
add(f'<line x1="920" y1="{ly}" x2="980" y2="{ly}" stroke="{ORANGE}" stroke-width="2.6"/>')
text(990, ly + 5, 'Truy vấn database', 14, 'normal', '#1d2a44', 'start')
add('</svg>')
import os
open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '00-kien-truc-tong-quan.svg'), 'w', encoding='utf-8').write('\n'.join(o))
print('ok')
