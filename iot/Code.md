# Hướng dẫn code ESP32 cho thiết bị trả lời quiz

*Cập nhật: 12/10/2026*

File code: **`~/Arduino/iotproject/quiz_client/quiz_client.ino`** (551 dòng).
Code đã biên dịch thành công với core `esp32:esp32` 3.3.12, không có cảnh báo, nhưng **chưa chạy thử trên mạch thật**.

---

## 1. Thiết bị làm gì

ESP32 là "tay bấm" của học sinh. Nó dùng **HTTP + JSON** để nói chuyện với server PHP qua 3 API:

| Thứ tự | API | Khi nào gọi | Gửi gì | Nhận gì |
|---|---|---|---|---|
| ① | `POST join.php` | Một lần, khi học sinh nhập xong mã và bấm `#` | `device_id`, `student_id`, `join_code` | `session_id` |
| ② | `GET session_state.php` | Mỗi 1 giây, từ lúc vào phòng đến khi kết thúc | `device_id`, `session_id` | `WAITING` / `ACTIVE` + `question_id` / `ENDED` |
| ③ | `POST answer.php` | Khi học sinh chọn A–D rồi bấm `#` | `device_id`, `session_id`, `question_id`, `answer` | `OK` hoặc lỗi |

Phần cứng chỉ có 2 LED 7 đoạn, nên mọi thông tin hiển thị phải gói trong 2 ký tự. 4 LED đơn A/B/C/D báo đáp án đang chọn.

---

## 2. Phần cứng và đấu chân

Đấu giống hệt `codenayne.ino`, không cần đấu lại.

| Linh kiện | GPIO |
|---|---|
| Bàn phím 4x4, hàng ROW1–4 | 33, 32, 14, 13 |
| Bàn phím 4x4, cột COL1–4 | 34, 35, 36, 39 (chân chỉ đọc, **cần điện trở kéo lên 10 kΩ bên ngoài**) |
| LED 7 đoạn, đoạn a, b, c, d, e, f, g, DP | 21, 26, 25, 27, 23, 17, 22, 16 |
| LED 7 đoạn, chân chung trái / phải | 19 / 18 (qua transistor PNP) |
| LED đơn A, B, C, D | 4, 5, 15, 2 |

Bố trí bàn phím:

```
1 2 3 A
4 5 6 B
7 8 9 C
* 0 # D
```

Nếu đấu khác, chỉ cần sửa các mảng `segPins`, `digitPins`, `ledPins`, `rowPins`, `colPins`, cùng hai cờ `DIG_VIA_PNP` và `LED_ACTIVE_HIGH` ở đầu file.

---

## 3. Chuẩn bị trước khi nạp code

### 3.1. Sửa phần cấu hình ở đầu file

```cpp
const char *WIFI_SSID     = "ten_wifi";
const char *WIFI_PASSWORD = "mat_khau_wifi";
const char *DEVICE_ID     = "01";  // mỗi ESP một mã riêng, phải có trong bảng devices

const char *SERVER_HOSTNAME = "buidinhkhoi-LOQ-15ARP9";  // lệnh `hostname` trên máy chủ, không có ".local"
const char *SERVER_FALLBACK = "10.42.0.1";               // IP hotspot của máy chủ
const char *SERVER_PATH     = "/iot/api/";               // nhớ dấu / ở cuối
```

- `WIFI_SSID`, `WIFI_PASSWORD`: file `.ino` đã điền sẵn Wi-Fi bạn đang dùng trong code cũ. File này chỉ ghi chữ giả để mật khẩu không lộ ra, vì `Code.md` nằm trong thư mục web.
- Không cần ghi IP máy chủ. Mỗi khi vào Wi-Fi, ESP tự tìm máy chủ (hàm `findServer()`), thử lần lượt:
  1. tên `SERVER_HOSTNAME.local` qua mDNS (máy chủ Ubuntu có sẵn avahi trả lời tên này);
  2. IP lần trước tìm được, lưu trong flash;
  3. cổng Wi-Fi, tức máy chủ khi ESP vào hotspot của chính máy chủ;
  4. `SERVER_FALLBACK`.

  IP nào trả lời `session_state.php` thì dùng IP đó. Trong lúc tìm, LED hiện `--` nhấp nháy. Mất liên lạc 3 lần liên tiếp thì ESP tự tìm lại, nên máy chủ đổi IP giữa chừng cũng không cần nạp lại code. Chỉ cần sửa `SERVER_HOSTNAME` khi đổi sang máy chủ khác.
- `DEVICE_ID`: hai chữ số. **Mỗi ESP phải có mã khác nhau.** Nạp cho ESP thứ hai thì đổi thành `"02"`, và cứ thế tiếp tục.

