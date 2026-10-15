# Sơ đồ hệ thống STEM Quiz

> Các sơ đồ viết bằng Mermaid (hiển thị được trên GitHub, VS Code, Typora, Obsidian…).
> Bản ảnh PNG để chèn vào báo cáo nằm trong `docs/diagrams/`.
> Mô tả chi tiết từng thành phần: `TONG_QUAN_DU_AN.md`.

## Sơ đồ tổng quan (bản gọn, để xem nhanh)

![Kiến trúc tổng quan hệ thống STEM Quiz](diagrams/00-kien-truc-tong-quan.png)

- **Giáo viên** dùng website trên laptop để quản lý lớp, câu hỏi, chạy quiz và xem kết quả.
- **Học sinh** trả lời bằng **thiết bị ESP32** hoặc **điện thoại**. Đường chính là Wi-Fi gọi API JSON; Bluetooth là đường dự phòng, đi qua một cầu nối trên laptop rồi cũng gọi đúng API đó.
- Laptop giáo viên chạy cả web server (Apache + PHP), cầu nối Bluetooth và database **MariaDB**, nên không cần Internet trong giờ học.

---

Danh sách sơ đồ chi tiết:
0. Kiến trúc tổng thể chi tiết (database → máy chủ → đường truyền → client)
1. Sơ đồ kiến trúc tổng thể
2. Sơ đồ triển khai mạng (Wi-Fi / Bluetooth)
3. Sơ đồ phân tầng phần mềm phía máy chủ
4. Sơ đồ cơ sở dữ liệu (ERD)
5. Sơ đồ tuần tự một buổi quiz
6. Sơ đồ tuần tự đường Bluetooth
7. Sơ đồ trạng thái phiên quiz
8. Sơ đồ trạng thái thiết bị ESP32
9. Thuật toán tự tìm máy chủ của ESP32

---

## 0. Kiến trúc tổng thể chi tiết

Sơ đồ đi từ dưới lên: **tầng dữ liệu → tầng máy chủ (web server, ứng dụng PHP, cầu nối Bluetooth) → tầng đường truyền → tầng client**. Các số ① ② … trên mũi tên ứng với bảng luồng dữ liệu bên dưới.

![Kiến trúc tổng thể hệ thống STEM Quiz](diagrams/00-kien-truc-tong-the-chi-tiet.png)

Ảnh vector (phóng to không vỡ, chèn vào Word được): `diagrams/00-kien-truc-tong-the-chi-tiet.svg`.

Đọc từ trên xuống:
- **Tầng client** có 3 loại:
  - Trình duyệt giáo viên: quản lý, trình chiếu, nền 3D.
  - Điện thoại học sinh: `phone.php`, Web Bluetooth, bộ nhớ đệm offline.
  - Thiết bị ESP32: luồng giao diện FreeRTOS, máy trạng thái, tìm máy chủ, bộ nhớ flash.
- **Tầng máy chủ:**
  - Apache nhận mọi yêu cầu HTTP/HTTPS.
  - Cầu nối Bluetooth nhận yêu cầu BLE rồi gọi lại Apache qua localhost.
  - Ứng dụng PHP chia 3 nhóm: trang giáo viên, API JSON, trang học sinh và tài nguyên. Cả 3 nhóm cùng dùng thư viện `includes/`.
  - Chỉ `config/db.php` nói chuyện với database.
- **Tầng dữ liệu:** MariaDB có 11 bảng, chia 5 nhóm.

### 0.1. Bảng luồng dữ liệu

