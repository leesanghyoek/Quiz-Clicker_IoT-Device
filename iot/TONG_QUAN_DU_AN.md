# STEM Quiz: hệ thống trắc nghiệm trực tiếp trên lớp dùng thiết bị IoT

> Tài liệu tổng hợp toàn bộ dự án (tính năng, kiến trúc, công nghệ, dữ liệu, thiết bị, kiểm thử, giới hạn) để làm nguồn viết báo cáo.
> Cập nhật: 14/10/2026. Đường dẫn dự án: `/var/www/html/iot` (web), `~/Desktop/esp32project/` (firmware ESP32 và cầu nối Bluetooth).
> Tài liệu kỹ thuật chi tiết của firmware: `Code.md`. Nhật ký triển khai ban đầu: `TOMTAT.md`.
> **Sơ đồ kiến trúc, ERD database, sơ đồ tuần tự và trạng thái:** `docs/SO_DO_HE_THONG.md` (Mermaid) và ảnh PNG trong `docs/diagrams/`.

---

## 1. Giới thiệu

### 1.1. Bài toán
Trong giờ học, giáo viên cần kiểm tra nhanh mức độ hiểu bài của cả lớp. Cách giơ tay hay gọi tên chậm, không ghi lại được, và học sinh ngại trả lời sai trước lớp. Các nền tảng như Kahoot hay Quizizz cần mỗi học sinh có điện thoại và Internet, nhiều trường không cho phép hoặc không có đủ.

### 1.2. Giải pháp
**STEM Quiz** là hệ thống trắc nghiệm trực tiếp kiểu Kahoot chạy hoàn toàn trong **mạng nội bộ của lớp**:
- **Giáo viên** dùng web trên laptop: quản lý lớp, học sinh, ngân hàng câu hỏi, chạy quiz trực tiếp trên máy chiếu, xem kết quả, theo dõi tiến bộ và tạo bài ôn tập.
- **Học sinh** trả lời bằng **thiết bị bấm ESP32** tự chế (bàn phím 4×4, 2 LED 7 đoạn, 4 LED A–D), hoặc bằng **điện thoại** qua trang web.
- Thiết bị nói chuyện với máy chủ qua **Wi-Fi** (HTTP + JSON), và có **Bluetooth Low Energy** làm đường dự phòng khi không dùng được Wi-Fi.
- Không cần Internet trong giờ học: laptop giáo viên vừa là máy chủ web, vừa phát Wi-Fi (hotspot), vừa làm trạm Bluetooth.

### 1.3. Điểm nổi bật
1. **Phần cứng IoT thật**: ESP32 với firmware đa nhiệm (FreeRTOS), lưu phiên vào flash để mất điện vẫn vào lại đúng phòng.
2. **Tự tìm máy chủ**: ESP32 không cần biết IP. Nó tìm laptop qua mDNS, IP đã nhớ, gateway Wi-Fi và IP dự phòng.
3. **Hai đường truyền Wi-Fi ↔ Bluetooth**: bấm `**` để đổi. Một chương trình cầu nối trên laptop chuyển yêu cầu Bluetooth thành lời gọi API, nên phía PHP không phải sửa gì.
4. **Điện thoại Android trả lời qua Bluetooth** từ trình duyệt (Web Bluetooth). Trang cài được như ứng dụng (PWA) và mở được khi không có mạng.
5. **Phân tích học tập**: ma trận học sinh × câu hỏi tô màu theo độ khó, phân bố đáp án, biểu đồ tiến bộ theo thời gian, cảnh báo học sinh cần chú ý.
6. **Tạo quiz ôn tập tự động** từ những câu cả lớp hay sai nhất.
7. **Giao diện 3D** (three.js): các vật thể STEM (nguyên tử, bóng đèn, bút chì, π, Σ…) bay lơ lửng làm nền, chuyển động liền mạch khi đổi trang.
8. **Toàn vẹn dữ liệu**: transaction, khoá hàng (row lock), ràng buộc khoá ngoại, CSRF token, mật khẩu băm bcrypt.

---

## 2. Kiến trúc tổng thể

```
                         ┌──────────────────────── LAPTOP GIÁO VIÊN ────────────────────────┐
                         │                                                                   │
 ┌──────────────┐ Wi-Fi  │  ┌──────────────┐    ┌──────────────────┐    ┌───────────────┐    │
 │ ESP32 (HS)   │──HTTP──┼─►│ Apache 2     │───►│ PHP 8.3          │───►│ MariaDB 10.11 │    │
 │ bàn phím 4x4 │  JSON  │  │ :80 / :443   │    │ trang web + API  │    │ database `iot`│    │
 └──────┬───────┘        │  └──────▲───────┘    └──────────────────┘    └───────────────┘    │
        │ BLE            │         │ HTTP localhost                                          │
        │ (dự phòng)     │  ┌──────┴────────────────────┐                                    │
        └────────────────┼─►│ ble_gateway.py (Python)   │◄── BLE ── Điện thoại Android        │
                         │  │ cầu nối Bluetooth ↔ API   │          (Chrome, Web Bluetooth)    │
                         │  └───────────────────────────┘                                    │
 ┌──────────────┐ Wi-Fi  │                                                                   │
 │ Điện thoại   │──HTTP──┼─► phone.php (trang trả lời)                                       │
 └──────────────┘        │                                                                   │
                         │  Trình duyệt giáo viên ─► dashboard, lobby, quiz_run (máy chiếu)  │
                         │  NetworkManager hotspot 10.42.0.1 · avahi (mDNS) · BlueZ           │
                         └───────────────────────────────────────────────────────────────────┘
```

