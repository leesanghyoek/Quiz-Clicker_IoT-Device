// ======================================================================
//  ESP32 - THIẾT BỊ TRẢ LỜI QUIZ
//  Phần cứng giống codenayne.ino: bàn phím 4x4, 2 LED 7 đoạn, 4 LED A-D.
//  Nói chuyện với server bằng HTTP + JSON qua 3 API:
//    join.php          -> vào phòng, nhận session_id
//    session_state.php -> mỗi giây hỏi: chờ / đang ở câu nào / đã kết thúc
//    answer.php        -> gửi đáp án A-D cho câu hiện tại
//  Bluetooth: bật máy là dùng Wi-Fi; bấm nhanh * hai lần để đổi qua lại
//  Wi-Fi <-> Bluetooth (BLE). Ở BLE, máy tính chạy ble_gateway.py: nhận yêu cầu
//  qua Bluetooth rồi gọi đúng 3 API trên.
//  Hướng dẫn chi tiết: /var/www/html/iot/Code.md
// ======================================================================
#include <WiFi.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <ESPmDNS.h>
#include <NimBLEDevice.h>  // thư viện "NimBLE-Arduino"

// ======================================================================
//  CẤU HÌNH - SỬA Ở ĐÂY
// ======================================================================
const char *WIFI_SSID     = "buidinhkhoi";
const char *WIFI_PASSWORD = "12032005s";
const char *DEVICE_ID     = "01";  // mỗi ESP một mã riêng, phải có trong bảng devices

// Tìm máy chủ: ESP tự tìm IP của máy tính theo tên, nên đổi mạng không cần sửa IP.
// Thứ tự thử: 1) tên mDNS  2) IP lần trước tìm được  3) cổng Wi-Fi (khi ESP vào
// hotspot của chính máy chủ)  4) IP dự phòng. IP nào trả lời API thì dùng IP đó.
const char *SERVER_HOSTNAME = "buidinhkhoi-LOQ-15ARP9";  // lệnh `hostname` trên máy chủ, không có ".local"
const char *SERVER_FALLBACK = "10.42.0.1";               // IP hotspot của máy chủ
const char *SERVER_PATH     = "/iot/api/";               // nhớ dấu / ở cuối

const unsigned long POLL_INTERVAL_MS = 1000;  // bao lâu hỏi session_state.php một lần
const unsigned long ERROR_SHOW_MS    = 2000;  // mã lỗi Ex hiện trong bao lâu
const uint16_t      HTTP_TIMEOUT_MS  = 3000;  // chờ server tối đa bao lâu
const uint16_t      PROBE_TIMEOUT_MS = 1500;  // thử một IP khi tìm máy chủ
const unsigned long SEARCH_RETRY_MS  = 10000; // chưa tìm thấy thì bao lâu tìm lại
const byte          FAILS_BEFORE_SEARCH = 3;  // mất liên lạc bấy nhiêu lần liên tiếp thì tìm lại

// Bluetooth: bấm * hai lần trong DOUBLE_PRESS_MS để đổi Wi-Fi <-> BLE.
// 3 UUID dưới phải giống hệt trong ble_gateway.py.
const unsigned long DOUBLE_PRESS_MS = 500;   // bấm * lần hai trong bấy lâu = đổi Wi-Fi/Bluetooth
const char *BLE_SERVICE_UUID  = "5ca7d9a4-0492-45e2-aaff-dbb32658be6b";
const char *BLE_REQUEST_UUID  = "e6dd137a-6c6c-4142-884e-51e4a17a9211";  // ESP -> máy tính: yêu cầu
const char *BLE_RESPONSE_UUID = "3551aab3-72c0-4dec-9f9a-259bffe6ccbe";  // máy tính -> ESP: trả lời
// Chỉ nhận kết nối Bluetooth từ máy tính có địa chỉ này; máy khác kết nối vào bị ngắt ngay.
// Lấy địa chỉ trên máy chủ: bluetoothctl show (dòng "Controller"). Để "" thì nhận mọi máy.
const char *BLE_GATEWAY_ADDRESS = "C0:35:32:41:FB:C6";

// ======================================================================
//  CHÂN GPIO (giống codenayne.ino)
// ======================================================================
//                          A   B   C   D   E   F   G   DP
const byte segPins[8]   = { 21, 26, 25, 27, 23, 17, 22, 16 };
const byte digitPins[2] = { 19, 18 };       // [0] = LED trái (hàng chục), [1] = LED phải (hàng đơn vị)
const byte ledPins[4]   = { 4, 5, 15, 2 };  // LED cho đáp án A, B, C, D

const bool DIG_VIA_PNP     = true;   // true: anode qua transistor PNP; false: nối thẳng GPIO
const bool LED_ACTIVE_HIGH = true;   // false nếu LED đơn sáng khi GPIO = LOW