| Số | Từ → đến | Giao thức | Nội dung |
|---|---|---|---|
| ① | Ứng dụng PHP → MariaDB | mysqli, prepared statement | Đọc/ghi mọi dữ liệu. Thao tác nhạy cảm (vào phòng, trả lời, bắt đầu/huỷ phiên) chạy trong transaction có `SELECT … FOR UPDATE`; cấp mã phòng dùng `GET_LOCK` |
| ② | Cầu nối Bluetooth → Apache | HTTP `127.0.0.1` | Chuyển yêu cầu nhận qua Bluetooth thành đúng lời gọi API như thiết bị Wi-Fi; danh sách trắng chỉ gồm `join`, `session_state`, `answer` |
| ③ | Client → Apache | HTTP :80 / HTTPS :443 | Trang HTML cho giáo viên, JSON cho thiết bị và màn hình trình chiếu, tài nguyên tĩnh |
| ④ | Trình duyệt giáo viên → trang web | HTTP, form POST + CSRF | Quản lý lớp, học sinh, quiz, thiết bị; nhập CSV/Excel; Launch/Start/Next/End |
| ⑤ | Lobby / quiz_run → API trực tiếp | HTTP GET JSON, khoảng 1 giây | Ai đã vào phòng, bao nhiêu em đã trả lời câu hiện tại |
| ⑥ | ESP32 → API thiết bị | Wi-Fi, HTTP + JSON | Vào phòng, hỏi trạng thái mỗi giây, gửi đáp án |
| ⑦ | ESP32 ↔ cầu nối | BLE GATT (ESP là peripheral) | Cùng nội dung ⑥, đóng gói `"<seq>\n<path>\n<body>"` |
| ⑧ | ESP32 → avahi | mDNS | Tìm IP máy chủ theo tên `*.local`, không cần ghi cứng IP |
| ⑨ | Điện thoại → API thiết bị | Wi-Fi, HTTP(S) + JSON | Giống ⑥, từ trang `phone.php` |
| ⑩ | Điện thoại ↔ cầu nối | BLE GATT (laptop là peripheral) | Lệnh ngắn `J,03,09,49` / `S,03,19` / `A,03,19,123,B`, ghi rồi đọc lại JSON |

### 0.2. Dữ liệu được lưu ở đâu

| Nơi lưu | Dữ liệu | Thời hạn |
|---|---|---|
| MariaDB | Toàn bộ dữ liệu nghiệp vụ (11 bảng) | Lâu dài, là nguồn sự thật duy nhất |
| Phiên PHP (máy chủ) | `teacher_id` đăng nhập, CSRF token | Đến khi đăng xuất / hết phiên |
| Flash ESP32 (`Preferences`) | `session` đang làm, IP máy chủ tìm được | Qua mất điện, khởi động lại |
| `localStorage` điện thoại | Mã máy, STT, phiên đang làm | Qua tải lại trang |
| Service Worker cache | Bản sao `phone.php`, manifest, icon | Để mở được khi mất Wi-Fi |
| `sessionStorage` trình duyệt giáo viên | Trạng thái và ảnh chụp cảnh 3D | Trong một tab, để chuyển trang liền mạch |
| `localStorage` trình duyệt giáo viên | Kiểu giao diện, tạm dừng chuyển động | Lâu dài trên máy đó |

---

## 1. Sơ đồ kiến trúc tổng thể

Hệ thống gồm 3 nhóm người dùng và thiết bị (giáo viên, học sinh dùng ESP32, học sinh dùng điện thoại) cùng nói chuyện với một máy chủ duy nhất là laptop giáo viên.

```mermaid
flowchart LR
    subgraph HS["Học sinh"]
        ESP["Thiết bị ESP32<br/>bàn phím 4x4 · LED 7 đoạn · LED A-D"]
        PHONE["Điện thoại<br/>phone.php (PWA)"]
    end

    subgraph GV["Giáo viên"]
        BROWSER["Trình duyệt<br/>Dashboard · Lobby · Quiz run (máy chiếu)"]
    end

    subgraph SERVER["Máy chủ: laptop giáo viên"]
        APACHE["Apache 2<br/>HTTP :80 · HTTPS :443"]
        subgraph PHP["Ứng dụng PHP 8.3"]
            PAGES["Trang web giáo viên<br/>(*.php)"]
            API["API JSON cho thiết bị<br/>api/join · session_state · answer"]
            LIVEAPI["API cập nhật trực tiếp<br/>api/lobby_status · question_status"]
        end
        DB[("MariaDB 10.11<br/>database iot · 11 bảng")]
        GW["Cầu nối Bluetooth<br/>ble_gateway.py + phone_server.py"]
    end

    ESP -- "Wi-Fi · HTTP/JSON" --> APACHE
    PHONE -- "Wi-Fi · HTTP(S)/JSON" --> APACHE
    BROWSER -- "HTTP(S) · HTML" --> APACHE
    ESP -. "BLE (dự phòng, bấm **)" .-> GW
    PHONE -. "BLE · Web Bluetooth (Android)" .-> GW
    GW -- "HTTP localhost" --> API
    APACHE --> PAGES
    APACHE --> API
    APACHE --> LIVEAPI
    PAGES --> DB
    API --> DB
    LIVEAPI --> DB
```