### 2.1. Luồng một buổi quiz
1. Giáo viên tạo quiz và giao cho lớp, rồi bấm **Launch**. Hệ thống tạo phiên `WAITING` với **mã phòng 2 chữ số**.
2. Học sinh nhập **STT** (2 số) và **mã phòng** trên thiết bị. Thiết bị gọi `api/join.php`.
3. Phòng chờ (lobby) trên máy chiếu cập nhật danh sách "Đã vào / Chưa vào" theo thời gian thực.
4. Giáo viên bấm **Start**. Phiên chuyển sang `ACTIVE` và hiện câu 1.
5. Thiết bị hỏi `api/session_state.php` mỗi giây, thấy câu mới thì hiện số câu. Học sinh chọn A–D rồi bấm `#`, thiết bị gửi `api/answer.php`.
6. Màn hình giáo viên hiện số học sinh đã trả lời. Giáo viên bấm **Next** để sang câu tiếp, cuối cùng bấm **End**.
7. Phiên chuyển sang `ENDED`. Thiết bị hiện `En`, giáo viên xem kết quả.

### 2.2. Trạng thái phiên (`live_sessions.status`)
`WAITING` (phòng chờ, cho vào) → `ACTIVE` (đang hỏi, không cho vào mới trừ thiết bị vào lại) → `ENDED` (đã kết thúc, tính vào kết quả). Phiên `WAITING` có thể **huỷ** (xoá hẳn vì chưa hỏi câu nào).

---

## 3. Công nghệ sử dụng

| Thành phần | Công nghệ |
|---|---|
| Máy chủ web | Apache 2 trên Ubuntu, có HTTPS bằng chứng chỉ nội bộ |
| Backend | **PHP 8.3 thuần** (không framework), `mysqli` với prepared statement |
| Cơ sở dữ liệu | **MariaDB 10.11**, InnoDB, utf8mb4, 11 bảng |
| Frontend | HTML/CSS/JS thuần; Tabler Icons, SweetAlert2 (CDN); **three.js 0.149** cho nền 3D |
| Thiết bị học sinh | **ESP32** (Arduino core 3.3.12), FreeRTOS, `WiFi`, `HTTPClient`, `ESPmDNS`, `Preferences`, **NimBLE-Arduino 2.5.1** |
| Cầu nối Bluetooth | **Python 3.12**, `bleak 3.0.2` (BLE central), `dbus-fast` (GATT server qua BlueZ D-Bus) |
| Điện thoại | Trang web + **Web Bluetooth API**, **Service Worker**, **Web App Manifest** (PWA) |
| Mạng | NetworkManager hotspot (10.42.0.1), avahi (mDNS `.local`), BlueZ 5.72 |
| Công cụ | `arduino-cli`, OpenSSL (CA nội bộ), Puppeteer + Firefox (chụp màn hình kiểm thử) |

Quy mô mã nguồn (ước tính theo số dòng): PHP khoảng 14.900 dòng (trang + API + include), CSS/JS khoảng 13.800 dòng, firmware ESP32 836 dòng, cầu nối Python khoảng 390 dòng.

---

## 4. Cơ sở dữ liệu

File tạo bảng: `database/schema.sql`. Migration: `database/migrations/`.

| Bảng | Vai trò | Ghi chú |
|---|---|---|
| `teachers` | Tài khoản giáo viên | email duy nhất, `password_hash` (bcrypt) |
| `classes` | Lớp học | thuộc một giáo viên; tên lớp duy nhất theo giáo viên |
| `students` | Học sinh | `student_id` = **STT 2 chữ số** (mã nhập trên thiết bị), `mssv` = mã số sinh viên của trường, `name`; STT duy nhất trong lớp |
| `quizzes` | Bộ câu hỏi | thuộc một giáo viên |
| `questions` | Câu hỏi | `question_order` để sắp thứ tự |
| `question_options` | Lựa chọn A–D | `is_correct` đánh dấu đáp án đúng |
| `class_quizzes` | Giao quiz cho lớp | khoá chính ghép (class, quiz) |
| `devices` | Mã thiết bị 2 số | `status` `ACTIVE`/`INACTIVE`; dùng chung cho ESP32 và điện thoại |
| `live_sessions` | Phiên quiz trực tiếp | `join_code`, `status`, `current_question_id`, thời điểm tạo/bắt đầu/kết thúc |
| `device_sessions` | Thiết bị nào, học sinh nào vào phiên nào | duy nhất (phiên, thiết bị) và (phiên, học sinh); `device_id` **có thể NULL**: xoá thiết bị vẫn giữ kết quả |
| `responses` | Câu trả lời | duy nhất (phiên, câu, học sinh), nên **mỗi học sinh chỉ trả lời 1 lần mỗi câu** |

Ràng buộc đáng chú ý:
- Mọi khoá ngoại để `RESTRICT`, nên không thể xoá dữ liệu đang được tham chiếu một cách vô tình. Riêng `device_sessions.device_id` dùng `ON DELETE SET NULL` (migration `20261013_device_sessions_keep_history.sql`).
- Ràng buộc duy nhất ở tầng database là lớp bảo vệ cuối chống trả lời hai lần hay vào phòng hai lần, kể cả khi nhiều yêu cầu đến cùng lúc.

---

## 5. Tính năng web cho giáo viên

Điều hướng chính (menu ngang): **Dashboard · Classes · Quizzes · Results · Progress · Review · Devices · Account**.

