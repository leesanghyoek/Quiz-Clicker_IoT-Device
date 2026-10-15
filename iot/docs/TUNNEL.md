# Mở STEM Quiz ra Internet bằng Cloudflare Tunnel

Laptop vẫn là máy chủ như trước. `cloudflared` (một chương trình nhỏ, chạy thêm trên laptop) tạo một đường hầm ra Cloudflare; người dùng vào địa chỉ `https://….trycloudflare.com/iot/login.php` và Cloudflare chuyển yêu cầu về Apache trên laptop. Không cần mở cổng router, tự có HTTPS.

**Firmware ESP32 không bị ảnh hưởng.** ESP32 và cầu nối Bluetooth vẫn nói chuyện với laptop trong mạng lớp học như cũ. Chỉ khi chuyển web sang máy chủ khác (VPS) thì mới phải sửa firmware.

## Bật / tắt
```bash
sudo ~/IoT/tunnel/install-tunnel-guard.sh   # một lần: bật các hạn chế của Apache cho yêu cầu từ Internet
~/IoT/tunnel/start-tunnel.sh                # mở tunnel; in ra địa chỉ trycloudflare.com; Ctrl+C để đóng
```
`start-tunnel.sh` tự kiểm tra và **từ chối mở tunnel nếu các hạn chế chưa bật**.

Địa chỉ của tunnel nhanh (không cần tài khoản) **đổi mỗi lần chạy** và không đảm bảo hoạt động liên tục. Muốn địa chỉ cố định (ví dụ `quiz.ten-mien-cua-ban.com`) thì dùng tunnel có tên: cần tài khoản Cloudflare miễn phí và một tên miền.

## Cái gì được phép, cái gì bị chặn
Yêu cầu đi qua tunnel luôn có header `Cf-Connecting-Ip` (Cloudflare đặt, mạng lớp học không bao giờ có). Dựa vào đó:

| | Mạng lớp học (Wi-Fi, ESP32, Bluetooth) | Từ Internet qua tunnel |
|---|---|---|
| Trang giáo viên `/iot/…` | Có | Có (phải đăng nhập) |
| API thiết bị `join`, `answer`, `session_state` | Có | **Không** (403) |
| Phần còn lại của `/var/www/html` (project khác) | Có | **Không** |
| `/iot/docs`, `/iot/database`, `/iot/config`, `/iot/includes`, file `.md .sql .sh .py .json…`, file ẩn | Có | **Không** |

Hạn chế nằm ở hai chỗ: `~/IoT/tunnel/stem-quiz-tunnel.conf` (Apache) và `includes/security.php` (PHP, cho API thiết bị).

Hệ quả: **học sinh ở ngoài mạng lớp không trả lời quiz từ xa được** (điện thoại dùng `phone.php` phải vào Wi-Fi lớp).

## Các lớp bảo vệ khác (`includes/security.php`)
- **Mã mời đăng ký:** tạo tài khoản giáo viên cần mã trong `/var/www/iot-private/site_config.php` (`register_invite_code`). Đổi mã bất cứ lúc nào, tài khoản cũ vẫn dùng được; để trống là đóng đăng ký. Nhập sai 8 lần/giờ từ một địa chỉ thì bị khoá 1 giờ.
- **Chống dò mật khẩu:** sai 5 lần với cùng email từ cùng một địa chỉ, hoặc 30 lần từ một địa chỉ, thì khoá đăng nhập 15 phút. Người khác ở địa chỉ khác vẫn đăng nhập được bình thường.
- **Cookie phiên** có `HttpOnly`, `SameSite=Lax`, và `Secure` khi qua https. Đăng nhập xong đổi mã phiên (chống chiếm phiên).
- **AI:** giới hạn số lượt mỗi ngày cho mỗi giáo viên khi dùng Claude (xem `AI_QUIZ.md`). Nên đặt thêm giới hạn chi tiêu hằng tháng trong console.anthropic.com. AI chạy trên máy (Gemma) không tốn tiền.

## Lưu ý
- Laptop phải bật và có Internet thì địa chỉ mới sống.
- Dữ liệu học sinh (tên, MSSV, điểm) nằm trên laptop nhưng truy cập được từ Internet: dùng mật khẩu mạnh, đừng đưa mã mời bừa bãi.
- Trang chủ `https://….trycloudflare.com/` (không có `/iot/…`) sẽ báo 403; vào thẳng `/iot/login.php`.
- Muốn tắt hẳn: Ctrl+C `start-tunnel.sh`, và (tuỳ chọn) `sudo a2disconf stem-quiz-tunnel && sudo systemctl reload apache2`.