Điểm chính:
- **Một nguồn sự thật duy nhất là database**. Thiết bị đi đường nào (Wi-Fi hay Bluetooth) cuối cùng cũng gọi cùng 3 API, nên logic nghiệp vụ chỉ nằm ở một chỗ.
- Cầu nối Bluetooth chỉ là **bộ chuyển đổi giao thức** (BLE ↔ HTTP), không chứa logic nghiệp vụ.

---

## 2. Sơ đồ triển khai mạng

```mermaid
flowchart TB
    subgraph LAPTOP["Laptop giáo viên (Ubuntu)"]
        direction TB
        WLAN["Card Wi-Fi wlo1<br/>Hotspot 10.42.0.1<br/>(NetworkManager)"]
        BT["Card Bluetooth (Realtek)<br/>BlueZ 5.72<br/>central + peripheral"]
        AVAHI["avahi-daemon<br/>tên mDNS: buidinhkhoi-LOQ-15ARP9.local"]
        WEB["Apache + PHP + MariaDB"]
        GWP["ble_gateway.py"]
    end

    E1["ESP32 #01<br/>10.42.0.x"]
    E2["ESP32 #02<br/>10.42.0.x"]
    P1["Điện thoại<br/>10.42.0.x"]
    E3["ESP32 #07<br/>(chế độ Bluetooth)"]
    P2["Điện thoại Android<br/>(chế độ Bluetooth)"]
    PROJ["Máy chiếu<br/>(màn hình laptop)"]

    E1 -- "Wi-Fi 2.4 GHz" --> WLAN
    E2 -- "Wi-Fi 2.4 GHz" --> WLAN
    P1 -- "Wi-Fi" --> WLAN
    E3 -. "BLE: ESP quảng bá quiz-esp-07,<br/>laptop quét và kết nối" .-> BT
    P2 -. "BLE: laptop quảng bá 'STEM Quiz',<br/>điện thoại kết nối" .-> BT
    WLAN --> WEB
    AVAHI -. "trả lời truy vấn mDNS" .-> E1
    BT --> GWP --> WEB
    WEB --> PROJ
```

- Không cần Internet trong giờ học: laptop **tự phát Wi-Fi**, tự làm máy chủ web và trạm Bluetooth.
- Hai chiều Bluetooth **ngược vai nhau**:
  - Với ESP32, laptop là **central** (đi tìm và kết nối).
  - Với điện thoại, laptop là **peripheral** (phát quảng bá cho trình duyệt tìm), vì trình duyệt chỉ làm được central.

---

## 3. Sơ đồ phân tầng phần mềm phía máy chủ

```mermaid
flowchart TB
    subgraph L1["Tầng giao diện"]
        UI1["Trang quản lý<br/>classes · quizzes · devices · account"]
        UI2["Trang trình chiếu<br/>lobby · quiz_run"]
        UI3["Trang phân tích<br/>results · progress · review"]
        UI4["Trang học sinh<br/>phone.php · phone_setup.php"]
        FX["Nền 3D three.js<br/>stem-world.js · world.css"]
    end

    subgraph L2["Tầng xử lý (PHP)"]
        ACT["Xử lý thao tác<br/>add_* · edit_* · delete_* · import_* ·<br/>launch/start/next/end/cancel"]
        APIL["API JSON<br/>join · session_state · answer ·<br/>lobby_status · question_status · reorder_questions"]
        INC["Thư viện dùng chung (includes/)<br/>auth · join_code · quiz_lock · student_rules ·<br/>results_data · progress_data · review_data · live_sessions"]
    end

    subgraph L3["Tầng dữ liệu"]
        MDB[("MariaDB · InnoDB · utf8mb4<br/>transaction · khoá hàng · khoá ngoại")]
    end

    UI1 --> ACT
    UI2 --> APIL
    UI3 --> INC
    UI4 --> APIL
    ACT --> INC
    APIL --> INC
    INC --> MDB
    ACT --> MDB
    APIL --> MDB
```

---

## 4. Sơ đồ cơ sở dữ liệu (ERD)