const byte ROWS = 4, COLS = 4;
const char keys[ROWS][COLS] = {
  {'*','0','#','D'},
  {'7','8','9','C'},
  {'4','5','6','B'},
  {'1','2','3','A'}
};
const byte rowPins[ROWS] = {33, 32, 14, 13};
const byte colPins[COLS] = {34, 35, 36, 39};  // chân chỉ đọc, cần điện trở kéo lên 10k bên ngoài

// ======================================================================
//  HÌNH TRÊN LED 7 ĐOẠN (bit0 = A ... bit6 = G, bit7 = DP)
// ======================================================================
const byte DIGIT_PATTERN[10] = {
  0b0111111, 0b0000110, 0b1011011, 0b1001111, 0b1100110,
  0b1101101, 0b1111101, 0b0000111, 0b1111111, 0b1101111
};
const byte GLYPH_BLANK = 0b00000000;
const byte GLYPH_DASH  = 0b01000000;  // -
const byte GLYPH_H     = 0b01110110;  // H
const byte GLYPH_S     = 0b01101101;  // S (giống số 5)
const byte GLYPH_J     = 0b00011110;  // J
const byte GLYPH_C     = 0b00111001;  // C
const byte GLYPH_E     = 0b01111001;  // E
const byte GLYPH_n     = 0b01010100;  // n
const byte DOT         = 0b10000000;  // dấu chấm DP

const byte SEG_ON  = LOW,  SEG_OFF = HIGH;           // anode chung: đoạn sáng khi LOW
const byte DIG_ON  = DIG_VIA_PNP ? LOW  : HIGH;      // PNP đảo mức
const byte DIG_OFF = DIG_VIA_PNP ? HIGH : LOW;
const byte LED_ON  = LED_ACTIVE_HIGH ? HIGH : LOW;
const byte LED_OFF = LED_ACTIVE_HIGH ? LOW  : HIGH;

// ======================================================================
//  DỮ LIỆU DÙNG CHUNG GIỮA loop() VÀ uiTask()
//  loop() chỉ ghi vào đây; uiTask() đọc ra để quét LED và gửi phím về.
// ======================================================================
enum LedMode : byte { LED_MODE_OFF, LED_MODE_ON, LED_MODE_BLINK };

volatile byte displayBuf[2] = {GLYPH_BLANK, GLYPH_BLANK};
volatile bool displayBlink  = false;
volatile byte ledMode[4]    = {LED_MODE_OFF, LED_MODE_OFF, LED_MODE_OFF, LED_MODE_OFF};
QueueHandle_t keyQueue;     // hàng đợi phím: uiTask() bỏ vào, loop() lấy ra

// ======================================================================
//  TRẠNG THÁI CHƯƠNG TRÌNH
// ======================================================================
enum Mode {
  MODE_RESUME,         // vừa khởi động lại, đang kiểm tra phiên đã lưu
  MODE_INPUT_STUDENT,  // nhập mã học sinh
  MODE_INPUT_CODE,     // nhập mã tham gia
  MODE_WAITING,        // đã vào phòng, chờ giáo viên bấm Start
  MODE_ACTIVE,         // đang có câu hỏi
  MODE_ENDED           // quiz đã kết thúc
};
Mode mode = MODE_INPUT_STUDENT;

byte inputCount = 0;           // số chữ số đã nhập: 0, 1 hoặc 2
byte inputTens = 0, inputUnits = 0;
char studentId[3] = "";
char joinCode[3]  = "";

long sessionId     = 0;        // nhận từ join.php
long questionId    = 0;        // nhận từ session_state.php
long questionOrder = 0;
char chosen        = 0;        // 'A'..'D' đã chọn, 0 = chưa chọn
bool answered      = false;    // đã gửi thành công cho câu này chưa

bool pollNow = true;
unsigned long lastPollAt = 0;

byte errorCode = 0;
unsigned long errorAt = 0;

String serverBase = "";            // "http://<ip>/iot/api/" của máy chủ đang dùng; rỗng = chưa tìm thấy
bool searching = false;            // đang tìm máy chủ (màn hình "--" nhấp nháy)
unsigned long lastSearchAt = 0;
byte failCount = 0;                // số lần liên tiếp không tới được máy chủ

bool bleMode = false;                     // đã chuyển sang Bluetooth
volatile bool bleGatewayReady = false;    // máy tính đã kết nối và đang nghe yêu cầu
NimBLECharacteristic *bleRequestChar = nullptr;
SemaphoreHandle_t bleReplyLock;
String bleReply;                          // trả lời mới nhất từ máy tính
volatile uint32_t bleReplySeq = 0;        // trả lời đó cho yêu cầu số mấy
uint32_t bleSeq = 0;                      // số thứ tự yêu cầu gần nhất