### 5.1. Tài khoản (`register.php`, `login.php`, `account.php`, `logout.php`)
- Đăng ký: họ tên, email, mật khẩu ≥ 8 ký tự, xác nhận mật khẩu. Có CSRF token, đăng ký xong tự đăng nhập.
- Đăng nhập bằng email + mật khẩu (bcrypt `password_hash`/`password_verify`).
- Đổi mật khẩu: phải nhập mật khẩu hiện tại.
- Dữ liệu tách theo giáo viên: mọi truy vấn đều lọc theo `teacher_id` của phiên đăng nhập.

### 5.2. Dashboard (`dashboard.php`)
- Câu tóm tắt: "Bạn dạy N lớp với M học sinh, có K quiz sẵn sàng."
- **"Not ended yet" (Chưa kết thúc)**: liệt kê mọi phiên đang chờ hoặc đang chạy, kèm mã phòng, tên quiz, lớp, trạng thái, đang ở câu mấy/tổng, mở bao lâu, bao nhiêu học sinh đã vào.
  - **Continue / Open lobby**: quay lại đúng màn hình đang dở (phòng chờ hoặc màn hình chạy quiz).
  - **Cancel**: huỷ phiên đang chờ. Thiết bị đã vào phòng tự quay về màn hình nhập (`E8` rồi `HS`).
- Danh sách lớp, quiz và các phiên gần đây.
- **Nút "Quiz running" / "N quizzes waiting"** trên thanh trên cùng ở mọi trang (chấm đỏ nhấp nháy). Giáo viên lỡ đóng tab vẫn tìm lại được bài đang chạy.

### 5.3. Lớp và học sinh (`classes.php`, `class.php`, `add_class.php`, `edit_class.php`, `delete_class.php`, `students.php`, `add_student.php`, `edit_student.php`, `delete_student.php`, `delete_students.php`, `import_students.php`)
- Tạo, sửa, xoá lớp. Trang lớp có 4 tab: **Overview, Students, Quizzes, Results**.
- Học sinh có 3 thông tin: **STT** (2 chữ số, là mã nhập trên thiết bị), **MSSV**, **Họ tên**. Có tìm kiếm theo STT, MSSV hoặc tên.
- **Nhập danh sách lớp**: tải file CSV **hoặc dán thẳng từ Excel**, các cột STT · MSSV · Họ tên (dòng tiêu đề không bắt buộc).
  - Có **bước xem trước** kiểm tra từng dòng: báo STT sai định dạng, trùng STT, thiếu tên…
  - Lưu tất cả trong một lần.
- **Xoá hàng loạt** cả lớp. Học sinh đã có lịch sử làm bài được giữ lại, trừ khi giáo viên tích "xoá cả câu trả lời".
- Quy tắc học sinh dùng chung một chỗ (`includes/student_rules.php`): STT đúng 2 chữ số 00–99.

### 5.4. Quiz và câu hỏi (`quizzes.php`, `create_quiz.php`, `quiz.php`, `add_question.php`, `edit_question.php`, `delete_question.php`, `delete_quiz.php`, `save_quiz.php`, `import_quiz.php`, `assign_quiz.php`, `api/reorder_questions.php`)
- Tạo quiz, thêm câu hỏi 2–4 lựa chọn (A–D) và đánh dấu đáp án đúng.
- **Sắp xếp lại thứ tự câu hỏi** (API `reorder_questions.php`, có CSRF).
- **Nhập quiz từ CSV** với tiêu đề `Question,A,B,C,D,Correct`:
  - Có kiểm tra: phải đúng tiêu đề, tự bỏ BOM UTF-8, chỉ nhận file văn bản CSV.
  - Có bước xem trước trước khi lưu.
- **Giao quiz cho một hoặc nhiều lớp.**
- **Khoá chỉnh sửa** (`includes/quiz_lock.php`): quiz đã từng chạy thì không sửa nội dung được nữa, để kết quả cũ không bị sai lệch.
  - Bắt đầu phiên và sửa quiz cùng lấy một khoá hàng (`SELECT … FOR UPDATE`), nên hai việc này không thể chen vào nhau.

### 5.5. Chạy quiz trực tiếp (`launch_quiz.php`, `lobby.php`, `start_quiz.php`, `quiz_run.php`, `next_question.php`, `end_quiz.php`, `finish_quiz.php`, `cancel_session.php`, API `lobby_status.php`, `question_status.php`)
- **Launch**: chọn lớp, hệ thống cấp **mã phòng 2 chữ số ngẫu nhiên**.
  - Mã không trùng với phòng nào đang chờ hoặc đang chạy.
  - Việc cấp mã dùng khoá `GET_LOCK` của MariaDB (`includes/join_code.php`), nên hai giáo viên bấm cùng lúc không bị trùng mã.
- **Lobby (phòng chờ)** cho máy chiếu: mã phòng cỡ lớn, hai cột "Joined" và "Not joined yet", tự cập nhật bằng `api/lobby_status.php`.
  - Nền 3D có các **hạt sáng** tương ứng số học sinh: mỗi em vào phòng thì một hạt sáng lên.
- **Màn hình chạy quiz** (`quiz_run.php`):
  - Nội dung câu hỏi và 4 lựa chọn, tiến độ "câu x / n".
  - Số học sinh **đã trả lời / đã vào phòng**, cập nhật liên tục qua `api/question_status.php`.
  - Nút **Next Question** và **End Quiz** (có hộp xác nhận).
- Chuyển câu hoặc kết thúc làm thiết bị tự cập nhật trong vòng khoảng 1 giây.