```mermaid
erDiagram
    teachers ||--o{ classes : "sở hữu"
    teachers ||--o{ quizzes : "soạn"
    classes ||--o{ students : "gồm"
    quizzes ||--o{ questions : "gồm"
    questions ||--o{ question_options : "có lựa chọn"
    classes ||--o{ class_quizzes : "được giao"
    quizzes ||--o{ class_quizzes : "giao cho"
    quizzes ||--o{ live_sessions : "được chạy"
    classes |o--o{ live_sessions : "cho lớp"
    questions |o..o{ live_sessions : "câu đang mở (không FK)"
    live_sessions ||--o{ device_sessions : "có người vào"
    devices |o--o{ device_sessions : "thiết bị dùng (NULL khi xoá)"
    students ||--o{ device_sessions : "học sinh vào"
    live_sessions ||--o{ responses : "thu đáp án"
    questions ||--o{ responses : "cho câu"
    students ||--o{ responses : "của học sinh"

    teachers {
        int teacher_id PK
        varchar name
        varchar email UK
        varchar password_hash "bcrypt"
        datetime created_at
    }
    classes {
        int class_id PK
        int teacher_id FK
        varchar name "UK cùng teacher_id"
        datetime created_at
    }
    students {
        int id PK
        int class_id FK
        varchar student_id "STT 2 số, UK cùng class_id"
        varchar mssv "mã số SV, có thể NULL"
        varchar name
    }
    quizzes {
        int quiz_id PK
        int teacher_id FK
        varchar title
        datetime created_at
    }
    questions {
        int question_id PK
        int quiz_id FK
        int question_order
        text content
    }
    question_options {
        int option_id PK
        int question_id FK
        int option_order "1-4 = A-D, UK cùng question_id"
        text content
        tinyint is_correct
    }
    class_quizzes {
        int class_id PK,FK
        int quiz_id PK,FK
    }
    devices {
        int device_id PK
        char device_code UK "2 số"
        varchar status "ACTIVE / INACTIVE"
    }
    live_sessions {
        int session_id PK
        int quiz_id FK
        int class_id FK "có thể NULL"
        char join_code "mã phòng 2 số"
        enum status "WAITING / ACTIVE / ENDED"
        int current_question_id
        datetime started_at
        datetime ended_at
        datetime created_at
    }
    device_sessions {
        int device_session_id PK
        int session_id FK
        int device_id FK "NULL khi xoá thiết bị"
        int student_pk FK
        datetime joined_at
    }
    responses {
        int response_id PK
        int session_id FK
        int question_id FK
        int student_pk FK
        char answer "A-D"
        datetime answered_at
    }
```

### 4.1. Nhóm bảng
| Nhóm | Bảng | Ý nghĩa |
|---|---|---|
| Người dùng | `teachers` | Tài khoản giáo viên; mọi dữ liệu khác tách theo giáo viên |
| Lớp học | `classes`, `students` | Lớp và danh sách học sinh (STT · MSSV · Họ tên) |
| Ngân hàng câu hỏi | `quizzes`, `questions`, `question_options`, `class_quizzes` | Bộ câu hỏi, câu hỏi, lựa chọn A–D, quiz nào giao cho lớp nào |
| Thiết bị | `devices` | Mã 2 số của ESP32 hoặc điện thoại, dùng chung cho mọi giáo viên |
| Phiên trực tiếp | `live_sessions`, `device_sessions`, `responses` | Mỗi lần chạy quiz; ai vào bằng thiết bị nào; ai trả lời gì |

### 4.2. Ràng buộc quan trọng
| Ràng buộc | Bảng | Tác dụng |
|---|---|---|
| `UNIQUE (session_id, question_id, student_pk)` | `responses` | Mỗi học sinh chỉ trả lời **1 lần mỗi câu**, kể cả khi 2 yêu cầu đến cùng lúc |
| `UNIQUE (session_id, device_id)` | `device_sessions` | Một thiết bị chỉ vào một phòng một lần |
| `UNIQUE (session_id, student_pk)` | `device_sessions` | Một học sinh chỉ vào phòng bằng một thiết bị |
| `UNIQUE (class_id, student_id)` | `students` | STT không trùng trong lớp |
| `UNIQUE (question_id, option_order)` | `question_options` | Mỗi câu chỉ có một A, một B… |
| `UNIQUE device_code` | `devices` | Mã thiết bị không trùng |
| `INDEX (join_code, status)` | `live_sessions` | Tìm nhanh phòng đang mở theo mã phòng khi thiết bị vào |
| Khoá ngoại `RESTRICT` | mọi bảng | Không xoá được dữ liệu đang được dùng (ví dụ quiz đã có kết quả) |
| `ON DELETE SET NULL` | `device_sessions.device_id` | Xoá mã thiết bị **vẫn giữ kết quả** cũ của học sinh |

