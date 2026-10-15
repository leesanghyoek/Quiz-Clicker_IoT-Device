# Tóm tắt: triển khai hệ thống quiz IoT

*Cập nhật: 11/10/2026*

## 1. Project là gì

`/home/buidinhkhoi/IoT/iot` là **hệ thống trắc nghiệm trực tiếp trên lớp (kiểu Kahoot)**, viết bằng **PHP thuần + MySQL/MariaDB**:

- **Giáo viên** dùng web để quản lý lớp, học sinh và quiz, chạy quiz trực tiếp và xem kết quả.
- **Học sinh** trả lời bằng **thiết bị ESP** (bấm A/B/C/D). Thiết bị gọi các API JSON trong `iot/api/`.

| API | Chức năng |
|---|---|
| `POST api/join.php` | ESP gửi `device_id`, `student_id`, `join_code` để vào phiên |
| `GET api/session_state.php` | ESP hỏi trạng thái phiên: `WAITING` / `ACTIVE` / `ENDED` và câu hỏi hiện tại |
| `POST api/answer.php` | ESP gửi đáp án A–D, mỗi học sinh chỉ trả lời một lần mỗi câu |

- Mã thiết bị, mã học sinh và mã tham gia đều có **đúng 2 chữ số** (`00`–`99`).
- 3 file `answer.php`, `join.php`, `session_state.php` ở `IoT/` chỉ là bản sao của các file trong `iot/api/`.
- Thư mục này **không có code firmware cho ESP**.

## 2. Môi trường trên máy

- Apache 2 + PHP 8.3 (có `mysqli`, **không có `mbstring`**), MariaDB 10.11. Tất cả đã chạy sẵn.
- Thư mục web của Apache: `/var/www/html`. App chạy ở `/var/www/html/iot`, vì code chuyển hướng cứng về `/iot/login.php`.
- IP máy chủ trong mạng LAN: `10.0.152.147` hoặc `10.0.220.246`. ESP dùng địa chỉ cùng mạng Wi-Fi với nó.

## 3. Các bước triển khai

| # | Bước | Trạng thái |
|---|---|---|
| 1 | Copy code sang `/var/www/html/iot` | ✅ Xong |
| 2 | Tạo database `iot` và user `iot_app` (mật khẩu lấy theo `config/db.php`) | ✅ Xong |
| 3 | Nạp `database/schema.sql` (11 bảng) | ✅ Xong |
| 4 | Thêm trang đăng ký tài khoản giáo viên | ✅ Xong, đã đưa lên Apache |
| 5 | Đăng ký thiết bị ESP vào bảng `devices` | ✅ Đã thêm `01` (12/10/2026). Thiết bị khác thì thêm tiếp |
| 6 | Cấu hình ESP gọi `http://<IP>/iot/api/...` | ⏳ Chưa làm |

Lệnh đã chạy cho bước 2 và 3 (cần `sudo`, chạy ở terminal riêng):

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS iot CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'iot_app'@'localhost' IDENTIFIED BY '<mật khẩu trong config/db.php>';
ALTER USER 'iot_app'@'localhost' IDENTIFIED BY '<mật khẩu trong config/db.php>';
GRANT ALL PRIVILEGES ON iot.* TO 'iot_app'@'localhost';"

sudo mysql iot < /home/buidinhkhoi/IoT/iot/database/schema.sql
```

> ⚠️ **Không chạy** `database/migrations/20261006_...sql` nữa. Nội dung của nó đã được gộp vào `schema.sql`, chạy lại sẽ lỗi trùng cột.

## 4. Các file đã thêm hoặc sửa

| File | Thay đổi |
|---|---|
| `iot/database/schema.sql` | **Mới.** Project ban đầu thiếu file tạo bảng, nên schema này được dựng lại từ các câu SQL trong code. Gồm 11 bảng: `teachers`, `classes`, `students`, `quizzes`, `questions`, `question_options`, `class_quizzes`, `devices`, `live_sessions`, `device_sessions`, `responses` |
| `iot/register.php` | **Mới.** Trang đăng ký giáo viên: họ tên, email, mật khẩu (≥ 8 ký tự), xác nhận mật khẩu, có CSRF token. Đăng ký xong tự đăng nhập |
| `iot/login.php` | Thêm link "Don't have an account? Create one" |
| `iot/assets/css/login.css` | Thêm style `.switch-link` |

## 5. Kết quả kiểm tra

Đã kiểm tra trên một MariaDB tạm, không đụng tới database thật:

- **Luồng quiz đầy đủ:** đăng nhập → tạo lớp, học sinh, quiz → giao quiz → mở phòng chờ → ESP join → bắt đầu → ESP trả lời. Gửi đáp án lần hai bị chặn (`already_answered`) → kết thúc → xem kết quả. **Tất cả chạy đúng.**
- **Đăng ký:** các trường hợp sai dữ liệu đều được báo lỗi. Đăng ký thành công thì vào được dashboard và đăng nhập lại được.
- **Trên Apache thật:** `login.php` và `register.php` mở bình thường. API trả JSON, tức là đã kết nối được database.
- Test phát hiện máy **không có `mbstring`**, nên `register.php` không dùng `mb_strlen`.

## 6. Tài khoản được lưu ở đâu

Tài khoản nằm trong database **`iot`**, bảng **`teachers`**: `teacher_id`, `name`, `email`, `password_hash` (bcrypt, không xem lại được mật khẩu gốc), `created_at`.

```bash
mysql -u iot_app -p iot -e "SELECT teacher_id, name, email, created_at FROM teachers;"
```

## 7. Việc còn lại

**Đăng ký thiết bị ESP.** Web chưa có trang quản lý thiết bị, nên phải thêm bằng SQL:

```sql
INSERT INTO devices (device_code, status) VALUES ('01','ACTIVE'), ('02','ACTIVE');
```

**Cách chạy một buổi quiz:**
1. Tạo lớp, thêm học sinh với **mã 2 chữ số**.
2. Tạo quiz, thêm câu hỏi (hoặc nhập từ file CSV), rồi giao quiz cho lớp.
3. Bấm **Launch**. Màn hình hiện mã tham gia 2 chữ số, học sinh nhập mã đó trên ESP.
4. Bấm **Start**, chuyển từng câu hỏi, kết thúc rồi xem kết quả.

## 8. Lưu ý

- **Ai mở được trang web cũng đăng ký được tài khoản**, kể cả học sinh dùng chung Wi-Fi. Dữ liệu vẫn tách riêng theo từng giáo viên. Nếu cần hạn chế, có thể thêm mã mời.
- **Chưa có chức năng "Quên mật khẩu".** Muốn đặt lại phải sửa trực tiếp `password_hash` trong database.
- **Giao diện tải Tabler CSS, icon và SweetAlert2 từ `cdn.jsdelivr.net`.** Máy giáo viên cần có Internet, nếu không trang web sẽ mất định dạng.
- **Mật khẩu database nằm thẳng trong `iot/config/db.php`.** Chỉ chạy hệ thống trong mạng LAN, và không commit file này lên GitHub.
- **Về Git:** repo đang lấy cả thư mục home `/home/buidinhkhoi` làm gốc. Repo riêng trong `iot/` chưa có commit nào.
- **Cách cập nhật code lên Apache sau khi sửa:**
  ```bash
  rsync -a --exclude .git --exclude tests /home/buidinhkhoi/IoT/iot/ /var/www/html/iot/
  ```