### 5.6. Kết quả (`results.php`, `session_results.php`, `result_question.php`, `includes/results_data.php`)
- Danh sách **các phiên đã hoàn thành**: quiz, lớp, ngày, số người tham gia, điểm trung bình.
- **Ma trận hiệu suất (Performance Matrix)**: mỗi hàng là một học sinh, mỗi cột là một câu, ô đúng/sai/không trả lời, cột điểm.
  - Tiêu đề cột **tô màu theo độ khó** dựa trên tỉ lệ sai: rất thấp ≤20%, thấp ≤40%, trung bình ≤60%, cao ≤80%, rất cao; riêng câu chưa được hỏi có màu riêng.
- **Phân tích từng câu** (`result_question.php`): phân bố đáp án A/B/C/D, đáp án đúng, ai chọn gì.
- Cách tính điểm: số câu đúng / số câu **đã được hỏi** trong phiên. Các trang Kết quả, Tiến bộ và Ôn tập dùng chung một cách tính.

### 5.7. Tiến bộ (`progress.php`, `includes/progress_data.php`)
- Ô tổng quan: số phiên, điểm trung bình lớp, tỉ lệ có mặt, số học sinh cần chú ý.
- **Biểu đồ đường SVG** điểm trung bình lớp qua từng quiz, có tooltip và đường dóng khi rê chuột.
- Biểu đồ số học sinh tham gia từng phiên, **histogram** phân bố điểm theo khoảng.
- Bảng học sinh: **sparkline** điểm theo thời gian của từng em, trạng thái, bộ lọc "chỉ học sinh cần chú ý".
- Ngưỡng cảnh báo: điểm trung bình < 50% hoặc tỉ lệ có mặt < 50% (`PROGRESS_ATTENTION_SCORE`, `PROGRESS_ATTENTION_ATTENDANCE`).

### 5.8. Ôn tập (`review.php`, `includes/review_data.php`)
- Tìm **những câu cả lớp sai nhiều nhất** qua mọi phiên đã kết thúc (chỉ tính phiên có người tham gia).
- Mỗi câu có thanh xếp chồng đúng / sai / không trả lời và **đáp án sai được chọn nhiều nhất** (cho thấy hiểu lầm phổ biến).
- Giáo viên tích chọn câu rồi bấm tạo: hệ thống **sao chép câu hỏi và lựa chọn thành quiz ôn tập mới**, giao luôn cho lớp, sẵn sàng chạy.

### 5.9. Thiết bị (`devices.php`)
- Thêm một mã hoặc **một dải mã** (ví dụ 01–40), khoá hoặc mở khoá, xoá từng mã hoặc xoá hàng loạt.
- Không cho xoá mã đang ở trong phiên chờ hoặc đang chạy. Xoá mã vẫn **giữ kết quả cũ** nhờ `ON DELETE SET NULL`.
- **Mã QR** cho từng mã: điện thoại quét là mở `phone.php?device=XX` với mã máy điền sẵn. Hệ thống tự tìm IP LAN của máy chủ để tạo link.

### 5.10. Huỷ phiên đang chờ (`cancel_session.php`)
- Chỉ huỷ được phiên `WAITING`, trong transaction: xoá danh sách thiết bị đã vào rồi xoá phiên.
- Phiên đã bắt đầu thì bị từ chối, giáo viên phải kết thúc bình thường để giữ đáp án.

---

## 6. Thiết bị trả lời ESP32

Firmware: `~/Desktop/esp32project/sketch_sep27a/sketch_sep27a.ino`. Hướng dẫn chi tiết: `Code.md`.

### 6.1. Phần cứng
- **ESP32 DevKit** (2 nhân, Wi-Fi 2.4 GHz + Bluetooth LE).
- **Bàn phím ma trận 4×4**: `0–9`, `A–D`, `*`, `#`. Các cột nối chân chỉ đọc 34–39, có điện trở kéo lên 10 kΩ.
- **2 LED 7 đoạn** anode chung, quét luân phiên qua transistor PNP.
- **4 LED** cho đáp án A, B, C, D.

### 6.2. Cách học sinh dùng

| Màn hình | Ý nghĩa |
|---|---|
| `--` nhấp nháy | Đang kết nối Wi-Fi hoặc tìm máy chủ |
| `--.` nhấp nháy (chấm bên phải) | Đang ở Bluetooth, chờ laptop kết nối |
| `HS` | Nhập số thứ tự học sinh (2 số) rồi bấm `#` |
| `JC` | Nhập mã phòng (2 số) rồi bấm `#` |
| `--` đứng yên | Đã vào phòng, chờ giáo viên bấm Start |
| `01`, `02`… | Số câu hỏi đang mở. Chọn A–D (LED nhấp nháy = chưa gửi), bấm `#` để gửi (LED sáng đứng = đã gửi) |
| `En` | Quiz kết thúc, bấm phím bất kỳ để làm phòng mới |
| `E1`–`E9` | Mã lỗi (bảng dưới) |
| Dấu chấm LED phải | Đang dùng Bluetooth |

Nhập 2 chữ số **theo cặp**: đang đủ 2 số mà bấm tiếp thì bắt đầu cặp mới. `*` để xoá hoặc quay lại bước trước. **Bấm `*` hai lần nhanh** (trong 0,5 giây) để đổi Wi-Fi ↔ Bluetooth.

| Mã lỗi | Nghĩa |
|---|---|
| E1 | Không tới được máy chủ (giữ nguyên số đã nhập / đáp án đã chọn để thử lại) |
| E2 | Thiết bị chưa đăng ký hoặc bị khoá |
| E3 | Sai mã phòng |
| E4 | Phòng đã bắt đầu, không vào thêm được |
| E5 | STT không có trong lớp |
| E6 | Đã vào phòng rồi (thiết bị hoặc học sinh đã được dùng) |
| E7 | Câu này đã trả lời rồi |
| E8 | Không còn ở trong phòng (phòng bị huỷ…), tự về `HS` |
| E9 | Lỗi khác |