bool wifiWasConnected = false;    // để biết lúc vừa vào được Wi-Fi
bool starPending = false;         // vừa bấm * một lần, chờ xem có bấm lần hai không
unsigned long starAt = 0;

Preferences prefs;

// ======================================================================
//  LED 7 ĐOẠN + LED A-D + BÀN PHÍM (chạy trong uiTask)
// ======================================================================
void writeSegments(byte pattern) {
  for (byte i = 0; i < 8; i++)
    digitalWrite(segPins[i], ((pattern >> i) & 1) ? SEG_ON : SEG_OFF);
}

char scanKey() {
  for (byte r = 0; r < ROWS; r++) {
    pinMode(rowPins[r], OUTPUT);
    digitalWrite(rowPins[r], LOW);
    delayMicroseconds(50);
    for (byte c = 0; c < COLS; c++) {
      if (digitalRead(colPins[c]) == LOW) {
        pinMode(rowPins[r], INPUT_PULLUP);
        return keys[r][c];
      }
    }
    pinMode(rowPins[r], INPUT_PULLUP);
  }
  return 0;
}

char getKey() {
  static char lastRaw = 0, stable = 0;
  static unsigned long t = 0;
  char k = scanKey();
  if (k != lastRaw) { lastRaw = k; t = millis(); }
  if (millis() - t > 30 && k != stable) {
    stable = k;
    return k;
  }
  return 0;
}

// Chạy song song với loop(). Mỗi 1 ms: đổi LED 7 đoạn đang sáng, cập nhật LED A-D;
// mỗi 5 ms quét bàn phím. Nhờ vậy màn hình không nháy và không mất phím khi loop() đang chờ server.
void uiTask(void *) {
  byte cur = 0;
  byte tick = 0;
  for (;;) {
    bool blinkOff = (millis() / 300) % 2;

    digitalWrite(digitPins[cur], DIG_OFF);
    cur ^= 1;
    writeSegments((displayBlink && blinkOff) ? GLYPH_BLANK : displayBuf[cur]);
    digitalWrite(digitPins[cur], DIG_ON);

    for (byte i = 0; i < 4; i++) {
      bool on = ledMode[i] == LED_MODE_ON || (ledMode[i] == LED_MODE_BLINK && !blinkOff);
      digitalWrite(ledPins[i], on ? LED_ON : LED_OFF);
    }

    if (++tick >= 5) {
      tick = 0;
      char k = getKey();
      if (k) xQueueSend(keyQueue, &k, 0);
    }

    vTaskDelay(1);
  }
}

// ======================================================================
//  GỌI API
// ======================================================================
// Gửi một yêu cầu lên server. postBody rỗng -> GET, có nội dung -> POST JSON.
// Trả về true nếu server có trả lời (kể cả trả lời lỗi), nội dung nằm trong response.
// Trả về false nếu không tới được server (mất Wi-Fi, sai IP, server tắt...).
bool httpRequest(const String &path, const String &postBody, String &response);
bool bleRequest(const String &path, const String &postBody, String &response);

bool sendRequest(const String &path, const String &postBody, String &response) {
  return bleMode ? bleRequest(path, postBody, response)
                 : httpRequest(path, postBody, response);
}

bool httpRequest(const String &path, const String &postBody, String &response) {
  if (serverBase.length() == 0) return false;  // chưa tìm thấy máy chủ

  HTTPClient http;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(serverBase + path)) return false;

  int httpCode;
  if (postBody.length() > 0) {
    http.addHeader("Content-Type", "application/json");
    httpCode = http.POST(postBody);
  } else {
    httpCode = http.GET();
  }

  if (httpCode > 0) {
    response = http.getString();
    failCount = 0;
  } else {
    Serial.printf("  Loi ket noi: %s\n", http.errorToString(httpCode).c_str());
    // Mất liên lạc nhiều lần liên tiếp: có thể máy chủ đã đổi IP -> tìm lại.
    if (++failCount >= FAILS_BEFORE_SEARCH) {
      failCount = 0;
      serverBase = "";
    }
  }
  http.end();
  return httpCode > 0;
}

// ======================================================================
//  TÌM MÁY CHỦ
// ======================================================================
// Hỏi thử session_state.php ở một IP. Máy chủ đúng luôn trả về JSON có "status"
// (kể cả lỗi thiếu tham số), nên chỉ cần thấy chữ đó là biết đúng chỗ.
bool probeServer(const IPAddress &ip) {
  if (ip == IPAddress(0, 0, 0, 0)) return false;

  String base = String("http://") + ip.toString() + SERVER_PATH;
  HTTPClient http;
  http.setConnectTimeout(PROBE_TIMEOUT_MS);
  http.setTimeout(PROBE_TIMEOUT_MS);
  if (!http.begin(base + "session_state.php")) return false;

  int httpCode = http.GET();
  bool ok = httpCode > 0 && http.getString().indexOf("\"status\"") >= 0;
  http.end();
  Serial.printf("  Thu %s -> %s\n", ip.toString().c_str(), ok ? "OK" : "khong tra loi");

  if (ok) serverBase = base;
  return ok;
}