### 3.2. Kiểm tra mạng (hay gặp lỗi nhất)

- ESP32 **chỉ bắt được Wi-Fi 2.4 GHz**, không bắt được mạng 5 GHz.
- ESP phải **vào cùng mạng với máy chủ**. Cách thử: cho điện thoại vào đúng Wi-Fi mà ESP dùng, rồi mở `http://buidinhkhoi-LOQ-15ARP9.local/iot/login.php` (hoặc `http://10.42.0.1/iot/login.php` khi dùng hotspot của máy chủ). Mở được thì ESP cũng tìm được. Serial Monitor in ra từng IP đã thử và IP tìm thấy.

### 3.3. Đăng ký thiết bị vào database

Server từ chối mọi thiết bị chưa có trong bảng `devices` (báo lỗi `E2`). Thêm bằng SQL, mỗi ESP một dòng:

```sql
INSERT INTO devices (device_code, status) VALUES ('01','ACTIVE'), ('02','ACTIVE');
```

### 3.4. Thư viện

Cần cài một thư viện cho phần Bluetooth dự phòng: **NimBLE-Arduino** (đã cài bản 2.5.1 bằng `arduino-cli lib install NimBLE-Arduino`; trong Arduino IDE: *Library Manager* → tìm `NimBLE-Arduino`). `WiFi`, `HTTPClient`, `Preferences`, `ESPmDNS` có sẵn trong core ESP32. Code tự quét bàn phím giống `codenayne.ino`, nên không dùng `Keypad.h`.

### 3.5. Nạp code

Có thêm Bluetooth thì chương trình lớn hơn vùng nhớ mặc định (1,25 MB), nên phải chọn bảng phân vùng **Huge APP** (3 MB cho chương trình, không có OTA). Đổi phân vùng không xóa phiên đã lưu vì vùng `nvs` vẫn ở chỗ cũ.

**Bằng Arduino IDE:** mở file `sketch_sep27a.ino`, vào *Tools → Board* chọn **ESP32 Dev Module**, *Tools → Partition Scheme* chọn **Huge APP (3MB No OTA/1MB SPIFFS)**, chọn cổng (thường là `/dev/ttyUSB0`), rồi bấm Upload.

**Bằng terminal:**

```bash
arduino-cli compile --upload -p /dev/ttyUSB0 \
  --fqbn esp32:esp32:esp32:PartitionScheme=huge_app \
  ~/Desktop/esp32project/sketch_sep27a
arduino-cli monitor -p /dev/ttyUSB0 -c baudrate=115200    # xem log
```

### 3.6. Dự phòng Bluetooth (BLE)

Bật máy là ESP dùng Wi-Fi. **Bấm nhanh `*` hai lần** (trong 0,5 giây, `DOUBLE_PRESS_MS`) để đổi Wi-Fi ↔ Bluetooth, ở bước nào cũng được, kể cả lúc đang `--` nhấp nháy. ESP không tự đổi; mất Wi-Fi thì phải bấm `**`. Vì phải chờ xem có bấm lần hai không, bấm `*` một lần sẽ có tác dụng chậm khoảng nửa giây.

- ESP phát quảng bá tên `quiz-esp-<DEVICE_ID>` với dịch vụ `BLE_SERVICE_UUID`.
- Máy chủ chạy **cầu nối** `~/Desktop/esp32project/ble_gateway/ble_gateway.py`. Cầu nối tìm ESP, kết nối, nhận yêu cầu qua Bluetooth rồi gọi đúng `join.php`, `session_state.php`, `answer.php` như ESP tự gọi. PHP và database không cần biết thiết bị dùng đường nào.
- Học sinh dùng y như cũ. **Dấu chấm ở LED bên phải** cho biết thiết bị đang dùng Bluetooth. Ở Bluetooth dấu chấm này luôn sáng, nên muốn biết đã gửi đáp án chưa thì nhìn LED A–D (sáng đứng yên = đã gửi).
- Đang ở trong phòng mà chuyển sang Bluetooth thì vẫn làm tiếp đúng phòng đó (phiên lưu trong flash, máy chủ nhận diện theo `DEVICE_ID`).

Chạy cầu nối trên máy chủ (để cửa sổ terminal mở suốt buổi học):

```bash
bluetoothctl power on                              # bật Bluetooth của máy chủ
~/Desktop/esp32project/ble_gateway/run.sh          # Ctrl+C để dừng
```