### 6.3. Kỹ thuật đáng chú ý trong firmware
- **Hai luồng song song (FreeRTOS)**:
  - `uiTask` chạy trên nhân 1, quét LED 7 đoạn mỗi 1 ms và bàn phím mỗi 5 ms. Màn hình không bao giờ nháy và không mất phím, kể cả khi đang chờ mạng.
  - `loop()` lo mạng và logic.
  - Hai luồng trao đổi phím qua hàng đợi `xQueue`.
- **Máy trạng thái**: `RESUME → INPUT_STUDENT → INPUT_CODE → WAITING → ACTIVE → ENDED`.
- **Lưu phiên vào flash** (`Preferences`): mất điện hay khởi động lại thì tự vào lại đúng phòng, đúng câu.
  - Máy chủ hỗ trợ **"vào lại"**: cùng thiết bị và cùng học sinh thì được nhận lại phòng kể cả khi quiz đã bắt đầu.
- **Tự tìm máy chủ** (`findServer()`), không cần ghi cứng IP. Thử lần lượt:
  1. Tên mDNS `buidinhkhoi-LOQ-15ARP9.local` (avahi trên laptop trả lời).
  2. IP tìm được lần trước, lưu trong flash.
  3. IP gateway Wi-Fi (khi ESP vào hotspot của chính laptop).
  4. IP dự phòng `10.42.0.1`.

  Mỗi IP được **gọi thử API** để chắc đúng là máy chủ quiz. Mất liên lạc 3 lần liên tiếp thì tự tìm lại.
- **Đọc JSON gọn nhẹ** không dùng thư viện (`jsonText`, `jsonNumber`), đủ cho các phản hồi nhỏ của API.
- **Bluetooth LE** (NimBLE):
  - ESP32 đóng vai thiết bị ngoại vi (peripheral) tên `quiz-esp-<mã>`, phát quảng bá một UUID dịch vụ riêng.
  - Yêu cầu được gửi dạng `"<số thứ tự>\n<path>\n<body>"`, phản hồi dạng `"<số thứ tự>\n1\n<JSON>"`.
  - Mọi lời gọi API đi qua một hàm `sendRequest()`, hàm này tự chọn HTTP hay BLE. Phần vào phòng, hỏi trạng thái và gửi đáp án không phân biệt đường truyền.
- Chương trình khoảng 1,32 MB, nạp với phân vùng **Huge APP** (3 MB).

### 6.4. API cho thiết bị (JSON)

| API | Phương thức | Vào | Ra |
|---|---|---|---|
| `api/join.php` | POST | `device_id`, `student_id`, `join_code` | `{"status":"OK","session_id":15}` (có `rejoined:true` khi vào lại) |
| `api/session_state.php` | GET | `device_id`, `session_id` | `WAITING` / `ACTIVE` + `question_id`, `question_order` / `ENDED` |
| `api/answer.php` | POST | `device_id`, `session_id`, `question_id`, `answer` (A–D) | `{"status":"OK"}` |

Mã lỗi trả về (`reason`):
- Thiết bị: `device_not_registered`, `device_inactive`, `device_not_joined`, `device_already_joined`.
- Phòng: `invalid_join_code`, `session_not_waiting`, `session_not_found`, `session_not_active`, `no_current_question`.
- Học sinh: `student_not_in_class`, `student_already_joined`.
- Câu hỏi và đáp án: `already_answered`, `stale_question` (giáo viên đã chuyển câu), `invalid_option`, `invalid_question`, `question_not_found`.
- Yêu cầu: `invalid_request`, `invalid_json`, `method_not_allowed`, `server_error`.

Quy tắc của `answer.php`:
- Chỉ nhận đáp án cho **đúng câu đang mở** của phiên đang chạy.
- Chỉ nhận lựa chọn có thật của câu đó.
- Mỗi học sinh một lần mỗi câu. Việc này được kiểm tra trong transaction có khoá hàng và có thêm ràng buộc duy nhất của database.

---

## 7. Trả lời bằng điện thoại

### 7.1. Trang trả lời (`phone.php`)
- Trang công khai, không cần đăng nhập, giao diện tối ưu cho điện thoại: màn hình số câu cỡ lớn và **4 nút A/B/C/D màu** cỡ lớn.
- Nhập mã máy (2 số, giáo viên cấp ở trang Devices, hoặc điền sẵn khi quét QR), STT và mã phòng.
- Dùng đúng 3 API như ESP32, hỏi trạng thái mỗi giây.
- **Nhớ phiên** trong `localStorage`: tải lại trang vẫn vào tiếp đúng phòng. Có nút "Rời phòng".
- Hỗ trợ giao diện tối (dark mode).

### 7.2. Bluetooth cho Android (Web Bluetooth)
- Trình duyệt chỉ đóng vai **central** (đi tìm thiết bị), nên laptop phải đóng vai **peripheral**:
  - Cầu nối đăng ký một **GATT service** với BlueZ qua D-Bus (`phone_server.py`).
  - Laptop phát quảng bá tên **"STEM Quiz"**.