void findServer() {
  searching = true;
  render();  // hiện "--" nhấp nháy trong lúc tìm
  Serial.println("Dang tim may chu...");

  IPAddress ip;
  bool found = false;

  // 1) Theo tên: máy chủ Linux (avahi) tự trả lời "<hostname>.local".
  ip = MDNS.queryHost(SERVER_HOSTNAME, 2000);
  Serial.printf("  mDNS %s.local -> %s\n", SERVER_HOSTNAME, ip.toString().c_str());
  found = probeServer(ip);

  // 2) IP lần trước tìm được (lưu trong flash).
  if (!found && ip.fromString(prefs.getString("server", ""))) found = probeServer(ip);

  // 3) Cổng Wi-Fi: ESP đang vào hotspot của chính máy chủ thì máy chủ là cổng.
  if (!found) found = probeServer(WiFi.gatewayIP());

  // 4) IP dự phòng.
  if (!found && ip.fromString(SERVER_FALLBACK)) found = probeServer(ip);

  if (found) {
    Serial.print("Tim thay may chu: ");
    Serial.println(serverBase);
    String host = serverBase.substring(7, serverBase.indexOf('/', 7));
    if (prefs.getString("server", "") != host) prefs.putString("server", host);
  } else {
    Serial.println("Chua tim thay may chu, se thu lai.");
  }

  failCount = 0;
  lastSearchAt = millis();
  searching = false;
}

// ======================================================================
//  DỰ PHÒNG BLUETOOTH (BLE)
// ======================================================================
// ESP là thiết bị BLE tên "quiz-esp-<mã>", phát quảng bá BLE_SERVICE_UUID.
// ble_gateway.py trên máy tính tìm thấy thì kết nối và đăng ký nghe BLE_REQUEST_UUID.
// Một yêu cầu: ESP ghi "<số thứ tự>\n<path>\n<postBody>" vào BLE_REQUEST_UUID rồi báo
// (notify); máy tính đọc, gọi API qua HTTP rồi ghi vào BLE_RESPONSE_UUID:
// "<số thứ tự>\n1\n<nội dung server trả>", hoặc "<số thứ tự>\n0\n" nếu không tới được server.

// Core ESP32 tự giải phóng bộ nhớ Bluetooth lúc khởi động nếu tưởng không dùng;
// NimBLE-Arduino không báo nên phải tự khai là có dùng.
extern "C" bool bleInUse(void) { return true; }

// Máy đang kết nối có phải máy chủ của mình không (so địa chỉ, không phân biệt hoa thường).
bool isOurGateway(NimBLEConnInfo &info) {
  if (BLE_GATEWAY_ADDRESS[0] == '\0') return true;
  return strcasecmp(info.getAddress().toString().c_str(), BLE_GATEWAY_ADDRESS) == 0;
}

class BleServerCallbacks : public NimBLEServerCallbacks {
  void onConnect(NimBLEServer *server, NimBLEConnInfo &info) override {
    if (!isOurGateway(info)) {
      // Máy lạ (laptop, điện thoại khác...): ngắt ngay để nó không giữ chỗ của máy chủ.
      // Ngắt xong onDisconnect chạy, ESP quảng bá lại cho máy chủ tìm.
      Serial.printf("BLE: tu choi may la %s\n", info.getAddress().toString().c_str());
      server->disconnect(info);
      return;
    }
    Serial.printf("BLE: may tinh %s da ket noi\n", info.getAddress().toString().c_str());
  }
  void onDisconnect(NimBLEServer *server, NimBLEConnInfo &info, int reason) override {
    if (!isOurGateway(info)) return;  // máy lạ vừa bị ngắt: máy chủ (nếu đang nối) vẫn còn
    bleGatewayReady = false;
    Serial.printf("BLE: mat ket noi (ly do %d), phat quang ba lai\n", reason);
  }
};

class BleRequestCallbacks : public NimBLECharacteristicCallbacks {
  void onSubscribe(NimBLECharacteristic *chr, NimBLEConnInfo &info, uint16_t subValue) override {
    if (!isOurGateway(info)) return;
    bleGatewayReady = subValue != 0;  // máy tính bật / tắt nghe yêu cầu
  }
};