Cầu nối in ra mỗi ESP kết nối và mỗi yêu cầu, ví dụ `quiz-esp-01: answer.php -> {"status":"OK"}`. Thư viện Python `bleak` đã cài sẵn trong `ble_gateway/.venv`; cài lại: `python3 -m venv .venv && .venv/bin/pip install -r requirements.txt`.

Giới hạn: card Bluetooth của máy chủ chỉ giữ được khoảng 7–20 kết nối cùng lúc, nên Bluetooth là đường **dự phòng** cho vài thiết bị, không thay Wi-Fi cho cả lớp. Tầm phủ khoảng 10–30 m.

**Điện thoại Android cũng dùng được Bluetooth** (Chrome, không có trên iPhone). Trình duyệt chỉ biết đi tìm thiết bị, nên cầu nối làm ngược lại với ESP: máy chủ phát quảng bá tên `STEM Quiz` (`phone_server.py`). `phone.php` có nút **Dùng Bluetooth**; khi đã nối, mỗi yêu cầu thành một lệnh ngắn (`J,03,09,49` vào phòng, `S,03,19` hỏi trạng thái, `A,03,19,123,B` gửi đáp án), cầu nối gọi đúng API rồi để điện thoại đọc lại JSON.

Chrome chỉ cho dùng Bluetooth trên trang `https://`, và trang phải được lưu trong máy để mất Wi-Fi vẫn mở được:

- `~/IoT/https/make-certs.sh` tạo chứng chỉ riêng của lớp (`ca.crt`, chỉ ký được cho IP nội bộ, `localhost`, `*.local`) và chứng chỉ Apache ghi các IP hiện tại. `sudo ~/IoT/https/install-https.sh` bật `https://` cho Apache (`http://` vẫn chạy như cũ). IP máy chủ đổi thì chạy lại hai lệnh này; điện thoại không phải cài lại.
- Mỗi điện thoại làm một lần theo trang `phone_setup.php` (link "Xem cách cài" trong `phone.php`): tải và cài `stem-quiz-ca.crt`, mở `https://<IP>/iot/phone.php`, *Thêm vào màn hình chính*.
- `phone-sw.js` lưu sẵn `phone.php` (chỉ trang này, không lưu API), `phone.webmanifest` + `assets/phone/icon-*.png` để cài thành ứng dụng.

---

## 4. Cách học sinh dùng

1. Bật máy. Màn hình hiện `--` nhấp nháy trong lúc kết nối Wi-Fi.
2. Màn hình hiện **`HS`** (học sinh). Học sinh gõ mã của mình, ví dụ `0` `5` → hiện `05`, rồi bấm **`#`**.
3. Màn hình hiện **`JC`** (join code). Học sinh gõ mã tham gia trên màn hình giáo viên, ví dụ `4` `2` → hiện `42`, rồi bấm **`#`**. Thiết bị gửi yêu cầu vào phòng.
4. Vào phòng thành công thì màn hình hiện **`--`** đứng yên, nghĩa là đang chờ giáo viên bấm Start.
5. Giáo viên bấm Start, màn hình hiện số câu, ví dụ **`01`**.
   - Bấm `A`/`B`/`C`/`D` để chọn. LED của đáp án đó **nhấp nháy**, nghĩa là chưa gửi. Muốn đổi thì bấm chữ khác, muốn bỏ chọn thì bấm `*`.
   - Bấm **`#`** để gửi. LED đáp án **sáng đứng yên** và góc phải màn hình có **dấu chấm** (`01.`), nghĩa là đã gửi xong (ở Bluetooth dấu chấm này luôn sáng). Sau đó không đổi được nữa.
6. Giáo viên bấm Next, màn hình tự chuyển sang `02`, LED tắt, học sinh chọn tiếp.
7. Giáo viên bấm End, màn hình hiện **`En`**. Bấm phím bất kỳ để quay về `HS` cho buổi sau.

### Cách nhập 2 chữ số

Thiết bị nhập theo **từng cặp**. Đang có đủ 2 số mà bấm tiếp thì xóa số cũ và bắt đầu số mới:

| Bấm | Màn hình |
|---|---|
| `2` | `2-` (gạch ngang: chờ chữ số thứ hai) |
| `4` | `24` |
| `3` | `3-` (bắt đầu số mới, `24` bị xóa) |
| `2` | `32` |
| `1` `5` | `15` |
| `#` | Gửi `15` |
| `*` | Xóa ô nhập. Nếu ô đang trống ở bước `JC` thì quay lại bước `HS` để sửa mã học sinh |

Chỉ khi đủ 2 số thì `#` mới có tác dụng.

### Bảng hiển thị