- Trang có nút **"Dùng Bluetooth"**. Chrome hiện hộp chọn thiết bị, người dùng chọn "STEM Quiz".
- Yêu cầu được nén thành **lệnh ngắn vừa một gói BLE nhỏ nhất (20 byte)**, không cần thương lượng MTU:
  - `J,03,09,49`: vào phòng (máy 03, STT 09, mã 49).
  - `S,03,19`: hỏi trạng thái phiên 19.
  - `A,03,19,123,B`: gửi đáp án B cho câu 123.
- Cách trao đổi: điện thoại **ghi** lệnh, BlueZ chỉ báo ghi xong khi cầu nối đã gọi API xong, rồi điện thoại **đọc** lại JSON. Câu trả lời được lưu **riêng theo từng điện thoại** (theo đường dẫn thiết bị BlueZ), nên các máy không đọc nhầm của nhau.
- Các thao tác Bluetooth được xếp hàng tuần tự. Mất kết nối thì tự nối lại 3 lần; khi Bluetooth không dùng được, trang gửi qua Wi-Fi như bình thường.

### 7.3. HTTPS nội bộ và ứng dụng cài được (PWA)
- Web Bluetooth và Service Worker **bắt buộc HTTPS**. Hệ thống có sẵn bộ tạo chứng chỉ (`~/IoT/https/make-certs.sh`):
  - **CA riêng của lớp** có **Name Constraints**: chỉ ký được cho IP nội bộ (10/8, 172.16/12, 192.168/16, 127/8), `localhost` và `*.local`. Dù lộ khoá CA cũng không giả mạo được trang web thật trên Internet. Đã kiểm thử: chứng chỉ ký cho `google.com` bị từ chối.
  - Chứng chỉ Apache ghi mọi IP hiện tại của máy. IP đổi thì cấp lại, điện thoại không phải cài lại CA.
  - Script `install-https.sh` bật HTTPS cho Apache, cổng 80 vẫn chạy song song.
- `phone_setup.php` hướng dẫn từng bước cho học sinh: tải và cài chứng chỉ, mở bản https, thêm vào màn hình chính.
- **PWA**:
  - `phone.webmanifest` gồm tên, biểu tượng 4 ô màu A/B/C/D, chế độ toàn màn hình.
  - `phone-sw.js` (Service Worker, phạm vi chỉ `phone.php`) lưu sẵn trang để **mất Wi-Fi vẫn mở được**. Chiến lược là ưu tiên mạng, chờ tối đa 3 giây rồi dùng bản lưu. Không bao giờ lưu API.

---

## 8. Cầu nối Bluetooth (`~/Desktop/esp32project/ble_gateway/`)

Chạy: `bluetoothctl power on` rồi `./run.sh`.

- `ble_gateway.py`:
  - **Phía ESP32 (vai central)**: quét liên tục theo UUID dịch vụ quiz, kết nối từng ESP32 (BlueZ chỉ kết nối lần lượt nên có khoá), đăng ký nhận thông báo, đọc yêu cầu, gọi API qua HTTP `localhost`, ghi phản hồi. Tự kết nối lại khi ESP mất kết nối.
  - **Danh sách trắng**: chỉ chuyển tiếp `join.php`, `session_state.php`, `answer.php`. Bluetooth không gọi được trang nào khác.
  - Log gọn: các lần hỏi trạng thái mỗi giây chỉ được in khi kết quả thay đổi.
- `phone_server.py`: **phía điện thoại (vai peripheral)**. Đăng ký GATT application và quảng bá LE qua D-Bus của BlueZ, kiểm tra chặt cú pháp lệnh ngắn bằng biểu thức chính quy. Nếu Bluetooth bị tắt rồi bật lại hoặc BlueZ khởi động lại thì tự đăng ký lại.
- Phía PHP **không cần biết** thiết bị đi đường nào.
- Giới hạn: card Bluetooth giữ được khoảng 7–20 kết nối cùng lúc, nên Bluetooth là đường dự phòng chứ không thay Wi-Fi cho cả lớp. Tầm phủ khoảng 10–30 m.

---

## 9. Giao diện và trải nghiệm

### 9.1. Phong cách "STEM 3D" (mặc định)
- Nền là một cảnh **three.js** với 16 vật thể học đường dựng bằng hình khối: chồng sách, nguyên tử có electron quay, bóng đèn, mũ tốt nghiệp, máy trả lời (mô phỏng thiết bị ESP32 có phím bấm), bình thí nghiệm, Σ, π, bút chì, ê-ke và các khối lập phương, 20 mặt, xuyến, nón, 8 mặt, cầu.
- Vật thể **nhấp nhô và xoay chậm**, nghiêng theo chuột (parallax), trôi theo cuộn trang. Lần đầu vào trang chúng "bay vào" lần lượt.
- **Chuyển trang liền mạch**:
  - Trạng thái cảnh (đồng hồ chuyển động, vị trí, tốc độ, góc xoay) được chuyển sang trang sau qua `sessionStorage`.
  - Một **ảnh chụp cảnh** (WebP) được hiển thị ngay khi trang mới mở, trong lúc three.js đang tải, nên vật thể không bao giờ nháy mất.
  - Mỗi trang và tab làm cảnh lệch nhẹ. Vật thể trôi sang chỗ mới bằng **lò xo giảm chấn tới hạn** (critically damped spring) có giới hạn tốc độ, nên chuyển động tự nhiên, không giật.
- Màn hình trình chiếu (phòng chờ, chạy quiz) ở **chế độ yên tĩnh**: chỉ giữ vài vật thể ở góc để không rối mắt khi chiếu.
- Có **nút tạm dừng chuyển động** và tôn trọng tuỳ chọn hệ thống `prefers-reduced-motion`. Thiết bị yếu hoặc chế độ tiết kiệm dữ liệu thì giảm độ phân giải. Mất ngữ cảnh WebGL thì chuyển sang nền tĩnh.
- Chuyển trang dùng **View Transitions API** (cross-document): nội dung mờ chéo nhẹ.