class BleReplyCallbacks : public NimBLECharacteristicCallbacks {
  void onWrite(NimBLECharacteristic *chr, NimBLEConnInfo &info) override {
    if (!isOurGateway(info)) return;  // chỉ nhận trả lời từ máy chủ của mình
    NimBLEAttValue value = chr->getValue();
    String text(value.c_str(), value.length());
    int lineEnd = text.indexOf('\n');
    if (lineEnd <= 0) return;

    xSemaphoreTake(bleReplyLock, portMAX_DELAY);
    bleReply = text.substring(lineEnd + 1);  // "1\n<nội dung>" hoặc "0\n"
    bleReplySeq = text.substring(0, lineEnd).toInt();
    xSemaphoreGive(bleReplyLock);
  }
};

BleServerCallbacks bleServerCallbacks;
BleRequestCallbacks bleRequestCallbacks;
BleReplyCallbacks bleReplyCallbacks;

// Tắt Wi-Fi, bật Bluetooth và phát quảng bá chờ máy tính kết nối.
void startBle() {
  Serial.println("=> Chuyen sang Bluetooth");
  bleMode = true;
  serverBase = "";
  searching = false;
  MDNS.end();
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);

  String name = String("quiz-esp-") + DEVICE_ID;
  NimBLEDevice::init(name.c_str());
  NimBLEDevice::setMTU(247);  // gói lớn: một yêu cầu đi trọn trong một lần

  NimBLEServer *server = NimBLEDevice::createServer();
  server->setCallbacks(&bleServerCallbacks);
  server->advertiseOnDisconnect(true);

  NimBLEService *service = server->createService(BLE_SERVICE_UUID);
  bleRequestChar = service->createCharacteristic(
      BLE_REQUEST_UUID, NIMBLE_PROPERTY::READ | NIMBLE_PROPERTY::NOTIFY);
  bleRequestChar->setCallbacks(&bleRequestCallbacks);
  NimBLECharacteristic *reply = service->createCharacteristic(BLE_RESPONSE_UUID, NIMBLE_PROPERTY::WRITE);
  reply->setCallbacks(&bleReplyCallbacks);
  server->start();

  NimBLEAdvertising *adv = NimBLEDevice::getAdvertising();
  adv->enableScanResponse(true);  // UUID đã chiếm gần hết gói quảng bá, tên nằm ở gói phụ
  adv->addServiceUUID(BLE_SERVICE_UUID);
  adv->setName(name.c_str());
  adv->start();
  Serial.printf("BLE: dang phat quang ba ten %s\n", name.c_str());
}

// Tắt Bluetooth, bật lại Wi-Fi và tìm máy chủ như lúc mới bật máy.
void startWifi() {
  Serial.println("=> Chuyen sang Wi-Fi");
  NimBLEDevice::deinit(true);
  bleGatewayReady = false;
  bleRequestChar = nullptr;
  bleMode = false;

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  MDNS.begin((String("quiz-esp-") + DEVICE_ID).c_str());

  serverBase = "";
  lastSearchAt = 0;
  failCount = 0;
  wifiWasConnected = false;
}

void toggleConnection() {
  if (bleMode) startWifi();
  else startBle();
  pollNow = true;
}

bool bleRequest(const String &path, const String &postBody, String &response) {
  if (!bleGatewayReady) return false;

  uint32_t seq = ++bleSeq;
  bleRequestChar->setValue(String(seq) + "\n" + path + "\n" + postBody);
  bleRequestChar->notify();

  unsigned long start = millis();
  while (millis() - start < HTTP_TIMEOUT_MS && bleGatewayReady) {
    if (bleReplySeq == seq) {
      xSemaphoreTake(bleReplyLock, portMAX_DELAY);
      String reply = bleReply;
      xSemaphoreGive(bleReplyLock);
      if (!reply.startsWith("1\n")) return false;  // máy tính không tới được server
      response = reply.substring(2);
      return true;
    }
    delay(5);
  }
  Serial.println("  BLE: may tinh khong tra loi");
  return false;
}

// Có đường nói chuyện với máy chủ không: Wi-Fi đã kết nối, hoặc máy tính đã nối Bluetooth.
bool linkUp() {
  return bleMode ? bleGatewayReady : WiFi.status() == WL_CONNECTED;
}

// Lấy giá trị chuỗi trong JSON: "key":"value" -> value
String jsonText(const String &json, const char *key) {
  String pattern = String("\"") + key + "\":\"";
  int start = json.indexOf(pattern);
  if (start < 0) return "";
  start += pattern.length();
  int end = json.indexOf('"', start);
  return end < 0 ? "" : json.substring(start, end);
}

// Lấy giá trị số trong JSON: "key":123 -> 123 (không có thì trả -1)
long jsonNumber(const String &json, const char *key) {
  String pattern = String("\"") + key + "\":";
  int start = json.indexOf(pattern);
  if (start < 0) return -1;
  return json.substring(start + pattern.length()).toInt();
}