| Màn hình | Ý nghĩa |
|---|---|
| `--` nhấp nháy | Đang kết nối Wi-Fi hoặc tìm máy chủ. Có thêm dấu chấm bên phải (`--.`): đang ở Bluetooth, chờ máy chủ kết nối. Lúc này phím bị bỏ qua |
| Dấu chấm ở LED phải (ngoài lúc trả lời câu hỏi) | Đang dùng Bluetooth (mục 3.6) |
| `HS` | Mời nhập mã học sinh |
| `JC` | Mời nhập mã tham gia |
| `2-`, `24` | Đang nhập số |
| `--` đứng yên | Đã vào phòng, chờ giáo viên bấm Start |
| `01`, `02`… | Đang ở câu 1, câu 2… |
| `01.` (có chấm) | Câu này đã gửi xong |
| `En` | Quiz đã kết thúc |
| `E1`–`E9` | Lỗi, xem bảng dưới. Tự tắt sau 2 giây hoặc khi bấm phím bất kỳ |

### Bảng mã lỗi

| Mã | Lý do server trả về | Ý nghĩa và cách xử lý |
|---|---|---|
| `E1` | Không có phản hồi / `server_error` | Không tới được server: sai IP, khác mạng, Apache tắt. Số đã nhập và đáp án đang chọn được giữ nguyên, bấm `#` để thử lại |
| `E2` | `device_not_registered`, `device_inactive` | `DEVICE_ID` chưa có trong bảng `devices` (xem mục 3.3) |
| `E3` | `invalid_join_code` | Sai mã tham gia. Nhập lại |
| `E4` | `session_not_waiting` | Giáo viên đã bấm Start, không vào phòng được nữa |
| `E5` | `student_not_in_class` | Mã học sinh không có trong lớp này. Quay về `HS` |
| `E6` | `student_already_joined`, `device_already_joined` | Học sinh này, hoặc thiết bị này, đã vào phòng rồi |
| `E7` | `already_answered` | Câu này đã được ghi nhận từ trước |
| `E8` | `device_not_joined`, `session_not_found` | Server không nhận ra phiên nữa. Quay về `HS` |
| `E9` | Lỗi khác, ví dụ `invalid_option` | Ví dụ câu chỉ có 3 lựa chọn mà bấm `D` |

---

## 5. Giải thích code từng phần

### 5.1. Cấu trúc: hai vòng lặp chạy song song

Mỗi lần gọi API, ESP phải **đứng chờ** server trả lời, thường mất vài chục ms, và tới 3 giây nếu mất mạng. Nếu việc quét LED 7 đoạn cũng nằm trong `loop()` như ở `codenayne.ino`, thì trong lúc chờ:

- Chỉ một LED 7 đoạn sáng, LED còn lại tắt, nên màn hình bị nháy mỗi giây.
- Phím bấm lúc đó bị mất.

Vì vậy code chia ra hai vòng lặp:

| | `uiTask()` | `loop()` |
|---|---|---|
| Làm gì | Quét LED 7 đoạn, nhấp nháy LED, quét bàn phím | Xử lý phím, gọi API, quyết định hiển thị gì |
| Tốc độ | Mỗi 1 ms | Mỗi khoảng 5 ms, và đứng chờ khi gọi API |
| Có bị chặn khi gọi API không | **Không** | Có |

Hai bên trao đổi qua vài biến dùng chung:

```cpp
volatile byte displayBuf[2];   // loop() ghi 2 ký tự cần hiện, uiTask() đọc ra để quét
volatile bool displayBlink;    // true = cả màn hình nhấp nháy
volatile byte ledMode[4];      // từng LED A-D: tắt / sáng / nhấp nháy
QueueHandle_t keyQueue;        // uiTask() bỏ phím vào, loop() lấy ra
```

`volatile` báo cho trình biên dịch rằng biến có thể bị task khác thay đổi bất cứ lúc nào, nên mỗi lần dùng phải đọc lại từ bộ nhớ. Hàng đợi `keyQueue` chứa được 16 phím. Kể cả khi `loop()` đang chờ server, phím bấm vẫn được xếp hàng và không bị mất.

### 5.2. Hình trên LED 7 đoạn

Mỗi ký tự là 1 byte. Bit 0 ứng với đoạn a, bit 1 với b, …, bit 6 với g, bit 7 với dấu chấm DP:

```
   aaa
  f   b
   ggg
  e   c
   ddd  .DP
```

```cpp
const byte GLYPH_DASH = 0b01000000;  // chỉ đoạn g          -> "-"
const byte GLYPH_H    = 0b01110110;  // b c e f g           -> "H"
const byte GLYPH_J    = 0b00011110;  // b c d e             -> "J"
const byte GLYPH_E    = 0b01111001;  // a d e f g           -> "E"
const byte DOT        = 0b10000000;  // dấu chấm
```