### 9.2. Phong cách thay thế
- Thêm `?world_style=` vào URL để chọn **`stem`** (mặc định), **`anime`** hoặc **`vangogh`** (phong cảnh hồ nước vẽ bằng canvas). Lựa chọn được nhớ lại.
- Menu chính nằm trên "đường chân trời", có hình phản chiếu xuống mặt nước ở các phong cách hồ.

### 9.3. Thiết kế chung
- Hệ màu khai báo bằng biến CSS (token) trong `assets/css/world.css` và `theme.css`.
- Giao diện co giãn tới bề rộng điện thoại (đã kiểm tra 384–390 px không bị tràn ngang).
- Hộp thoại xác nhận (SweetAlert2), thông báo flash sau mỗi thao tác.

---

## 10. Bảo mật và toàn vẹn dữ liệu
- **CSRF token** trên mọi biểu mẫu thay đổi dữ liệu: đăng ký, đổi mật khẩu, thiết bị, huỷ phiên, sắp xếp câu hỏi…
- **Prepared statement** cho mọi truy vấn, nên không bị SQL injection. Mọi dữ liệu xuất ra HTML đều qua `htmlspecialchars`, nên không bị XSS.
- **Mật khẩu băm bcrypt**. Kiểm tra quyền sở hữu: mọi trang đều kiểm tra lớp, quiz hay phiên có thuộc giáo viên đang đăng nhập không.
- **Transaction + khoá hàng** ở các thao tác nhạy cảm: vào phòng, trả lời, bắt đầu phiên, huỷ phiên, xoá hàng loạt.
- **Khoá cấp mã phòng** (`GET_LOCK`) tránh trùng mã khi nhiều người bấm cùng lúc.
- **Kiểm tra đầu vào chặt** ở API: mã đúng 2 chữ số, đáp án A–D, JSON hợp lệ, đúng phương thức HTTP.
- Cầu nối Bluetooth chỉ chuyển tiếp danh sách trắng API. CA nội bộ có Name Constraints.
- Thao tác thay đổi cấu trúc database luôn có bản sao lưu trước (`~/IoT/backups/`).

---

## 11. Kiểm thử đã thực hiện
- **Database tạm** (MariaDB riêng, cổng 3399, nạp từ bản dump) để thử mọi thay đổi trước khi đụng dữ liệu thật.
- **Luồng đầy đủ**: đăng ký → tạo lớp, học sinh, quiz → giao → mở phòng → thiết bị vào → bắt đầu → trả lời (lần hai bị chặn) → kết thúc → kết quả.
- **Kiểm thử giao diện tự động** bằng Puppeteer + Firefox:
  - Chụp màn hình desktop và điện thoại, kiểm tra không tràn ngang.
  - Chụp chuỗi khung hình khi chuyển trang để đo độ giật của vật thể 3D. Quãng di chuyển giảm từ 2,03 xuống ≤ 0,16 đơn vị màn hình.
- **Web Bluetooth giả lập** trong trình duyệt để thử luồng Bluetooth của `phone.php`: nối, gửi lệnh, nhận lỗi, ngắt.
- **Cầu nối**: thử chuyển tiếp với API thật (chỉ các yêu cầu chỉ đọc), chặn đường dẫn lạ, xử lý khi máy chủ tắt. Đăng ký GATT/quảng bá thành công với BlueZ thật.
- **Chạy thật với ESP32**:
  - ESP32 vào phòng, trả lời 2 câu và nhận "kết thúc" hoàn toàn qua Bluetooth (log Apache ghi 33 yêu cầu từ cầu nối).
  - ESP32 tự tìm máy chủ sau nhiều lần đổi IP.
- **Chứng chỉ**: kiểm tra chuỗi hợp lệ cho các IP nội bộ, và Name Constraints từ chối tên miền ngoài.

---

## 12. Triển khai và vận hành

```bash
# Máy chủ: Apache + PHP 8.3 + MariaDB, app ở /var/www/html/iot
sudo mysql iot < database/schema.sql              # tạo bảng (lần đầu)

# Wi-Fi cho lớp: hotspot của laptop (10.42.0.1)
nmcli con up Hotspot

# Nạp firmware ESP32 (mỗi máy một DEVICE_ID khác nhau)
arduino-cli compile --upload -p /dev/ttyUSB0 \
  --fqbn esp32:esp32:esp32:PartitionScheme=huge_app ~/Desktop/esp32project/sketch_sep27a

# Bluetooth dự phòng (ESP32 + điện thoại Android)
bluetoothctl power on
~/Desktop/esp32project/ble_gateway/run.sh

# HTTPS cho điện thoại (một lần, hoặc khi IP đổi)
~/IoT/https/make-certs.sh && sudo ~/IoT/https/install-https.sh
```

Trước buổi học: thêm mã thiết bị ở trang **Devices**, nhập danh sách lớp ở **Import Students**, tạo hoặc nhập quiz và giao cho lớp.

---

## 13. Giới hạn hiện tại và hướng phát triển