// ======================================================================
//  TIỆN ÍCH
// ======================================================================
void showError(byte code) {
  errorCode = code;
  errorAt = millis();
  Serial.printf("  => Hien loi E%u\n", code);
}

bool errorVisible() {
  return errorCode != 0 && millis() - errorAt < ERROR_SHOW_MS;
}

void clearInput() { inputCount = 0; }

// Nhập kiểu "từng cặp": đang có 2 số mà bấm tiếp thì bắt đầu số mới.
// 2,4 -> "24"; bấm 3 -> "3-"; bấm 2 -> "32"; bấm 1,5 -> "15"
void typeDigit(byte d) {
  if (inputCount == 1) { inputUnits = d; inputCount = 2; }
  else                 { inputTens  = d; inputCount = 1; }
}

void takeInput(char out[3]) {
  out[0] = '0' + inputTens;
  out[1] = '0' + inputUnits;
  out[2] = '\0';
}

// session_id được lưu vào bộ nhớ flash để mất điện bật lại vẫn vào tiếp được phòng cũ
void saveSession(long id) {
  sessionId = id;
  prefs.putLong("session", id);
}

void forgetSession() {
  sessionId = 0;
  prefs.remove("session");
}

void backToStart() {
  forgetSession();
  mode = MODE_INPUT_STUDENT;
  clearInput();
}

// ======================================================================
//  BƯỚC 1: join.php - vào phòng, nhận session_id
// ======================================================================
void joinSession() {
  String body = String("{\"device_id\":\"") + DEVICE_ID
              + "\",\"student_id\":\"" + studentId
              + "\",\"join_code\":\"" + joinCode + "\"}";
  Serial.println("POST join.php " + body);

  String response;
  if (!sendRequest("join.php", body, response)) {
    showError(1);  // giữ nguyên số đã nhập, bấm # để thử lại
    return;
  }
  Serial.println("  -> " + response);

  if (jsonText(response, "status") == "OK") {
    long id = jsonNumber(response, "session_id");
    if (id <= 0) { showError(9); return; }
    saveSession(id);
    mode = MODE_WAITING;
    pollNow = true;  // hỏi trạng thái ngay, không chờ 1 giây
    return;
  }

  String reason = jsonText(response, "reason");
  if (reason == "invalid_join_code") {
    showError(3); clearInput();
  } else if (reason == "session_not_waiting") {
    showError(4); clearInput();
  } else if (reason == "student_not_in_class") {
    showError(5); mode = MODE_INPUT_STUDENT; clearInput();
  } else if (reason == "student_already_joined") {
    showError(6); mode = MODE_INPUT_STUDENT; clearInput();
  } else if (reason == "device_already_joined") {
    showError(6); clearInput();
  } else if (reason == "device_not_registered" || reason == "device_inactive") {
    showError(2);
  } else if (reason == "server_error") {
    showError(1);
  } else {
    showError(9);
  }
}

// ======================================================================
//  BƯỚC 2: session_state.php - hỏi tình hình, chạy mỗi giây
// ======================================================================
void pollSession() {
  String path = String("session_state.php?device_id=") + DEVICE_ID
              + "&session_id=" + sessionId;
  String response;
  if (!sendRequest(path, "", response)) return;  // mất mạng: giữ nguyên, lần sau hỏi lại

  static String lastResponse;
  if (response != lastResponse) {  // chỉ in khi có thay đổi cho đỡ rối Serial
    Serial.println("session_state -> " + response);
    lastResponse = response;
  }

  String status = jsonText(response, "status");
  if (status == "WAITING") {
    mode = MODE_WAITING;
  } else if (status == "ACTIVE") {
    long newQuestionId = jsonNumber(response, "question_id");
    if (mode != MODE_ACTIVE || newQuestionId != questionId) {
      // Câu mới (hoặc vừa Start): nhớ question_id, mở lại cho chọn đáp án
      questionId    = newQuestionId;
      questionOrder = jsonNumber(response, "question_order");
      chosen   = 0;
      answered = false;
      Serial.printf("  => Cau %ld (question_id %ld)\n", questionOrder, questionId);
    }
    mode = MODE_ACTIVE;
  } else if (status == "ENDED") {
    forgetSession();
    mode = MODE_ENDED;
  } else {
    String reason = jsonText(response, "reason");
    if (reason == "device_not_joined" || reason == "session_not_found") {
      showError(8); backToStart();
    } else if (reason == "device_not_registered" || reason == "device_inactive") {
      showError(2); backToStart();
    }
    // Lỗi khác (no_current_question...) là tạm thời: lần sau hỏi lại
  }
}