Muốn thêm ký tự mới, liệt kê các đoạn cần sáng rồi bật đúng các bit đó lên 1.

### 5.3. `uiTask()`: quét màn hình và bàn phím

```cpp
void uiTask(void *) {
  byte cur = 0;
  byte tick = 0;
  for (;;) {
    bool blinkOff = (millis() / 300) % 2;          // cứ 300 ms đảo trạng thái nhấp nháy

    digitalWrite(digitPins[cur], DIG_OFF);         // tắt LED đang sáng
    cur ^= 1;                                      // chuyển sang LED kia
    writeSegments((displayBlink && blinkOff) ? GLYPH_BLANK : displayBuf[cur]);
    digitalWrite(digitPins[cur], DIG_ON);          // bật LED kia

    for (byte i = 0; i < 4; i++) {                 // LED A-D
      bool on = ledMode[i] == LED_MODE_ON || (ledMode[i] == LED_MODE_BLINK && !blinkOff);
      digitalWrite(ledPins[i], on ? LED_ON : LED_OFF);
    }

    if (++tick >= 5) {                             // mỗi 5 ms quét phím một lần
      tick = 0;
      char k = getKey();
      if (k) xQueueSend(keyQueue, &k, 0);
    }

    vTaskDelay(1);                                 // nghỉ 1 ms, nhường CPU cho Wi-Fi và loop()
  }
}
```

- **Quét LED (multiplex):** hai LED dùng chung 8 dây đoạn, nên mỗi lúc chỉ bật được một LED. Đổi qua lại mỗi 1 ms đủ nhanh để mắt thấy cả hai cùng sáng.
- `scanKey()`, `getKey()` và `writeSegments()` giữ nguyên từ `codenayne.ino`. `getKey()` có chống dội phím 30 ms và chỉ trả về phím ở lúc **vừa nhấn xuống**.
- Task được tạo trong `setup()`:
  ```cpp
  xTaskCreatePinnedToCore(uiTask, "ui", 4096, NULL, 2, NULL, 1);
  ```
  Tham số `2` là độ ưu tiên, cao hơn `loop()` (ưu tiên 1), nên màn hình luôn được quét đúng giờ. Tham số `1` là chạy trên core 1, còn Wi-Fi chạy trên core 0.

### 5.4. Các trạng thái của thiết bị

Biến `mode` cho biết thiết bị đang ở bước nào:

```
                 bật máy
                    │
     có session_id đã lưu trong flash?
          │ không                 │ có
          ▼                       ▼
   INPUT_STUDENT "HS"          RESUME "--" ──── hỏi session_state.php ───┐
          │ 2 số + #                                                     │
          ▼                                                              │
   INPUT_CODE "JC"                                                       │
          │ 2 số + #  → join.php trả OK                                  │
          ▼                                                              │
   WAITING "--"  ◄───────────────────────────────── server: WAITING ─────┤
          │ server: ACTIVE                                               │
          ▼                                                              │
   ACTIVE "01", "02"… ◄──────────────────────────── server: ACTIVE ──────┘
          │ server: ENDED
          ▼
   ENDED "En" ── bấm phím bất kỳ ──► INPUT_STUDENT
```

```cpp
enum Mode {
  MODE_RESUME,         // vừa khởi động lại, đang kiểm tra phiên đã lưu
  MODE_INPUT_STUDENT,  // nhập mã học sinh
  MODE_INPUT_CODE,     // nhập mã tham gia
  MODE_WAITING,        // đã vào phòng, chờ giáo viên bấm Start
  MODE_ACTIVE,         // đang có câu hỏi
  MODE_ENDED           // quiz đã kết thúc
};
```

### 5.5. Nhập 2 chữ số

```cpp
byte inputCount = 0;                 // đã nhập 0, 1 hay 2 chữ số
byte inputTens = 0, inputUnits = 0;

void typeDigit(byte d) {
  if (inputCount == 1) { inputUnits = d; inputCount = 2; }  // đang chờ số thứ 2
  else                 { inputTens  = d; inputCount = 1; }  // ô trống hoặc đã đủ 2 số: bắt đầu số mới
}
```

Đây là điểm khác với `codenayne.ino`. Code cũ bỏ qua số thứ ba cho đến khi bấm `*`. Code mới cho số thứ ba bắt đầu một cặp mới, đúng như yêu cầu "24 → 32 → 15".

Khi bấm `#`, hàm `takeInput()` ghép hai chữ số thành chuỗi `"24"` để gửi lên server. Server yêu cầu **chuỗi đúng 2 chữ số**, không phải số `24`.