### 4.3. Cách tính điểm từ dữ liệu
- **Câu đã được hỏi** trong một phiên: các câu có `question_order` ≤ thứ tự của câu cuối cùng được mở.
- **Điểm học sinh** = số `responses` đúng (đáp án trùng `question_options.is_correct = 1`) / số câu đã được hỏi.
- Chỉ tính phiên `ENDED` có ít nhất một học sinh tham gia. Trang Kết quả, Tiến bộ và Ôn tập dùng chung cách tính này.

---

## 5. Sơ đồ tuần tự một buổi quiz (qua Wi-Fi)

```mermaid
sequenceDiagram
    autonumber
    actor GV as Giáo viên
    participant WEB as Web (PHP)
    participant DB as MariaDB
    participant ESP as ESP32 / điện thoại
    actor HS as Học sinh

    GV->>WEB: Launch quiz cho lớp 10A1
    WEB->>DB: Cấp mã phòng ngẫu nhiên (GET_LOCK), INSERT live_sessions (WAITING)
    WEB-->>GV: Phòng chờ, mã phòng 49

    HS->>ESP: Nhập STT 05 + mã phòng 49, bấm #
    ESP->>WEB: POST api/join.php {device_id, student_id, join_code}
    WEB->>DB: Kiểm tra thiết bị, phòng, học sinh → INSERT device_sessions
    WEB-->>ESP: {"status":"OK","session_id":19}
    loop Mỗi giây (lobby)
        GV->>WEB: GET api/lobby_status.php
        WEB-->>GV: Danh sách đã vào / chưa vào
    end

    GV->>WEB: Start
    WEB->>DB: status = ACTIVE, current_question_id = câu 1
    loop Mỗi giây
        ESP->>WEB: GET api/session_state.php
        WEB-->>ESP: {"status":"ACTIVE","question_order":1, ...}
    end
    HS->>ESP: Chọn B, bấm #
    ESP->>WEB: POST api/answer.php {question_id, answer:"B"}
    WEB->>DB: Khoá hàng, kiểm tra đúng câu đang mở → INSERT responses
    WEB-->>ESP: {"status":"OK"} (LED B sáng đứng)
    GV->>WEB: GET api/question_status.php
    WEB-->>GV: Đã trả lời 24/30

    GV->>WEB: Next Question … End Quiz
    WEB->>DB: status = ENDED
    ESP->>WEB: GET api/session_state.php
    WEB-->>ESP: {"status":"ENDED"} (màn hình hiện En)
    GV->>WEB: Xem kết quả, ma trận, phân tích câu
```

---

## 6. Sơ đồ tuần tự đường Bluetooth

### 6.1. ESP32 qua Bluetooth (laptop là central)
```mermaid
sequenceDiagram
    autonumber
    participant ESP as ESP32 (peripheral)
    participant GW as ble_gateway.py (central)
    participant API as API PHP

    Note over ESP: Học sinh bấm ** → tắt Wi-Fi, bật BLE
    ESP-->>GW: Quảng bá "quiz-esp-01" + UUID dịch vụ quiz
    GW->>ESP: Quét thấy → kết nối, đăng ký notify
    Note over ESP: Màn hình hết nhấp nháy, chấm bên phải sáng
    ESP->>ESP: Ghi "7\nanswer.php\n{...}" vào characteristic yêu cầu
    ESP-->>GW: notify (báo có yêu cầu)
    GW->>ESP: Đọc yêu cầu đầy đủ
    GW->>API: POST http://127.0.0.1/iot/api/answer.php
    API-->>GW: {"status":"OK"}
    GW->>ESP: Ghi "7\n1\n{"status":"OK"}" vào characteristic trả lời
    Note over ESP: Khớp số thứ tự 7 → xử lý như HTTP
```

### 6.2. Điện thoại Android qua Bluetooth (laptop là peripheral)
```mermaid
sequenceDiagram
    autonumber
    participant PH as phone.php (Chrome, central)
    participant GW as phone_server.py (peripheral, BlueZ)
    participant API as API PHP

    GW-->>PH: Quảng bá "STEM Quiz"
    PH->>GW: Người dùng bấm "Dùng Bluetooth", chọn STEM Quiz → kết nối GATT
    PH->>GW: writeValue("A,03,19,123,B")
    GW->>API: POST answer.php {device_id:"03", session_id:19, question_id:123, answer:"B"}
    API-->>GW: {"status":"OK"}
    GW-->>PH: Ghi xong (BlueZ chờ API trả lời mới xác nhận)
    PH->>GW: readValue()
    GW-->>PH: {"status":"OK"} (trả lời riêng cho điện thoại này)
```

Lệnh ngắn của điện thoại (vừa một gói BLE 20 byte):