// ======================================================================
//  BƯỚC 3: answer.php - gửi đáp án đã chọn
// ======================================================================
void sendAnswer() {
  String body = String("{\"device_id\":\"") + DEVICE_ID
              + "\",\"session_id\":" + sessionId
              + ",\"question_id\":" + questionId
              + ",\"answer\":\"" + chosen + "\"}";
  Serial.println("POST answer.php " + body);

  String response;
  if (!sendRequest("answer.php", body, response)) {
    showError(1);  // vẫn giữ đáp án đang chọn, bấm # để gửi lại
    return;
  }
  Serial.println("  -> " + response);

  if (jsonText(response, "status") == "OK") {
    answered = true;
    return;
  }

  String reason = jsonText(response, "reason");
  if (reason == "already_answered") {
    showError(7); answered = true; chosen = 0;
  } else if (reason == "stale_question" || reason == "session_not_active") {
    chosen = 0; pollNow = true;  // giáo viên vừa chuyển câu/kết thúc: hỏi lại ngay
  } else if (reason == "device_not_joined" || reason == "session_not_found") {
    showError(8); backToStart();
  } else if (reason == "server_error") {
    showError(1);
  } else {
    showError(9); chosen = 0;  // invalid_option (câu không có lựa chọn này), invalid_request...
  }
}

// ======================================================================
//  XỬ LÝ PHÍM
// ======================================================================
void handleKey(char key) {
  errorCode = 0;  // bấm phím bất kỳ thì tắt mã lỗi đang hiện

  switch (mode) {
    case MODE_INPUT_STUDENT:
    case MODE_INPUT_CODE:
      if (key >= '0' && key <= '9') {
        typeDigit(key - '0');
      } else if (key == '*') {
        if (inputCount > 0) clearInput();
        else if (mode == MODE_INPUT_CODE) mode = MODE_INPUT_STUDENT;  // quay lại sửa mã học sinh
      } else if (key == '#' && inputCount == 2) {
        if (mode == MODE_INPUT_STUDENT) {
          takeInput(studentId);
          Serial.printf("Ma hoc sinh: %s\n", studentId);
          mode = MODE_INPUT_CODE;
          clearInput();
        } else {
          takeInput(joinCode);
          joinSession();
        }
      }
      break;

    case MODE_ACTIVE:
      if (answered) break;  // đã gửi rồi thì khóa đến câu sau
      if (key >= 'A' && key <= 'D') chosen = key;
      else if (key == '*') chosen = 0;
      else if (key == '#' && chosen) sendAnswer();
      break;

    case MODE_RESUME:
      if (key == '*') backToStart();  // bỏ phiên cũ, nhập lại từ đầu
      break;

    case MODE_ENDED:
      backToStart();  // bấm phím bất kỳ để vào phòng mới
      break;

    case MODE_WAITING:
      break;
  }
}

// ======================================================================
//  VẼ MÀN HÌNH: từ trạng thái hiện tại -> 2 LED 7 đoạn + 4 LED A-D
// ======================================================================
void render() {
  byte left = GLYPH_BLANK, right = GLYPH_BLANK;
  bool blink = false;
  byte leds[4] = {LED_MODE_OFF, LED_MODE_OFF, LED_MODE_OFF, LED_MODE_OFF};

  if (!linkUp() || searching) {
    left = right = GLYPH_DASH;  // "--" nhấp nháy: đang kết nối Wi-Fi / tìm máy chủ / chờ máy tính nối Bluetooth
    blink = true;
  } else if (errorVisible()) {
    left  = GLYPH_E;
    right = DIGIT_PATTERN[errorCode];
  } else {
    switch (mode) {
      case MODE_INPUT_STUDENT:
      case MODE_INPUT_CODE:
        if (inputCount == 0) {  // ô trống: hiện lời nhắc HS / JC
          left  = (mode == MODE_INPUT_STUDENT) ? GLYPH_H : GLYPH_J;
          right = (mode == MODE_INPUT_STUDENT) ? GLYPH_S : GLYPH_C;
        } else if (inputCount == 1) {
          left  = DIGIT_PATTERN[inputTens];
          right = GLYPH_DASH;
        } else {
          left  = DIGIT_PATTERN[inputTens];
          right = DIGIT_PATTERN[inputUnits];
        }
        break;

      case MODE_RESUME:
      case MODE_WAITING:
        left = right = GLYPH_DASH;  // "--" đứng yên: chờ giáo viên
        break;

      case MODE_ACTIVE: {
        long q = questionOrder > 0 ? questionOrder % 100 : 0;
        left  = DIGIT_PATTERN[q / 10];
        right = DIGIT_PATTERN[q % 10];
        if (answered) right |= DOT;  // dấu chấm góc phải: câu này đã gửi xong (ở Bluetooth thì luôn sáng, xem LED A-D)
        if (chosen) leds[chosen - 'A'] = answered ? LED_MODE_ON : LED_MODE_BLINK;
        break;
      }

      case MODE_ENDED:
        left = GLYPH_E;  // "En" = End
        right = GLYPH_n;
        break;
    }
  }

  if (bleMode) right |= DOT;  // dấu chấm LED phải: đang dùng Bluetooth

  displayBuf[0] = left;
  displayBuf[1] = right;
  displayBlink  = blink;
  for (byte i = 0; i < 4; i++) ledMode[i] = leds[i];
}