### 5.6. Gọi API: `sendRequest()`

```cpp
bool sendRequest(const String &path, const String &postBody, String &response) {
  HTTPClient http;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(serverBase + path)) return false;  // serverBase do findServer() tìm ra

  int httpCode;
  if (postBody.length() > 0) {
    http.addHeader("Content-Type", "application/json");
    httpCode = http.POST(postBody);
  } else {
    httpCode = http.GET();
  }

  if (httpCode > 0) response = http.getString();
  http.end();
  return httpCode > 0;
}
```

- Có `postBody` thì gửi **POST** (dùng cho join và answer). Không có thì gửi **GET** (dùng cho session_state).
- `httpCode > 0` nghĩa là server có trả lời. Hàm trả về `true` **kể cả khi server báo lỗi**. Ví dụ server trả `{"status":"ERROR","reason":"invalid_join_code"}` kèm HTTP 200: đây là câu trả lời hợp lệ và cần đọc nội dung.
- `httpCode <= 0` nghĩa là không tới được server (mất mạng, sai IP). Khi đó hàm trả `false` và nơi gọi hiện `E1`.

Trong code thật, đoạn trên là `httpRequest()`. `sendRequest()` chọn đường: đã chuyển sang Bluetooth thì gọi `bleRequest()`, không thì `httpRequest()`. `bleRequest()` ghi `"<số thứ tự>\n<path>\n<postBody>"` vào characteristic yêu cầu rồi báo cho máy chủ, sau đó chờ tối đa `HTTP_TIMEOUT_MS` để máy chủ ghi lại `"<số thứ tự>\n1\n<nội dung server trả>"`. Các hàm `joinSession()`, `pollSession()`, `sendAnswer()` không phân biệt hai đường. Đổi đường bằng `toggleConnection()` (bấm `**`): `startBle()` tắt Wi-Fi rồi bật BLE, `startWifi()` tắt BLE rồi vào lại Wi-Fi.

### 5.7. Đọc JSON: `jsonText()` và `jsonNumber()`

Server luôn trả JSON gọn, một tầng, không có dấu cách, ví dụ:

```json
{"status":"ACTIVE","session_id":15,"question_id":123,"question_order":1}
```

Nên chỉ cần tìm chuỗi là đủ, không cần cài thư viện ArduinoJson:

```cpp
// "status":"ACTIVE"  → tìm  "status":"  rồi lấy chữ tới dấu " tiếp theo → ACTIVE
String jsonText(const String &json, const char *key);

// "question_id":123  → tìm  "question_id":  rồi đọc số phía sau → 123 (không có thì trả -1)
long jsonNumber(const String &json, const char *key);
```

### 5.8. Bước ①: `joinSession()`

Hàm này được gọi khi học sinh bấm `#` ở bước `JC`.

```cpp
String body = String("{\"device_id\":\"") + DEVICE_ID
            + "\",\"student_id\":\"" + studentId
            + "\",\"join_code\":\"" + joinCode + "\"}";
// → {"device_id":"01","student_id":"05","join_code":"42"}
```

| Server trả | Code làm gì |
|---|---|
| `{"status":"OK","session_id":15}` | `saveSession(15)` để lưu vào flash, chuyển sang `MODE_WAITING`, đặt `pollNow = true` để hỏi trạng thái ngay |
| `invalid_join_code`, `session_not_waiting` | Hiện `E3`/`E4`, xóa ô nhập, ở lại bước `JC` |
| `student_not_in_class`, `student_already_joined` | Hiện `E5`/`E6`, quay về bước `HS` |
| Không tới được server | Hiện `E1`, **giữ nguyên số đã nhập**, bấm `#` để thử lại |

### 5.9. Bước ②: `pollSession()`

`loop()` gọi hàm này mỗi 1 giây khi đang ở `RESUME`, `WAITING` hoặc `ACTIVE`:

```cpp
GET session_state.php?device_id=01&session_id=15
```

```cpp
} else if (status == "ACTIVE") {
  long newQuestionId = jsonNumber(response, "question_id");
  if (mode != MODE_ACTIVE || newQuestionId != questionId) {
    // Câu mới (hoặc vừa Start): nhớ question_id, mở lại cho chọn đáp án
    questionId    = newQuestionId;
    questionOrder = jsonNumber(response, "question_order");
    chosen   = 0;
    answered = false;
  }
  mode = MODE_ACTIVE;
}
```

Đây là chỗ thiết bị **phát hiện giáo viên bấm Start hoặc Next**. Khi `question_id` khác với số đang nhớ, thiết bị biết là đã có câu mới, nên xóa đáp án cũ và cho chọn lại.