| Lệnh | API tương ứng |
|---|---|
| `J,<máy>,<STT>,<mã phòng>` | `join.php` |
| `S,<máy>,<session_id>` | `session_state.php` |
| `A,<máy>,<session_id>,<question_id>,<A-D>` | `answer.php` |

---

## 7. Sơ đồ trạng thái phiên quiz (`live_sessions.status`)

```mermaid
stateDiagram-v2
    [*] --> WAITING: Launch (cấp mã phòng)
    WAITING --> WAITING: Học sinh vào phòng (join)
    WAITING --> [*]: Cancel (xoá phiên, thiết bị về HS)
    WAITING --> ACTIVE: Start (mở câu 1)
    ACTIVE --> ACTIVE: Next Question / thu đáp án
    ACTIVE --> ENDED: End Quiz
    ENDED --> [*]: Tính vào Kết quả · Tiến bộ · Ôn tập
```

---

## 8. Sơ đồ trạng thái thiết bị ESP32

```mermaid
stateDiagram-v2
    [*] --> RESUME: Bật máy, flash còn phiên
    [*] --> INPUT_STUDENT: Bật máy, không có phiên
    RESUME --> WAITING: Server báo WAITING
    RESUME --> ACTIVE: Server báo ACTIVE
    RESUME --> INPUT_STUDENT: Phím * hoặc E8 (phiên không còn)
    INPUT_STUDENT --> INPUT_CODE: Nhập STT + #  (màn hình HS)
    INPUT_CODE --> INPUT_STUDENT: Phím * khi ô trống
    INPUT_CODE --> WAITING: Nhập mã phòng + #, join OK  (màn hình JC)
    INPUT_CODE --> INPUT_CODE: E3 sai mã · E4 · E6
    WAITING --> ACTIVE: Giáo viên Start
    ACTIVE --> ACTIVE: Chọn A-D, # gửi · câu mới
    ACTIVE --> ENDED: Giáo viên End
    WAITING --> INPUT_STUDENT: E8 phòng bị huỷ
    ENDED --> INPUT_STUDENT: Bấm phím bất kỳ
    note right of ACTIVE
        Bấm ** ở mọi trạng thái:
        đổi Wi-Fi <-> Bluetooth,
        giữ nguyên phiên
    end note
```

Kiến trúc bên trong firmware:

```mermaid
flowchart LR
    subgraph CORE1["uiTask (FreeRTOS, nhân 1, mỗi 1 ms)"]
        SCAN["Quét bàn phím 4x4<br/>(chống dội 30 ms)"]
        LED7["Quét 2 LED 7 đoạn"]
        LEDS["4 LED A-D<br/>(sáng / nhấp nháy)"]
    end
    subgraph LOOP["loop() (logic + mạng)"]
        KEY["handleKey()<br/>máy trạng thái"]
        NET["sendRequest()"]
        HTTP["httpRequest()<br/>Wi-Fi + HTTPClient"]
        BLE["bleRequest()<br/>NimBLE"]
        REN["render()"]
        FLASH[("Preferences (flash)<br/>session · IP máy chủ")]
    end
    SCAN -- "xQueue phím" --> KEY
    KEY --> NET
    NET -- "chế độ Wi-Fi" --> HTTP
    NET -- "chế độ BLE" --> BLE
    KEY --> FLASH
    REN -- "displayBuf · ledMode" --> LED7
    REN --> LEDS
```

---

## 9. Thuật toán tự tìm máy chủ của ESP32

```mermaid
flowchart TD
    A([Vào được Wi-Fi hoặc mất liên lạc 3 lần liên tiếp]) --> B["Hỏi mDNS:<br/>buidinhkhoi-LOQ-15ARP9.local"]
    B --> C{"Gọi thử<br/>session_state.php<br/>có chữ 'status'?"}
    C -- Có --> OK([Dùng IP này, lưu vào flash])
    C -- Không --> D["Thử IP lần trước<br/>(lưu trong flash)"]
    D --> E{Trả lời đúng?}
    E -- Có --> OK
    E -- Không --> F["Thử IP gateway Wi-Fi<br/>(laptop phát hotspot)"]
    F --> G{Trả lời đúng?}
    G -- Có --> OK
    G -- Không --> H["Thử IP dự phòng 10.42.0.1"]
    H --> I{Trả lời đúng?}
    I -- Có --> OK
    I -- Không --> J["Hiện -- nhấp nháy,<br/>10 giây sau thử lại"]
    J --> B
```