// ======================================================================
void setup() {
  Serial.begin(115200);
  delay(500);

  for (byte c = 0; c < COLS; c++) pinMode(colPins[c], INPUT);
  for (byte r = 0; r < ROWS; r++) pinMode(rowPins[r], INPUT_PULLUP);
  for (byte i = 0; i < 8; i++) { pinMode(segPins[i], OUTPUT);   digitalWrite(segPins[i], SEG_OFF); }
  for (byte i = 0; i < 2; i++) { pinMode(digitPins[i], OUTPUT); digitalWrite(digitPins[i], DIG_OFF); }
  for (byte i = 0; i < 4; i++) { pinMode(ledPins[i], OUTPUT);   digitalWrite(ledPins[i], LED_OFF); }

  prefs.begin("quiz", false);
  sessionId = prefs.getLong("session", 0);
  mode = sessionId > 0 ? MODE_RESUME : MODE_INPUT_STUDENT;
  Serial.printf("\n== Thiet bi %s, phien da luu: %ld ==\n", DEVICE_ID, sessionId);

  keyQueue = xQueueCreate(16, sizeof(char));
  bleReplyLock = xSemaphoreCreateMutex();
  xTaskCreatePinnedToCore(uiTask, "ui", 4096, NULL, 2, NULL, 1);

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  // Tên của ESP trên mạng (quiz-esp-01.local); cần bật mDNS mới hỏi tên máy chủ được.
  MDNS.begin((String("quiz-esp-") + DEVICE_ID).c_str());
}

void loop() {
  // 1) Lấy phím từ hàng đợi (uiTask bỏ vào)
  //    Bấm * hai lần liền thì đổi Wi-Fi <-> Bluetooth (kể cả lúc đang mất kết nối).
  //    Vì vậy bấm * một lần thì chờ DOUBLE_PRESS_MS rồi mới xử lý như bình thường.
  char key;
  while (xQueueReceive(keyQueue, &key, 0) == pdTRUE) {
    Serial.printf("Phim: %c\n", key);
    if (key == '*') {
      if (starPending && millis() - starAt <= DOUBLE_PRESS_MS) {
        starPending = false;
        toggleConnection();
      } else {
        starPending = true;
        starAt = millis();
      }
      continue;
    }
    if (starPending) {  // phím khác tới trước khi hết giờ chờ: * lúc nãy là bấm đơn
      starPending = false;
      if (linkUp()) handleKey('*');
    }
    if (linkUp()) handleKey(key);  // chưa có đường tới máy chủ thì bỏ qua phím
  }
  if (starPending && millis() - starAt > DOUBLE_PRESS_MS) {
    starPending = false;
    if (linkUp()) handleKey('*');
  }

  // 2) Đã vào phòng thì cứ mỗi giây hỏi session_state.php một lần
  bool inSession = mode == MODE_RESUME || mode == MODE_WAITING || mode == MODE_ACTIVE;
  if (inSession && linkUp() &&
      (pollNow || millis() - lastPollAt >= POLL_INTERVAL_MS)) {
    pollNow = false;
    lastPollAt = millis();
    pollSession();
  }

  // 3) Cập nhật màn hình theo trạng thái mới
  render();

  // 4) Đang dùng Bluetooth thì phần Wi-Fi bên dưới không chạy (bấm * hai lần để quay lại Wi-Fi)
  if (bleMode) {
    delay(5);
    return;
  }

  // 5) Vừa có Wi-Fi thì in IP và tìm máy chủ; chưa thấy (hoặc mất liên lạc) thì tìm lại định kỳ
  bool connected = WiFi.status() == WL_CONNECTED;
  if (connected && !wifiWasConnected) {
    Serial.print("Da ket noi Wi-Fi, IP cua ESP: ");
    Serial.println(WiFi.localIP());
    serverBase = "";  // mạng mới: tìm lại máy chủ ngay
    lastSearchAt = 0;
  }
  wifiWasConnected = connected;

  if (connected && serverBase.length() == 0 &&
      (lastSearchAt == 0 || millis() - lastSearchAt >= SEARCH_RETRY_MS)) {
    findServer();
  }

  delay(5);
}