- `WAITING`: hiện `--`.
- `ENDED`: xóa `session_id` khỏi flash, hiện `En`.
- Không tới được server: không đổi gì, giây sau hỏi lại.

### 5.10. Bước ③: `sendAnswer()`

Hàm này được gọi khi học sinh đã chọn A–D và bấm `#`.

```cpp
String body = String("{\"device_id\":\"") + DEVICE_ID
            + "\",\"session_id\":" + sessionId        // từ bước ①
            + ",\"question_id\":" + questionId        // từ bước ②, lần hỏi gần nhất
            + ",\"answer\":\"" + chosen + "\"}";
// → {"device_id":"01","session_id":15,"question_id":123,"answer":"B"}
```

| Server trả | Code làm gì |
|---|---|
| `OK` | `answered = true`: LED sáng đứng yên, hiện dấu chấm, khóa phím đến câu sau |
| `already_answered` | Hiện `E7`, khóa câu này |
| `stale_question`, `session_not_active` | Giáo viên vừa chuyển câu hoặc kết thúc: bỏ lựa chọn, `pollNow = true` để hỏi lại ngay |
| Không tới được server | Hiện `E1`, **giữ đáp án đang chọn**, bấm `#` để gửi lại |

Nút bấm chỉ gửi yêu cầu, còn **server mới là bên quyết định** có nhận hay không. Biến `answered` ở ESP chỉ để hiển thị cho học sinh. Kể cả có lỗi ở ESP, server vẫn chỉ nhận một đáp án cho mỗi câu.

### 5.11. `handleKey()`: mỗi phím làm gì ở từng bước

| Bước | `0`–`9` | `A`–`D` | `#` | `*` |
|---|---|---|---|---|
| `HS` / `JC` | Nhập số | — | Đủ 2 số thì xác nhận hoặc gửi | Xóa ô, hoặc quay về `HS` |
| `--` (chờ) | — | — | — | — |
| `01` (câu hỏi) | — | Chọn đáp án | Gửi đáp án | Bỏ chọn |
| `En` | Về `HS` | Về `HS` | Về `HS` | Về `HS` |

Phím nào cũng tắt mã lỗi đang hiện. Bấm `*` hai lần liền (trong 0,5 giây) ở bất kỳ bước nào thì không làm việc trong bảng mà đổi Wi-Fi ↔ Bluetooth (mục 3.6).

### 5.12. `render()`: từ trạng thái ra hình hiển thị

Mỗi vòng `loop()` gọi `render()` một lần. Hàm này nhìn `mode`, `inputCount`, `chosen`, `answered`, `errorCode` rồi quyết định 2 ký tự và 4 LED cần hiện, sau đó ghi vào `displayBuf` và `ledMode` để `uiTask()` hiển thị. Thứ tự ưu tiên:

1. Mất Wi-Fi: `--` nhấp nháy.
2. Đang có lỗi (trong vòng 2 giây): `Ex`.
3. Còn lại hiển thị theo `mode`, như bảng ở mục 4.

### 5.13. `loop()`

```cpp
void loop() {
  // 1) Lấy phím từ hàng đợi (uiTask bỏ vào)
  while (xQueueReceive(keyQueue, &key, 0) == pdTRUE) {
    if (WiFi.status() == WL_CONNECTED) handleKey(key);
  }

  // 2) Đã vào phòng thì cứ mỗi giây hỏi session_state.php một lần
  if (inSession && WiFi.status() == WL_CONNECTED &&
      (pollNow || millis() - lastPollAt >= POLL_INTERVAL_MS)) {
    pollNow = false;
    lastPollAt = millis();
    pollSession();
  }

  // 3) Cập nhật màn hình theo trạng thái mới
  render();
  delay(5);
}
```

Code dùng `millis() - lastPollAt >= …` thay cho `delay(1000)`, nên `loop()` không bị đứng 1 giây và phím vẫn được xử lý ngay.

### 5.14. Lưu `session_id` vào flash

Server **chỉ cho join khi giáo viên chưa bấm Start**. Nếu ESP mất điện giữa buổi mà quên `session_id`, nó sẽ không vào lại được phòng. Vì vậy code lưu `session_id` bằng thư viện `Preferences`:

```cpp
prefs.putLong("session", id);              // lưu khi join thành công
sessionId = prefs.getLong("session", 0);   // đọc lại khi khởi động (setup)
prefs.remove("session");                   // xóa khi ENDED hoặc server báo không còn phiên
```

