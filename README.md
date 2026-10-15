# Quiz Clicker: thiết bị IoT trả lời trắc nghiệm trên lớp (STEM Quiz)

<p align="center">
  <img src="docs/images/thietbi.jpeg" alt="Thiết bị trả lời" width="300">
</p>

Báo cáo dự án **STEM Quiz**: hệ thống trắc nghiệm trực tiếp kiểu Kahoot chạy hoàn toàn trong mạng nội bộ của lớp học. Học sinh trả lời bằng **thiết bị bấm IoT ESP32 ** hoặc điện thoại; giáo viên điều khiển và xem kết quả trên web.

## Thành viên nhóm

| STT | Họ và tên | MSSV |
|:---:|---|:---:|
| 1 | Bui Dinh Khoi | 23134032 |
| 2 | Le Thanh Sang | 23134049 |
| 3 | Bui Huynh Phuong Anh | 23134002 |
| 4 | Pham Trung Kien | 23134034 |

> Mọi sơ đồ viết bằng [Mermaid](https://mermaid.js.org/), GitHub tự vẽ ngay trên trang. Ảnh PNG nằm trong [`docs/images/`](docs/images).

## Mục lục

1. [Giới thiệu](#1-giới-thiệu)
2. [Kiến trúc hệ thống](#2-kiến-trúc-hệ-thống)
3. [Cấu trúc dữ liệu](#3-cấu-trúc-dữ-liệu)
4. [Luồng thực thi](#4-luồng-thực-thi)
5. [Bảo mật và toàn vẹn dữ liệu](#5-bảo-mật-và-toàn-vẹn-dữ-liệu)
6. [Kiểm thử](#6-kiểm-thử)
7. [Cài đặt và vận hành](#7-cài-đặt-và-vận-hành)
8. [Hạn chế và hướng phát triển](#8-hạn-chế-và-hướng-phát-triển)
9. [Danh mục mã nguồn](#9-danh-mục-mã-nguồn)

---

## 1. Giới thiệu

### 1.1. Bài toán
Giáo viên cần kiểm tra nhanh mức hiểu bài của cả lớp. Giơ tay hay gọi tên thì chậm, không lưu lại được, và học sinh ngại sai trước lớp. Kahoot hay Quizizz lại cần mỗi em một điện thoại có Internet, điều mà nhiều trường không cho phép hoặc không đủ điều kiện.

### 1.2. Giải pháp
- **Giáo viên** dùng web trên laptop: quản lý lớp, học sinh, ngân hàng câu hỏi (soạn tay, nhập CSV hoặc tạo bằng AI), chạy quiz trên máy chiếu, xem kết quả và tiến bộ.
- **Học sinh** bấm trả lời trên **thiết bị ESP32** (bàn phím 4×4, 2 LED 7 đoạn, 4 LED A–D) hoặc trên **điện thoại** (trang `phone.php`).
- Thiết bị nói chuyện với máy chủ qua **Wi-Fi (HTTP + JSON)**; **Bluetooth Low Energy** là đường dự phòng, đi qua một chương trình cầu nối trên laptop.
- **Không cần Internet trong giờ học**: laptop giáo viên vừa phát Wi-Fi, vừa là máy chủ web và database, vừa là trạm Bluetooth.

### 1.3. Tính năng chính

| Nhóm | Tính năng |
|---|---|
| Lớp và học sinh | Tạo lớp; nhập danh sách từ CSV hoặc dán từ Excel (STT · MSSV · Họ tên) có bước xem trước |
| Câu hỏi | Quiz 2–4 lựa chọn, sắp xếp lại thứ tự, nhập CSV, **tạo bằng AI** từ tài liệu bài học (Claude hoặc Gemma chạy trên máy) |
| Chạy quiz | Mã phòng 2 chữ số, phòng chờ thời gian thực, đếm số em đã trả lời, Next / End |
| Thiết bị | Mã thiết bị 2 số, khoá/mở, mã QR cho điện thoại |
| Phân tích | Ma trận học sinh × câu tô màu theo độ khó, phân bố đáp án, biểu đồ tiến bộ, cảnh báo học sinh cần chú ý |
| Ôn tập | Tự tạo quiz mới từ những câu cả lớp hay sai nhất |
| Đường truyền | Wi-Fi tự tìm máy chủ (mDNS); Bluetooth cho ESP32 và điện thoại Android (Web Bluetooth) |

### 1.4. Giao diện web

| Trang chủ (Dashboard) | Ngân hàng quiz |
|:---:|:---:|
| <img src="docs/images/web1.png" alt="Dashboard" width="400"> | <img src="docs/images/web2.png" alt="Quizzes" width="400"> |
| **Danh sách lớp** | **Kết quả các buổi quiz** |
| <img src="docs/images/web3.png" alt="Classes" width="400"> | <img src="docs/images/web4.png" alt="Results" width="400"> |
| **Tiến bộ của lớp** | |
| <img src="docs/images/web5.png" alt="Progress" width="400"> | |

---

## 2. Kiến trúc hệ thống

### 2.1. Tổng quan

![Kiến trúc tổng quan](docs/images/kien-truc-tong-quan.png)

Một nguyên tắc xuyên suốt: **database là nguồn sự thật duy nhất** và **mọi thiết bị đều gọi cùng 3 API** (`join`, `session_state`, `answer`). Đi Wi-Fi hay Bluetooth thì logic nghiệp vụ vẫn chỉ nằm ở một chỗ là PHP.

### 2.2. Các thành phần

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
            PAGES["Trang web giáo viên"]
            API["API thiết bị<br/>join · session_state · answer"]
            LIVEAPI["API trực tiếp<br/>lobby_status · question_status"]
            AIAPI["API AI<br/>ai_quiz · ai_chat"]
        end
        DB[("MariaDB 10.11<br/>11 bảng")]
        GW["Cầu nối Bluetooth<br/>ble_gateway.py + phone_server.py"]
        LLM["LLM trên máy<br/>llama.cpp · Gemma 4 E4B"]
    end

    CLAUDE["Claude API<br/>(tuỳ chọn, cần Internet)"]

    ESP -- "Wi-Fi · HTTP/JSON" --> APACHE
    PHONE -- "Wi-Fi · HTTP(S)/JSON" --> APACHE
    BROWSER -- "HTTP(S)" --> APACHE
    ESP -. "BLE (dự phòng)" .-> GW
    PHONE -. "BLE · Web Bluetooth" .-> GW
    GW -- "HTTP 127.0.0.1" --> API
    APACHE --> PAGES & API & LIVEAPI & AIAPI
    PAGES --> DB
    API --> DB
    LIVEAPI --> DB
    AIAPI --> LLM
    AIAPI -.-> CLAUDE
```

| Thành phần | Vai trò |
|---|---|
| **Thiết bị ESP32** | Nhập STT, mã phòng, chọn A–D; hỏi trạng thái phiên mỗi giây; tự tìm máy chủ; lưu phiên vào flash |
| **Điện thoại** | Trang `phone.php` dùng đúng 3 API như ESP32; cài được như ứng dụng (PWA), mở được khi mất mạng |
| **Web giáo viên** | Quản lý lớp, quiz, thiết bị; chạy quiz; kết quả, tiến bộ, ôn tập |
| **API JSON** | Cửa vào duy nhất của thiết bị; kiểm tra chặt đầu vào; transaction và khoá hàng |
| **Cầu nối Bluetooth** | Chỉ chuyển đổi giao thức BLE ↔ HTTP, không chứa logic nghiệp vụ |
| **MariaDB** | Lưu toàn bộ dữ liệu, ràng buộc khoá ngoại và duy nhất |
| **AI** | Soạn câu hỏi trắc nghiệm từ tài liệu; mặc định chạy Gemma trên GPU laptop (miễn phí, offline) |

### 2.3. Triển khai mạng

```mermaid
flowchart TB
    subgraph LAPTOP["Laptop giáo viên (Ubuntu 24.04)"]
        direction TB
        WLAN["Card Wi-Fi<br/>Hotspot 10.42.0.1<br/>(NetworkManager)"]
        BT["Card Bluetooth<br/>BlueZ · vừa central vừa peripheral"]
        AVAHI["avahi-daemon<br/>tên mDNS: &lt;hostname&gt;.local"]
        WEB["Apache + PHP + MariaDB"]
        GWP["ble_gateway.py"]
    end

    E1["ESP32 #01<br/>10.42.0.x"]
    E2["ESP32 #02<br/>10.42.0.x"]
    P1["Điện thoại<br/>10.42.0.x"]
    E3["ESP32 #07<br/>(chế độ Bluetooth)"]
    P2["Điện thoại Android<br/>(chế độ Bluetooth)"]
    PROJ["Máy chiếu"]

    E1 -- "Wi-Fi 2.4 GHz" --> WLAN
    E2 -- "Wi-Fi 2.4 GHz" --> WLAN
    P1 -- "Wi-Fi" --> WLAN
    E3 -. "ESP quảng bá quiz-esp-07,<br/>laptop quét và kết nối" .-> BT
    P2 -. "laptop quảng bá 'STEM Quiz',<br/>điện thoại kết nối" .-> BT
    WLAN --> WEB
    AVAHI -. "trả lời truy vấn mDNS" .-> E1
    BT --> GWP --> WEB
    WEB --> PROJ
```

Hai chiều Bluetooth **ngược vai** nhau:
- Với ESP32, laptop là **central** (đi tìm và kết nối), ESP32 là **peripheral**.
- Với điện thoại, laptop là **peripheral** (phát quảng bá), vì trình duyệt chỉ làm được central.

### 2.4. Phân tầng phần mềm phía máy chủ

```mermaid
flowchart TB
    subgraph L1["Tầng giao diện"]
        UI1["Trang quản lý<br/>classes · quizzes · devices · account"]
        UI2["Trang trình chiếu<br/>lobby · quiz_run"]
        UI3["Trang phân tích<br/>results · progress · review"]
        UI4["Trang học sinh<br/>phone.php · phone_setup.php"]
        UI5["Tạo quiz bằng AI<br/>ai_quiz.php · khung chat AI"]
    end

    subgraph L2["Tầng xử lý (PHP thuần, không framework)"]
        ACT["Xử lý thao tác<br/>add_* · edit_* · delete_* · import_* ·<br/>launch · start · next · end · cancel"]
        APIL["API JSON<br/>join · session_state · answer ·<br/>lobby_status · question_status · reorder_questions ·<br/>ai_quiz · ai_chat"]
        INC["Thư viện dùng chung includes/<br/>auth · security · join_code · quiz_lock ·<br/>student_rules · results_data · progress_data ·<br/>review_data · live_sessions · ai_quiz"]
    end

    subgraph L3["Tầng dữ liệu"]
        MDB[("MariaDB · InnoDB · utf8mb4<br/>transaction · khoá hàng · khoá ngoại")]
    end

    UI1 --> ACT
    UI2 --> APIL
    UI3 --> INC
    UI4 --> APIL
    UI5 --> APIL
    ACT --> INC
    APIL --> INC
    INC --> MDB
    ACT --> MDB
    APIL --> MDB
```

### 2.5. Phần cứng thiết bị ESP32

```mermaid
flowchart LR
    KP["Bàn phím ma trận 4x4<br/>hàng: GPIO 33 32 14 13<br/>cột: GPIO 34 35 36 39<br/>(kéo lên 10 kΩ)"]
    MCU["ESP32 DevKit<br/>2 nhân · Wi-Fi 2.4 GHz · BLE"]
    SEG["2 LED 7 đoạn anode chung<br/>đoạn A-G, DP: GPIO 21 26 25 27 23 17 22 16"]
    PNP["2 transistor PNP<br/>chọn chữ số: GPIO 19 18"]
    LED["4 LED đáp án<br/>A: GPIO 4 · B: 5 · C: 15 · D: 2"]
    USB["Nguồn 5 V qua USB"]

    KP --> MCU
    MCU --> SEG
    MCU --> PNP --> SEG
    MCU --> LED
    USB --> MCU
```

Bố trí phím:

| | | | |
|---|---|---|---|
| `1` | `2` | `3` | `A` |
| `4` | `5` | `6` | `B` |
| `7` | `8` | `9` | `C` |
| `*` | `0` | `#` | `D` |

`0–9` nhập số, `A–D` chọn đáp án, `#` xác nhận/gửi, `*` xoá hoặc quay lại; **bấm `*` hai lần trong 0,5 giây** để đổi Wi-Fi ↔ Bluetooth.

### 2.6. Kiến trúc firmware

Firmware chạy hai luồng FreeRTOS song song để màn hình không bao giờ nháy và không mất phím, kể cả khi đang chờ mạng.

```mermaid
flowchart LR
    subgraph CORE1["uiTask (FreeRTOS, nhân 1, chu kỳ 1 ms)"]
        SCAN["Quét bàn phím 4x4<br/>chống dội 30 ms"]
        LED7["Quét luân phiên<br/>2 LED 7 đoạn"]
        LEDS["4 LED A-D<br/>tắt / sáng / nhấp nháy"]
    end
    subgraph LOOP["loop() (logic + mạng)"]
        KEY["handleKey()<br/>máy trạng thái"]
        POLL["pollSession()<br/>mỗi 1 giây"]
        NET["sendRequest()"]
        HTTP["httpRequest()<br/>WiFi + HTTPClient"]
        BLE["bleRequest()<br/>NimBLE"]
        FIND["findServer()<br/>mDNS + thử IP"]
        REN["render()"]
        FLASH[("Preferences (flash)<br/>session · IP máy chủ")]
    end
    SCAN -- "xQueue phím" --> KEY
    KEY --> NET
    POLL --> NET
    NET -- "chế độ Wi-Fi" --> HTTP
    NET -- "chế độ BLE" --> BLE
    HTTP -.-> FIND
    KEY --> FLASH
    FIND --> FLASH
    REN -- "displayBuf · ledMode" --> LED7
    REN --> LEDS
```

Mọi lời gọi API đi qua một hàm `sendRequest()`, hàm này tự chọn HTTP hay BLE, nên phần vào phòng, hỏi trạng thái và gửi đáp án không phân biệt đường truyền.

### 2.7. Công nghệ sử dụng

| Thành phần | Công nghệ |
|---|---|
| Hệ điều hành máy chủ | Ubuntu 24.04 |
| Web server | Apache 2.4, HTTPS bằng CA nội bộ |
| Backend | PHP 8.3 thuần, `mysqli` + prepared statement |
| Database | MariaDB 10.11, InnoDB, utf8mb4 |
| Frontend | HTML/CSS/JS thuần, three.js 0.149 (nền 3D), SweetAlert2, Tabler Icons |
| Thiết bị | ESP32 (Arduino core 3.x), FreeRTOS, `WiFi`, `HTTPClient`, `ESPmDNS`, `Preferences`, NimBLE-Arduino 2.x |
| Cầu nối Bluetooth | Python 3.12, `bleak` (central), `dbus-fast` (GATT server qua BlueZ) |
| Điện thoại | Web Bluetooth API, Service Worker, Web App Manifest (PWA) |
| AI | llama.cpp + Gemma 4 E4B trên máy; hoặc Claude API qua `anthropic-ai/sdk` |
| Mạng | NetworkManager hotspot, avahi (mDNS), BlueZ; Cloudflare Tunnel (tuỳ chọn) |

---

## 3. Cấu trúc dữ liệu

### 3.1. Sơ đồ quan hệ thực thể (ERD)

Sơ đồ được vẽ từ chính schema đang chạy (11 bảng). Ảnh PNG: [`docs/images/erd.png`](docs/images/erd.png).

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
    devices |o--o{ device_sessions : "thiết bị (NULL khi xoá)"
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
        varchar mssv "có thể NULL"
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

### 3.2. Nhóm bảng

| Nhóm | Bảng | Ý nghĩa |
|---|---|---|
| Người dùng | `teachers` | Tài khoản giáo viên; mọi dữ liệu khác tách theo giáo viên |
| Lớp học | `classes`, `students` | Lớp và học sinh. `student_id` là **STT 2 chữ số** nhập trên thiết bị, `mssv` là mã số của trường |
| Ngân hàng câu hỏi | `quizzes`, `questions`, `question_options`, `class_quizzes` | Bộ câu hỏi, câu hỏi, lựa chọn A–D, quiz giao cho lớp nào |
| Thiết bị | `devices` | Mã 2 số của ESP32 hoặc điện thoại, dùng chung cho mọi giáo viên |
| Phiên trực tiếp | `live_sessions`, `device_sessions`, `responses` | Mỗi lần chạy quiz; ai vào bằng thiết bị nào; ai trả lời gì |

### 3.3. Ràng buộc quan trọng

| Ràng buộc | Bảng | Tác dụng |
|---|---|---|
| `UNIQUE (session_id, question_id, student_pk)` | `responses` | Mỗi học sinh trả lời **1 lần mỗi câu**, kể cả khi hai yêu cầu đến cùng lúc |
| `UNIQUE (session_id, device_id)` | `device_sessions` | Một thiết bị chỉ vào một phòng một lần |
| `UNIQUE (session_id, student_pk)` | `device_sessions` | Một học sinh chỉ vào phòng bằng một thiết bị |
| `UNIQUE (class_id, student_id)` | `students` | STT không trùng trong lớp |
| `UNIQUE (teacher_id, name)` | `classes` | Một giáo viên không có hai lớp trùng tên |
| `UNIQUE (question_id, option_order)` | `question_options` | Mỗi câu chỉ có một A, một B… |
| `UNIQUE device_code`, `UNIQUE email` | `devices`, `teachers` | Mã thiết bị và email không trùng |
| `INDEX (join_code, status)` | `live_sessions` | Tìm nhanh phòng đang mở theo mã phòng |
| Khoá ngoại `RESTRICT` | mọi bảng | Không xoá được dữ liệu đang được tham chiếu |
| `ON DELETE SET NULL` | `device_sessions.device_id` | Xoá mã thiết bị vẫn **giữ kết quả cũ** |

`current_question_id` không có khoá ngoại; tính hợp lệ của nó do PHP bảo đảm (chỉ gán câu thuộc đúng quiz của phiên).

### 3.4. Cách tính điểm
- **Câu đã được hỏi** trong một phiên: các câu có `question_order` ≤ thứ tự của câu cuối cùng được mở.
- **Điểm** = số câu đúng (đáp án trùng `question_options.is_correct = 1`) / số câu đã được hỏi.
- Chỉ tính phiên `ENDED` có ít nhất một học sinh tham gia. Trang Kết quả, Tiến bộ và Ôn tập dùng chung cách tính này.

### 3.5. Dữ liệu được lưu ở đâu

```mermaid
flowchart LR
    subgraph SV["Máy chủ"]
        DB[("MariaDB<br/>toàn bộ nghiệp vụ")]
        SESS["Phiên PHP<br/>teacher_id · CSRF token · nháp AI"]
        PRIV["/var/www/iot-private<br/>cấu hình AI, mã mời<br/>(ngoài thư mục web)"]
    end
    subgraph ESPD["ESP32"]
        PREF[("Flash · Preferences<br/>session · IP máy chủ")]
    end
    subgraph PH["Điện thoại"]
        LS["localStorage<br/>mã máy · STT · phiên"]
        SW["Service Worker cache<br/>phone.php · manifest · icon"]
    end
    subgraph BR["Trình duyệt giáo viên"]
        SS["sessionStorage<br/>trạng thái cảnh 3D"]
    end
```

| Nơi lưu | Dữ liệu | Thời hạn |
|---|---|---|
| MariaDB | Toàn bộ dữ liệu nghiệp vụ (11 bảng) | Lâu dài, nguồn sự thật duy nhất |
| Phiên PHP | `teacher_id`, CSRF token, nháp quiz AI | Đến khi đăng xuất |
| Flash ESP32 | `session` đang làm, IP máy chủ tìm được | Qua mất điện, khởi động lại |
| `localStorage` điện thoại | Mã máy, STT, phiên đang làm | Qua tải lại trang |
| Service Worker cache | Bản sao `phone.php` | Để mở được khi mất Wi-Fi |

### 3.6. Định dạng dữ liệu trao đổi

**API cho thiết bị (JSON):**

| API | Phương thức | Vào | Ra |
|---|---|---|---|
| `api/join.php` | POST | `device_id`, `student_id`, `join_code` | `{"status":"OK","session_id":15}` (thêm `"rejoined":true` khi vào lại) |
| `api/session_state.php` | GET | `device_id`, `session_id` | `WAITING` / `ACTIVE` + `question_id`, `question_order` / `ENDED` |
| `api/answer.php` | POST | `device_id`, `session_id`, `question_id`, `answer` (A–D) | `{"status":"OK"}` |

Lỗi luôn có dạng `{"status":"ERROR","reason":"..."}`. Firmware đổi `reason` thành mã lỗi trên LED:

| Mã LED | `reason` từ máy chủ | Nghĩa |
|---|---|---|
| E1 | (không có trả lời), `server_error` | Không tới được máy chủ, giữ nguyên số đã nhập / đáp án đã chọn để thử lại |
| E2 | `device_not_registered`, `device_inactive` | Thiết bị chưa đăng ký hoặc bị khoá |
| E3 | `invalid_join_code` | Sai mã phòng |
| E4 | `session_not_waiting` | Phòng đã bắt đầu |
| E5 | `student_not_in_class` | STT không có trong lớp |
| E6 | `device_already_joined`, `student_already_joined` | Thiết bị hoặc học sinh đã vào phòng |
| E7 | `already_answered` | Câu này đã trả lời |
| E8 | `device_not_joined`, `session_not_found` | Không còn trong phòng (phòng bị huỷ), tự về màn hình nhập |
| E9 | còn lại (`invalid_option`, `invalid_request`…) | Lỗi khác |

Riêng `stale_question` và `session_not_active` khi gửi đáp án thì thiết bị không báo lỗi mà hỏi lại trạng thái ngay để hiện câu mới.

**Gói Bluetooth ESP32 ↔ cầu nối** (MTU 247, một yêu cầu đi trọn một gói):

```
Yêu cầu  (ESP ghi vào REQUEST, rồi notify):  "<seq>\n<path>\n<JSON body>"
          ví dụ: "7\nanswer.php\n{\"device_id\":\"01\",\"session_id\":19,\"question_id\":123,\"answer\":\"B\"}"
Trả lời  (cầu nối ghi vào RESPONSE):          "<seq>\n1\n<JSON từ API>"   hoặc   "<seq>\n0\n" (không tới được máy chủ)
```

| UUID | Vai trò | Thuộc tính |
|---|---|---|
| `5ca7d9a4-0492-45e2-aaff-dbb32658be6b` | Service quiz | |
| `e6dd137a-6c6c-4142-884e-51e4a17a9211` | REQUEST: ESP → laptop | READ, NOTIFY |
| `3551aab3-72c0-4dec-9f9a-259bffe6ccbe` | RESPONSE: laptop → ESP | WRITE |

Số thứ tự `seq` giúp ESP bỏ qua trả lời cũ và giúp cầu nối không xử lý một yêu cầu hai lần.

**Lệnh ngắn điện thoại ↔ cầu nối** (vừa gói BLE nhỏ nhất 20 byte):

| Lệnh | API tương ứng |
|---|---|
| `J,<máy>,<STT>,<mã phòng>` — ví dụ `J,03,09,49` | `join.php` |
| `S,<máy>,<session_id>` — ví dụ `S,03,19` | `session_state.php` |
| `A,<máy>,<session_id>,<question_id>,<A-D>` — ví dụ `A,03,19,123,B` | `answer.php` |

### 3.7. Cấu trúc dữ liệu trong firmware

| Biến | Kiểu | Ý nghĩa |
|---|---|---|
| `mode` | `enum Mode` | `RESUME`, `INPUT_STUDENT`, `INPUT_CODE`, `WAITING`, `ACTIVE`, `ENDED` |
| `studentId[3]`, `joinCode[3]` | `char[]` | STT và mã phòng 2 chữ số vừa nhập |
| `sessionId`, `questionId`, `questionOrder` | `long` | Phiên đang tham gia và câu đang mở |
| `chosen`, `answered` | `char`, `bool` | Đáp án đã chọn, đã gửi thành công chưa |
| `displayBuf[2]`, `displayBlink` | `volatile byte[]`, `bool` | Hình 2 chữ số cho `uiTask` quét |
| `ledMode[4]` | `volatile LedMode[]` | Trạng thái 4 LED A–D: tắt / sáng / nhấp nháy |
| `keyQueue` | `QueueHandle_t` | Hàng đợi phím từ `uiTask` sang `loop()` |
| `serverBase` | `String` | `http://<ip>/iot/api/` đang dùng; rỗng = chưa tìm thấy |
| `bleMode`, `bleGatewayReady` | `bool` | Đang dùng Bluetooth; laptop đã đăng ký nghe |
| `bleReply`, `bleReplySeq` | `String`, `uint32_t` | Trả lời mới nhất từ laptop (khoá bằng semaphore) |

---

## 4. Luồng thực thi

### 4.1. Một buổi quiz từ đầu đến cuối

```mermaid
sequenceDiagram
    autonumber
    actor GV as Giáo viên
    participant WEB as Web (PHP)
    participant DB as MariaDB
    participant ESP as ESP32 / điện thoại
    actor HS as Học sinh

    GV->>WEB: Launch quiz cho lớp 10A1
    WEB->>DB: Cấp mã phòng (GET_LOCK), INSERT live_sessions WAITING
    WEB-->>GV: Phòng chờ, mã phòng 49

    HS->>ESP: Nhập STT 05, mã phòng 49, bấm xác nhận
    ESP->>WEB: POST api/join.php
    WEB->>DB: Kiểm tra thiết bị, phòng, học sinh rồi INSERT device_sessions
    WEB-->>ESP: status OK, session_id 19
    loop Mỗi giây
        GV->>WEB: GET api/lobby_status.php
        WEB-->>GV: Danh sách đã vào / chưa vào
    end

    GV->>WEB: Start
    WEB->>DB: status ACTIVE, current_question_id = câu 1
    loop Mỗi giây
        ESP->>WEB: GET api/session_state.php
        WEB-->>ESP: ACTIVE, question_order 1
    end
    HS->>ESP: Chọn B, bấm gửi
    ESP->>WEB: POST api/answer.php answer B
    WEB->>DB: Khoá hàng, kiểm tra câu đang mở, INSERT responses
    WEB-->>ESP: status OK (LED B sáng đứng)
    GV->>WEB: GET api/question_status.php
    WEB-->>GV: Đã trả lời 24/30

    GV->>WEB: Next Question ... End Quiz
    WEB->>DB: status ENDED
    ESP->>WEB: GET api/session_state.php
    WEB-->>ESP: ENDED (màn hình hiện En)
    GV->>WEB: Xem kết quả, ma trận, phân tích câu
```

### 4.2. Trạng thái phiên quiz (`live_sessions.status`)

```mermaid
stateDiagram-v2
    [*] --> WAITING: Launch (cấp mã phòng)
    WAITING --> WAITING: Học sinh vào phòng
    WAITING --> [*]: Cancel (xoá phiên, thiết bị báo E8)
    WAITING --> ACTIVE: Start (mở câu 1)
    ACTIVE --> ACTIVE: Next Question, thu đáp án
    ACTIVE --> ENDED: End Quiz
    ENDED --> [*]: Tính vào Kết quả, Tiến bộ, Ôn tập
```

- `WAITING`: cho vào phòng. `ACTIVE`: đang hỏi, không nhận người mới (trừ thiết bị **vào lại**). `ENDED`: đã khoá, chỉ để xem kết quả.
- Quiz đã từng chạy bị **khoá chỉnh sửa** (`includes/quiz_lock.php`) để kết quả cũ không bị sai lệch.

### 4.3. Mở phòng (Launch)

```mermaid
flowchart TD
    A([Giáo viên chọn lớp, bấm Launch]) --> B{CSRF hợp lệ?<br/>Quiz và lớp thuộc giáo viên?}
    B -- Không --> X([Báo lỗi])
    B -- Có --> C["GET_LOCK('join_code')<br/>chống hai người cấp trùng mã"]
    C --> D[BEGIN TRANSACTION]
    D --> E["Bắt đầu từ một mã ngẫu nhiên 00-99,<br/>dò lần lượt tới mã không trùng<br/>phòng WAITING/ACTIVE"]
    E --> F{Còn mã trống?}
    F -- Không --> Y([Báo hết mã, ROLLBACK])
    F -- Có --> G["INSERT IGNORE class_quizzes<br/>(tự giao quiz cho lớp nếu chưa)"]
    G --> H["INSERT live_sessions<br/>status = WAITING"]
    H --> I[COMMIT · RELEASE_LOCK]
    I --> J([Chuyển tới lobby.php])
```

### 4.4. Xử lý `api/join.php`

```mermaid
flowchart TD
    A([POST JSON: device_id, student_id, join_code]) --> B{Đúng POST,<br/>JSON hợp lệ,<br/>mã đúng 2 chữ số?}
    B -- Không --> E0[invalid_request / invalid_json]
    B -- Có --> T[BEGIN · SELECT phòng theo join_code<br/>WAITING hoặc ACTIVE · FOR UPDATE]
    T --> C{Có đúng 1 phòng?}
    C -- Không --> E1[invalid_join_code]
    C -- Có --> D{Thiết bị đăng ký<br/>và ACTIVE?}
    D -- Không --> E2[device_not_registered / device_inactive]
    D -- Có --> F{STT có trong lớp<br/>của phòng?}
    F -- Không --> E3[student_not_in_class]
    F -- Có --> G{Cùng thiết bị + học sinh<br/>đã vào phòng này?}
    G -- Có --> R([OK, rejoined: true])
    G -- Không --> H{Phòng còn WAITING?}
    H -- Không --> E4[session_not_waiting]
    H -- Có --> I{Thiết bị hoặc học sinh<br/>đã vào bằng cách khác?}
    I -- Có --> E5[device_already_joined / student_already_joined]
    I -- Không --> J[INSERT device_sessions · COMMIT]
    J --> K([OK, session_id])
```

Nhờ nhánh **vào lại**, ESP32 mất điện hay điện thoại tải lại trang vẫn quay về đúng phòng kể cả khi quiz đã bắt đầu.

### 4.5. Xử lý `api/answer.php`

```mermaid
flowchart TD
    A([POST JSON: device_id, session_id, question_id, answer]) --> B{JSON hợp lệ,<br/>answer là A-D?}
    B -- Không --> E0[invalid_request]
    B -- Có --> T[BEGIN · SELECT live_sessions FOR UPDATE]
    T --> C{Phiên tồn tại<br/>và ACTIVE?}
    C -- Không --> E1[session_not_found / session_not_active]
    C -- Có --> D{question_id =<br/>current_question_id?}
    D -- Không --> E2[stale_question<br/>giáo viên đã chuyển câu]
    D -- Có --> Q{Câu thuộc quiz<br/>của phiên?}
    Q -- Không --> EQ[invalid_question]
    Q -- Có --> F{Thiết bị hợp lệ<br/>và đã vào phiên?}
    F -- Không --> E3[device_* / device_not_joined]
    F -- Có --> G{Lựa chọn có thật<br/>trong câu này?}
    G -- Không --> E4[invalid_option]
    G -- Có --> H{Học sinh đã trả lời<br/>câu này chưa?}
    H -- Rồi --> E5[already_answered]
    H -- Chưa --> I[INSERT responses · COMMIT]
    I --> K([OK])
    I -. "trùng khoá UNIQUE<br/>(hai yêu cầu cùng lúc)" .-> E5
```

Khoá hàng `FOR UPDATE` làm cho thao tác Next/End của giáo viên và việc ghi đáp án không chen vào nhau; ràng buộc `UNIQUE` là lớp chặn cuối cùng.

### 4.6. Máy trạng thái thiết bị ESP32

```mermaid
stateDiagram-v2
    [*] --> RESUME: Bật máy, flash còn phiên
    [*] --> INPUT_STUDENT: Bật máy, không có phiên
    RESUME --> WAITING: Máy chủ báo WAITING
    RESUME --> ACTIVE: Máy chủ báo ACTIVE
    RESUME --> INPUT_STUDENT: Phím sao hoặc E8
    INPUT_STUDENT --> INPUT_CODE: Nhập STT rồi xác nhận
    INPUT_CODE --> INPUT_STUDENT: Phím sao khi ô trống
    INPUT_CODE --> WAITING: Nhập mã phòng, join OK
    INPUT_CODE --> INPUT_CODE: E3, E4, E6 thiết bị
    INPUT_CODE --> INPUT_STUDENT: E5, E6 học sinh
    WAITING --> ACTIVE: Giáo viên Start
    ACTIVE --> ACTIVE: Chọn A-D, gửi, câu mới
    ACTIVE --> ENDED: Giáo viên End
    WAITING --> INPUT_STUDENT: E8 phòng bị huỷ
    ENDED --> INPUT_STUDENT: Bấm phím bất kỳ
```

Màn hình LED tương ứng:

| Màn hình | Ý nghĩa |
|---|---|
| `--` nhấp nháy | Đang kết nối Wi-Fi hoặc tìm máy chủ |
| `--.` nhấp nháy | Đang ở Bluetooth, chờ laptop kết nối |
| `HS` | Nhập số thứ tự học sinh |
| `JC` | Nhập mã phòng |
| `--` đứng yên | Đã vào phòng, chờ Start |
| `01`, `02`… | Câu đang mở. LED đáp án nhấp nháy = đã chọn chưa gửi, sáng đứng = đã gửi |
| `En` | Quiz kết thúc |
| Chấm bên phải | Đang dùng Bluetooth |

### 4.7. Vòng lặp chính của firmware

```mermaid
flowchart TD
    S([setup: đọc flash, tạo uiTask, bật Wi-Fi, mDNS]) --> L1
    L1["1. Lấy phím từ xQueue"] --> D{Phím sao<br/>bấm 2 lần trong 0,5 s?}
    D -- Có --> TG["toggleConnection()<br/>đổi Wi-Fi / Bluetooth, giữ phiên"]
    D -- Không --> HK["handleKey()<br/>nhập số, chọn đáp án, gửi"]
    TG --> L2
    HK --> L2
    L2{"2. Đang trong phòng<br/>và đã 1 giây?"} -- Có --> PS["pollSession()<br/>GET session_state"]
    L2 -- Không --> L3
    PS --> L3["3. render()<br/>cập nhật displayBuf, ledMode"]
    L3 --> B{bleMode?}
    B -- Có --> L1
    B -- Không --> W{"Wi-Fi có,<br/>chưa có máy chủ?"}
    W -- Có --> FS["findServer()"]
    W -- Không --> L1
    FS --> L1
```

### 4.8. ESP32 tự tìm máy chủ

ESP32 không cần biết IP của laptop. Mỗi ứng viên được **gọi thử `session_state.php`** để chắc đúng là máy chủ quiz.

```mermaid
flowchart TD
    A([Vừa vào Wi-Fi hoặc mất liên lạc 3 lần liên tiếp]) --> B["Hỏi mDNS: &lt;hostname&gt;.local"]
    B --> C{"Gọi thử API<br/>có trả 'status'?"}
    C -- Có --> OK([Dùng IP này, lưu vào flash])
    C -- Không --> D["Thử IP lần trước (flash)"]
    D --> E{Đúng?}
    E -- Có --> OK
    E -- Không --> F["Thử IP gateway Wi-Fi<br/>(laptop phát hotspot)"]
    F --> G{Đúng?}
    G -- Có --> OK
    G -- Không --> H["Thử IP dự phòng 10.42.0.1"]
    H --> I{Đúng?}
    I -- Có --> OK
    I -- Không --> J["Màn hình -- nhấp nháy,<br/>10 giây sau thử lại"]
    J --> B
```

### 4.9. ESP32 trả lời qua Bluetooth

```mermaid
sequenceDiagram
    autonumber
    participant ESP as ESP32 (peripheral)
    participant GW as ble_gateway.py (central)
    participant API as API PHP

    Note over ESP: Bấm phím sao 2 lần: tắt Wi-Fi, bật BLE
    ESP-->>GW: Quảng bá "quiz-esp-01" + UUID service quiz
    GW->>ESP: Quét thấy UUID, kết nối, đăng ký notify
    Note over ESP: Hết nhấp nháy, chấm bên phải sáng
    ESP->>ESP: Ghi "7 / answer.php / JSON" vào REQUEST
    ESP-->>GW: notify (có yêu cầu mới)
    GW->>ESP: Đọc trọn REQUEST
    GW->>GW: Kiểm tra danh sách trắng (join, session_state, answer)
    GW->>API: POST http://127.0.0.1/iot/api/answer.php
    API-->>GW: status OK
    GW->>ESP: Ghi "7 / 1 / JSON" vào RESPONSE
    Note over ESP: Khớp seq 7, xử lý như trả lời HTTP
```

Cầu nối kết nối lần lượt từng ESP32 (BlueZ chỉ kết nối một thiết bị mỗi lúc), tự quét lại khi ESP mất kết nối.

### 4.10. Điện thoại Android trả lời qua Bluetooth

```mermaid
sequenceDiagram
    autonumber
    participant PH as phone.php (Chrome, central)
    participant GW as phone_server.py (peripheral, BlueZ)
    participant API as API PHP

    GW-->>PH: Quảng bá "STEM Quiz"
    PH->>GW: Bấm "Dùng Bluetooth", chọn STEM Quiz, kết nối GATT
    PH->>GW: writeValue("A,03,19,123,B")
    GW->>API: POST answer.php
    API-->>GW: status OK
    GW-->>PH: Xác nhận ghi (BlueZ chờ API trả lời xong)
    PH->>GW: readValue()
    GW-->>PH: JSON trả lời riêng cho điện thoại này
```

Mất kết nối thì trang tự nối lại 3 lần; Bluetooth không dùng được thì trang gửi qua Wi-Fi như bình thường.

### 4.11. Tạo quiz bằng AI

```mermaid
sequenceDiagram
    autonumber
    actor GV as Giáo viên
    participant PG as ai_quiz.php
    participant AP as api/ai_quiz.php
    participant AI as Gemma (llama.cpp) hoặc Claude
    participant DB as MariaDB

    GV->>PG: Tải PDF / Word / PowerPoint hoặc dán văn bản
    PG->>AP: Lưu tài liệu (Office đổi sang PDF bằng LibreOffice)
    GV->>PG: Chọn số câu, độ khó, ngôn ngữ
    PG->>AP: fetch: tạo câu hỏi
    AP->>AI: Tài liệu + yêu cầu, đầu ra theo JSON schema
    AI-->>AP: title, questions (question, options, correct, explanation)
    AP->>AP: Kiểm tra từng câu: 2-4 đáp án, đáp án đúng có thật, tối đa 99 câu
    AP-->>PG: Bộ câu hỏi nháp
    GV->>PG: Sửa bằng chat ("Câu 3 khó hơn") hoặc sửa tay
    GV->>PG: Đặt tên, chọn lớp, Save quiz
    PG->>DB: INSERT quizzes, questions, question_options, class_quizzes
```

AI không bao giờ lưu thẳng vào database: giáo viên luôn phải xem lại trước khi lưu.

---

## 5. Bảo mật và toàn vẹn dữ liệu

| Biện pháp | Áp dụng |
|---|---|
| Prepared statement | Mọi truy vấn SQL, chống SQL injection |
| `htmlspecialchars` | Mọi dữ liệu xuất ra HTML, chống XSS |
| CSRF token | Biểu mẫu thay đổi dữ liệu, API sắp xếp câu hỏi |
| bcrypt | Mật khẩu giáo viên (`password_hash` / `password_verify`) |
| Kiểm tra quyền sở hữu | Lớp, quiz, phiên đều lọc theo `teacher_id` đăng nhập |
| Transaction + `SELECT … FOR UPDATE` | Vào phòng, trả lời, bắt đầu, huỷ phiên, xoá hàng loạt |
| `GET_LOCK` | Cấp mã phòng không trùng khi nhiều người bấm cùng lúc |
| Mã mời đăng ký, chống dò mật khẩu | Đăng ký cần mã mời; sai nhiều lần thì khoá tạm thời |
| Cookie phiên | `HttpOnly`, `SameSite=Lax`, `Secure` khi HTTPS, đổi mã phiên sau đăng nhập |
| Chặn Internet cho API thiết bị | Yêu cầu qua Cloudflare Tunnel (có `Cf-Connecting-Ip`) bị 403 ở `join`, `answer`, `session_state` |
| Danh sách trắng ở cầu nối | Bluetooth chỉ gọi được 3 API thiết bị |
| CA nội bộ có Name Constraints | Chỉ ký được cho IP nội bộ và `*.local`, lộ khoá CA cũng không giả mạo được trang ngoài |
| Bí mật ngoài thư mục web | Khoá API AI và mã mời nằm trong `/var/www/iot-private` |

---

## 6. Kiểm thử

| Nội dung | Kết quả |
|---|---|
| Kiểm tra cú pháp toàn bộ mã PHP (`php -l`) | 72/72 tệp không lỗi |
| Luồng đầy đủ: đăng ký → lớp, học sinh, quiz → giao → mở phòng → vào phòng → trả lời → kết thúc → kết quả | Đạt; trả lời lần hai bị chặn |
| ESP32 thật qua Wi-Fi, tự tìm máy chủ sau nhiều lần đổi IP | Đạt |
| ESP32 thật qua Bluetooth: vào phòng, trả lời 2 câu, nhận kết thúc | Đạt (log Apache ghi 33 yêu cầu từ cầu nối) |
| Cầu nối: chặn đường dẫn lạ, xử lý khi máy chủ tắt | Đạt |
| `phone.php` với Web Bluetooth giả lập | Đạt: nối, gửi lệnh, nhận lỗi, ngắt |
| Giao diện tự động (Puppeteer + Firefox): desktop và điện thoại 384–390 px | Không tràn ngang |
| Chứng chỉ HTTPS: chuỗi hợp lệ cho IP nội bộ, từ chối tên miền ngoài | Đạt |
| Kiểm thử tải 20–50 thiết bị cùng lúc | Chưa thực hiện |

---

## 7. Cài đặt và vận hành

```bash
# 1. Máy chủ: Apache + PHP 8.3 + MariaDB, ứng dụng ở /var/www/html/iot
sudo apt install apache2 php8.3 php8.3-mysql php8.3-curl mariadb-server
sudo mysql iot < database/schema.sql            # tạo bảng lần đầu

# 2. Wi-Fi cho lớp: hotspot của laptop (10.42.0.1)
nmcli con up Hotspot

# 3. Nạp firmware ESP32 (mỗi máy một DEVICE_ID; sửa SSID/mật khẩu Wi-Fi trong sketch)
arduino-cli compile --upload -p /dev/ttyUSB0 \
  --fqbn esp32:esp32:esp32:PartitionScheme=huge_app sketch_sep27a

# 4. Bluetooth dự phòng (ESP32 + điện thoại Android)
bluetoothctl power on
ble_gateway/run.sh                              # chạy ble_gateway.py bằng .venv có bleak

# 5. HTTPS cho điện thoại (một lần, hoặc khi IP đổi)
~/IoT/https/make-certs.sh && sudo ~/IoT/https/install-https.sh
```

Trước buổi học: thêm mã thiết bị ở **Devices**, nhập danh sách lớp ở **Import Students**, tạo quiz (tay, CSV hoặc AI) rồi giao cho lớp.

Trong giờ học:
1. Bật hotspot, mở web, chọn quiz → **Launch** → chọn lớp.
2. Chiếu phòng chờ; học sinh nhập STT và mã phòng trên thiết bị.
3. **Start**, **Next Question** từng câu, cuối cùng **End Quiz**.
4. Xem **Results**, **Progress**, tạo quiz ôn tập ở **Review**.

---

## 8. Hạn chế và hướng phát triển

**Hạn chế**
- Thiết bị xác thực chỉ bằng mã 2 chữ số; HTTP và BLE không mã hoá, nên chỉ dùng trong mạng của lớp.
- Mã 2 chữ số giới hạn tối đa 100 thiết bị và 100 học sinh mỗi lớp.
- Chưa giới hạn thời gian trả lời mỗi câu.
- Bluetooth chỉ phục vụ vài thiết bị cùng lúc; điện thoại dùng Bluetooth chỉ chạy trên Android + Chrome.
- Tên và mật khẩu Wi-Fi ghi cứng trong firmware, đổi Wi-Fi phải nạp lại.
- Xoá lớp đã có phiên quiz bị khoá ngoại chặn.
- Chưa kiểm thử tải với số lượng thiết bị lớn.

**Hướng phát triển**
- Đếm ngược thời gian, tính điểm theo tốc độ, bảng xếp hạng.
- Cấu hình Wi-Fi qua chế độ AP (WiFiManager), cập nhật firmware qua mạng (OTA).
- Khoá riêng cho từng thiết bị, BLE pairing có mã hoá.
- Thiết bị chạy pin với chế độ ngủ tiết kiệm năng lượng.
- Xuất kết quả ra Excel/PDF; câu hỏi có hình ảnh và công thức.

---

## 9. Danh mục mã nguồn

| Nhóm | Tệp |
|---|---|
| Tài khoản | `register.php`, `login.php`, `logout.php`, `account.php` |
| Lớp và học sinh | `classes.php`, `class.php`, `add_class.php`, `edit_class.php`, `delete_class.php`, `students.php`, `add_student.php`, `edit_student.php`, `delete_student.php`, `delete_students.php`, `import_students.php` |
| Quiz | `quizzes.php`, `create_quiz.php`, `quiz.php`, `add_question.php`, `edit_question.php`, `delete_question.php`, `delete_quiz.php`, `save_quiz.php`, `import_quiz.php`, `assign_quiz.php`, `ai_quiz.php` |
| Chạy quiz | `launch_quiz.php`, `lobby.php`, `start_quiz.php`, `quiz_run.php`, `next_question.php`, `end_quiz.php`, `finish_quiz.php`, `cancel_session.php` |
| Kết quả | `results.php`, `session_results.php`, `result_question.php`, `progress.php`, `review.php` |
| Thiết bị, điện thoại | `devices.php`, `phone.php`, `phone_setup.php`, `phone-sw.js`, `phone.webmanifest` |
| API | `api/join.php`, `api/session_state.php`, `api/answer.php`, `api/lobby_status.php`, `api/question_status.php`, `api/reorder_questions.php`, `api/ai_quiz.php`, `api/ai_chat.php` |
| Dùng chung | `includes/auth.php`, `security.php`, `join_code.php`, `quiz_lock.php`, `student_rules.php`, `live_sessions.php`, `results_data.php`, `progress_data.php`, `review_data.php`, `ai_quiz.php`, `ai_chat.php` |
| Database | `database/schema.sql`, `database/migrations/*.sql` |
| Firmware | `sketch_sep27a/sketch_sep27a.ino` (836 dòng) |
| Cầu nối Bluetooth | `ble_gateway/ble_gateway.py`, `phone_server.py`, `run.sh` |

Quy mô: khoảng 16.700 dòng PHP, 11.600 dòng CSS, 3.600 dòng JavaScript, 836 dòng firmware C++ và khoảng 390 dòng Python.