**Giới hạn**
- Thiết bị xác thực chỉ bằng mã 2 chữ số, HTTP và BLE không mã hoá. Hệ thống chỉ nên dùng trong mạng LAN của lớp.
- Mã 2 chữ số giới hạn tối đa 100 thiết bị và 100 học sinh mỗi lớp.
- Không giới hạn thời gian trả lời: câu còn mở thì vẫn gửi được.
- Bluetooth chỉ phục vụ được vài thiết bị cùng lúc. Điện thoại dùng Bluetooth chỉ chạy trên Android + Chrome (iPhone không có Web Bluetooth).
- Tên và mật khẩu Wi-Fi ghi cứng trong firmware. Đổi Wi-Fi phải nạp lại.
- Chưa có "quên mật khẩu". Ai vào được trang web cũng đăng ký được tài khoản giáo viên.
- Xoá lớp đã có phiên quiz bị chặn bởi khoá ngoại (cần xử lý riêng).
- Tải icon, SweetAlert2 và three.js từ CDN, nên lần đầu cần Internet (sau đó trình duyệt lưu đệm).

**Hướng phát triển**
- Đếm ngược thời gian mỗi câu, tự chuyển câu, tính điểm theo tốc độ trả lời, bảng xếp hạng kiểu Kahoot.
- Cho học sinh rời phòng khi đang chờ (phím trên ESP + API rời phòng).
- Cấu hình Wi-Fi cho ESP qua web hoặc chế độ phát AP cấu hình (WiFiManager), cập nhật firmware qua mạng (OTA).
- Xuất kết quả ra Excel/PDF, gửi báo cáo cho phụ huynh.
- Câu hỏi có hình ảnh, công thức toán (LaTeX), nhiều đáp án đúng.
- Mã hoá và xác thực thiết bị (khoá riêng từng thiết bị, BLE pairing).
- Thiết bị chạy pin với chế độ ngủ tiết kiệm năng lượng (lợi thế của BLE).

---

## 14. Danh mục file chính

**Trang web (gốc `/var/www/html/iot`)**

| Nhóm | File |
|---|---|
| Tài khoản | `register.php`, `login.php`, `logout.php`, `account.php` |
| Tổng quan | `dashboard.php`, `cancel_session.php` |
| Lớp và học sinh | `classes.php`, `class.php`, `add_class.php`, `edit_class.php`, `delete_class.php`, `students.php`, `add_student.php`, `edit_student.php`, `delete_student.php`, `delete_students.php`, `import_students.php` |
| Quiz | `quizzes.php`, `create_quiz.php`, `quiz.php`, `add_question.php`, `edit_question.php`, `delete_question.php`, `delete_quiz.php`, `save_quiz.php`, `import_quiz.php`, `assign_quiz.php` |
| Chạy quiz | `launch_quiz.php`, `lobby.php`, `start_quiz.php`, `quiz_run.php`, `next_question.php`, `end_quiz.php`, `finish_quiz.php` |
| Kết quả và phân tích | `results.php`, `session_results.php`, `result_question.php`, `progress.php`, `review.php` |
| Thiết bị và điện thoại | `devices.php`, `phone.php`, `phone_setup.php`, `phone-sw.js`, `phone.webmanifest`, `stem-quiz-ca.crt` |
| API | `api/join.php`, `api/session_state.php`, `api/answer.php`, `api/lobby_status.php`, `api/question_status.php`, `api/reorder_questions.php` |
| Dùng chung | `includes/auth.php`, `layout_start.php`, `layout_end.php`, `topbar.php`, `sidebar.php`, `places.php`, `flash.php`, `wordmark.php`, `join_code.php`, `quiz_lock.php`, `student_rules.php`, `live_sessions.php`, `results_data.php`, `progress_data.php`, `review_data.php` |
| Giao diện | `assets/css/world.css`, `theme.css` (+ CSS từng trang), `assets/js/stem-world.js`, `world.js`, `world-style-anime.js`, `world-style-vangogh.js`, `motion.js`, `ui.js` |
| Database | `database/schema.sql`, `database/migrations/*.sql` |
| Tài liệu | `Code.md` (firmware ESP32), `TOMTAT.md` (triển khai ban đầu), `TONG_QUAN_DU_AN.md` (tài liệu này) |

**Ngoài thư mục web**

| File | Vai trò |
|---|---|
| `~/Desktop/esp32project/sketch_sep27a/sketch_sep27a.ino` | Firmware ESP32 |
| `~/Desktop/esp32project/ble_gateway/ble_gateway.py`, `phone_server.py`, `run.sh` | Cầu nối Bluetooth |
| `~/IoT/https/make-certs.sh`, `install-https.sh` | CA nội bộ và bật HTTPS |
| `~/IoT/backups/` | Bản sao lưu database, firmware và file trước mỗi thay đổi lớn |

---

## 15. Gợi ý bố cục báo cáo
1. Đặt vấn đề và mục tiêu (mục 1)
2. Cơ sở lý thuyết:
   - Mô hình client–server, REST/JSON.
   - Vi điều khiển ESP32, FreeRTOS.
   - Wi-Fi, mDNS.
   - Bluetooth Low Energy (GAP, GATT, vai central/peripheral, quảng bá).
   - Web Bluetooth, PWA, HTTPS/PKI.
3. Phân tích và thiết kế hệ thống: kiến trúc (mục 2), cơ sở dữ liệu (mục 4), API (mục 6.4), máy trạng thái thiết bị (mục 6.3)
4. Thiết kế phần cứng thiết bị trả lời (mục 6.1)
5. Hiện thực:
   - Web giáo viên (mục 5).
   - Firmware (mục 6).
   - Điện thoại (mục 7).
   - Cầu nối Bluetooth (mục 8).
   - Giao diện 3D (mục 9).
6. Bảo mật (mục 10)
7. Kiểm thử và đánh giá (mục 11)
8. Kết luận, hạn chế, hướng phát triển (mục 13)