Khi bật lại máy mà flash còn `session_id`, thiết bị vào `MODE_RESUME`, hỏi server một lần, rồi quay lại đúng câu đang chạy. Lưu ý: thiết bị không nhớ mình đã trả lời câu hiện tại chưa. Nếu học sinh gửi lại, server sẽ báo `E7`.

---

## 6. Kiểm tra và gỡ lỗi

### 6.1. Log mẫu trên Serial Monitor (115200 baud)

```
== Thiet bi 01, phien da luu: 0 ==
Da ket noi Wi-Fi, IP cua ESP: 10.0.x.x
Phim: 0
Phim: 5
Phim: #
Ma hoc sinh: 05
Phim: 4
Phim: 2
Phim: #
POST join.php {"device_id":"01","student_id":"05","join_code":"42"}
  -> {"status":"OK","session_id":15}
session_state -> {"status":"WAITING","session_id":15}
session_state -> {"status":"ACTIVE","session_id":15,"question_id":123,"question_order":1}
  => Cau 1 (question_id 123)
Phim: B
Phim: #
POST answer.php {"device_id":"01","session_id":15,"question_id":123,"answer":"B"}
  -> {"status":"OK"}
```

`session_state` chỉ được in ra khi kết quả thay đổi, để log không bị lặp mỗi giây.

### 6.2. Thử API bằng máy tính, không cần ESP

```bash
curl "http://10.0.152.147/iot/api/session_state.php?device_id=01&session_id=15"

curl -X POST http://10.0.152.147/iot/api/join.php \
  -H "Content-Type: application/json" \
  -d '{"device_id":"01","student_id":"05","join_code":"42"}'
```

Lúc viết tài liệu này, cả 3 API đều trả `device_not_registered` cho thiết bị `01`, vì bảng `devices` vẫn trống.

### 6.3. Lỗi thường gặp

| Hiện tượng | Nguyên nhân thường gặp |
|---|---|
| `--` nhấp nháy mãi | Sai tên hoặc mật khẩu Wi-Fi, hoặc mạng là 5 GHz. Muốn dùng Bluetooth thì bấm `**` |
| `--.` nhấp nháy mãi | Đã sang Bluetooth nhưng máy chủ chưa kết nối: bật Bluetooth (`bluetoothctl power on`) và chạy `ble_gateway/run.sh` |
| Muốn quay lại Wi-Fi | Bấm nhanh `*` hai lần (hoặc khởi động lại ESP) |
| Bấm `#` thì hiện `E1` | ESP chưa tìm thấy hoặc không tới được máy chủ. Xem Serial Monitor và mục 3.2 |
| `E2` | Chưa `INSERT` thiết bị vào bảng `devices` |
| Đã vào phòng nhưng giáo viên bấm Start mà vẫn hiện `--` | Xem Serial: `session_state` có báo lỗi không |
| Một LED 7 đoạn tối hơn hoặc nháy | Kiểm tra transistor PNP và `DIG_VIA_PNP` |
| Phím không ăn | Kiểm tra điện trở kéo lên 10 kΩ ở các chân cột 34–39 |

---

## 7. So với `codenayne.ino`

| | `codenayne.ino` | `quiz_client.ino` |
|---|---|---|
| Đấu chân, `scanKey`, `getKey`, `writeSegments` | | Giữ nguyên |
| Quét LED 7 đoạn | `refreshDisplay()` trong `loop()` | `uiTask()` chạy song song |
| Nhập số thứ ba | Bỏ qua, phải bấm `*` | Bắt đầu cặp số mới |
| `#` | Không làm gì | Xác nhận, gửi join, gửi đáp án |
| LED A–D | Sáng khi bấm phím | Nhấp nháy khi đã chọn, sáng đứng yên khi đã gửi |
| Wi-Fi, HTTP, lưu flash | Không có | Có |
| Bluetooth dự phòng | Không có | Có (mục 3.6) |

## 8. Giới hạn hiện tại

- **Chưa chạy trên mạch thật.** Mới biên dịch thành công và kiểm tra định dạng phản hồi của server.
- Thiết bị nhận diện bằng `DEVICE_ID` 2 chữ số, không có mật khẩu, và gửi HTTP không mã hóa. Chỉ dùng trong mạng LAN. Đường Bluetooth cũng không ghép đôi, không mã hóa.
- Đã sang Bluetooth thì không tự quay về Wi-Fi; bấm `*` hai lần để quay lại.
- Server không giới hạn thời gian trả lời. Câu còn mở thì vẫn gửi được.
- Nếu flash bị xóa trong lúc đang ở trong phòng (ví dụ nạp lại code giữa buổi), thiết bị không vào lại được phòng đó (`E6`).
