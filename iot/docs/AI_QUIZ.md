# Tạo quiz bằng AI (AI Quiz)

Giáo viên đưa tài liệu bài học (PDF, Word, PowerPoint, OpenDocument, .txt hoặc dán văn bản), AI (Claude của Anthropic) soạn câu hỏi trắc nghiệm A–D. Giáo viên nhắn để sửa, xem và chỉnh từng câu, rồi lưu thành quiz bình thường để chạy với ESP32/điện thoại.

Vào: **Quizzes → Create with AI** (`ai_quiz.php`).

## Cách dùng
1. **Lesson material:** chọn file hoặc dán văn bản → *Use this material*.
2. **Ask the AI:** chọn số câu, độ khó, ngôn ngữ, ghi thêm yêu cầu nếu muốn → *Write questions*. Mất khoảng 30 giây đến 2 phút.
3. **Sửa bằng chat:** gõ yêu cầu như "Câu 3 khó hơn", "Thêm 5 câu về chương 3", "Rút gọn đáp án" → *Apply change*. AI giữ nguyên các câu không được yêu cầu sửa, kể cả những chỗ giáo viên đã tự sửa trên trang.
4. **Check the questions:** sửa chữ, chọn lại đáp án đúng (nút tròn), xoá hoặc thêm câu. Dưới mỗi câu có lời giải thích và vị trí trong tài liệu để kiểm tra nhanh.
5. Đặt tên, chọn lớp cần giao → *Save quiz*. Quiz được lưu như mọi quiz khác.

Nháp được giữ trong phiên đăng nhập, nên tải lại trang không mất.

## Cài đặt (một lần)

Mọi thứ riêng tư nằm **ngoài thư mục web**, ở `/var/www/iot-private`:

| Đường dẫn | Nội dung | Quyền |
|---|---|---|
| `ai_config.php` | API key và tuỳ chọn | 640, nhóm `www-data` |
| `vendor/` | Thư viện chính thức `anthropic-ai/sdk` + `guzzlehttp/guzzle` | đọc |
| `ai-uploads/` | Tài liệu đang làm (tự xoá sau 1 ngày), `.usage.json` đếm lượt | `www-data` ghi được |

```bash
# 1. Tiện ích PHP, thư mục riêng, giới hạn tải file 20 MB
sudo apt install -y php8.3-curl
sudo install -d -o $USER -g www-data -m 2750 /var/www/iot-private
sudo install -d -o $USER -g www-data -m 2770 /var/www/iot-private/ai-uploads
sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 20M/; s/^post_max_size = .*/post_max_size = 25M/' /etc/php/8.3/apache2/php.ini
sudo systemctl restart apache2

# 2. Thư viện Claude cho PHP
cd /var/www/iot-private
php -r "copy('https://getcomposer.org/download/latest-stable/composer.phar', 'composer.phar');"
php composer.phar require anthropic-ai/sdk guzzlehttp/guzzle

# 3. API key (tạo ở https://console.anthropic.com → API Keys)
cat > ai_config.php <<'EOF'
<?php
return [
    'api_key' => 'DÁN_API_KEY_VÀO_ĐÂY',
    // 'model' => 'claude-opus-5-5',  // mặc định
    // 'effort' => 'high',            // low / medium / high / xhigh / max
    'daily_limit' => 40,              // số lần gọi AI tối đa mỗi giáo viên mỗi ngày
    // 'fallback' => false,           // true (mặc định): bị từ chối thì API thử model dự phòng
];
EOF
chmod 640 ai_config.php
nano ai_config.php    # dán key, lưu bằng Ctrl+O, thoát bằng Ctrl+X
```

Khi chưa cài xong, trang AI Quiz tự liệt kê bước nào còn thiếu.

## Cách hoạt động
- `includes/ai_quiz.php`: đọc cấu hình, lưu và chuyển đổi tài liệu, gọi Claude, kiểm tra kết quả, lưu quiz.
- `api/ai_quiz.php`: trang `ai_quiz.php` gọi API này bằng `fetch`. Mỗi lần gọi trả về JSON là bộ câu hỏi mới.
- `ai_quiz.php`: giao diện, tải tài liệu, xem và sửa, lưu.

Chi tiết kỹ thuật:
- **Model:** `claude-opus-5-5`, effort `high`, dùng thư viện chính thức `anthropic-ai/sdk`.
- **Structured outputs** (`output_config.format` dạng JSON schema): AI luôn trả `{title, reply, questions: [{question, options, correct A–D, explanation, source}]}`. PHP kiểm tra lại từng câu: có nội dung, 2–4 đáp án không rỗng, đáp án đúng trỏ vào một lựa chọn có thật. Câu không hợp lệ bị bỏ và trang báo số câu bị bỏ. Tối đa 99 câu, vì thiết bị chỉ hiện 2 chữ số.
- **Tài liệu:**
  - PDF gửi dạng `document` base64. Claude đọc cả chữ, hình và bảng.
  - Word, PowerPoint, OpenDocument được LibreOffice (`soffice --headless --convert-to pdf`) đổi sang PDF trên máy chủ.
  - Văn bản gửi dạng `document` văn bản.
- **Mỗi lần gọi độc lập:** gồm tài liệu, bộ câu hiện tại (kể cả chỗ giáo viên đã sửa) và yêu cầu. Không phải giữ lịch sử hội thoại với AI. Tài liệu được đánh dấu **prompt caching**, nên các lần sửa tiếp theo trên cùng tài liệu rẻ và nhanh hơn.
- **Từ chối và dự phòng:** bật `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`). Nếu model từ chối vì chính sách nội dung, API tự thử model dự phòng. Nếu vẫn từ chối, trang báo cho giáo viên.
- **Lỗi** (sai key, quá tải, mất mạng, PDF hỏng) đều có thông báo dễ hiểu. Chi tiết lỗi ghi vào log lỗi của Apache.

## AI trên máy (miễn phí, offline): Gemma 4 E4B

Đặt `'provider' => 'local'` trong `ai_config.php` (hiện đang bật). Khi đó trang dùng model **Gemma 4 E4B** (Google, giấy phép Apache 2.0) chạy trên GPU của laptop, không cần API key và không cần Internet. Trước đó đã thử Qwen3 4B: Gemma soạn câu hỏi chính xác hơn rõ (đúng kiến thức, mỗi câu một đáp án đúng, đáp án nhiễu hợp lý, không lẫn chữ Trung Quốc), tốc độ tương đương.

| Thành phần | Vị trí |
|---|---|
| llama.cpp (bản Vulkan, khoảng 80 MB) | `~/llm/llama-b11609/` |
| Model `gemma-4-E4B_q4_0-it.gguf` (5,2 GB, bản QAT của Google, đã kiểm tra SHA-256) | `~/llm/models/` |
| Script chạy (chỉ nghe ở `127.0.0.1:8081`, ngữ cảnh 32k token, tắt chế độ "nghĩ thầm") | `~/llm/start.sh` |
| Dịch vụ tự chạy khi đăng nhập | `~/.config/systemd/user/stem-quiz-llm.service` |

Tất cả nằm trong `/home`, không chiếm ổ `/`.

```bash
systemctl --user status stem-quiz-llm      # xem đang chạy không
systemctl --user restart stem-quiz-llm     # khởi động lại
journalctl --user -u stem-quiz-llm -n 50   # xem log
sudo loginctl enable-linger $USER          # (tuỳ chọn) chạy cả khi chưa đăng nhập
```

Đã đo: 8 câu mất khoảng **22 giây** (lần đầu sau khi bật có thể lâu hơn vì model đang nạp), chữ đầu tiên của chat hiện sau khoảng **0,6 giây**, chiếm khoảng **4 GB VRAM**.

**Đổi sang model khác:** tải file `.gguf` vào `~/llm/models/`, sửa hai dòng `-m ...` và `--alias ...` trong `~/llm/start.sh`, thêm `'local_model' => '<alias>'` vào `ai_config.php`, rồi `systemctl --user restart stem-quiz-llm`. Xoá model cũ để khỏi chiếm ổ.

Giới hạn của AI trên máy:
- **Chỉ đọc chữ.** PDF được lấy chữ ra bằng `pdftotext`, nên hình, bảng dạng ảnh và trang scan bị bỏ qua. PDF scan thì trang báo lỗi và gợi ý dán văn bản.
- **Tối đa khoảng 60.000 ký tự** (khoảng 20–25 trang). Dài hơn thì trang báo, **không tự cắt bớt**; giáo viên dán riêng chương cần hỏi.
- **Vẫn kém Claude.** Vẫn phải đọc lại từng câu trước khi lưu.
- Code tự xáo thứ tự đáp án ở lần tạo đầu, tự bỏ phần "A. " thừa ở đầu đáp án và lọc chữ Trung Quốc lỡ chèn vào.
- Không tính vào `daily_limit`, vì miễn phí.

Muốn quay lại Claude: đặt `'provider' => 'claude'` (cần nạp credit API).

## Chọn model

Đặt trong `/var/www/iot-private/ai_config.php`. Lưu xong là có hiệu lực ngay, không cần khởi động lại.

| Tuỳ chọn | Ý nghĩa |
|---|---|
| `'model' => 'claude-opus-5-5'` | Model dùng để soạn câu hỏi (mặc định) |
| `'effort' => 'high'` | Model suy nghĩ kỹ đến mức nào: `low` / `medium` / `high` / `xhigh` / `max`. Cao hơn thì câu hỏi tốt hơn nhưng chậm và tốn hơn |
| `'fallback' => false` | Mặc định `true`: nếu model từ chối vì chính sách nội dung, API tự thử một model dự phòng. Đặt `false` để **chỉ dùng đúng model đã chọn** |

Các model dùng được (giá cho mỗi 1 triệu token đầu vào / đầu ra, tháng 10/2026):

| `model` | Giá | Nhận xét |
|---|---|---|
| `claude-opus-5-5` | $4 / $20 | Mặc định, câu hỏi chất lượng tốt nhất trong nhóm này |
| `claude-sonnet-5-5` | $2 / $10 | Rẻ bằng một nửa, nhanh hơn |
| `claude-haiku-5-5` | $0,10 / $0,50 | Rất rẻ và nhanh. Câu hỏi đơn giản hơn, nên kiểm tra kỹ hơn |
| `claude-fable-5-1` | $10 / $50 | Mạnh nhất nhưng đắt, thường không cần cho việc soạn quiz |

Ghi sai tên model thì trang báo "model does not exist". Muốn khống chế cả tiền, đặt **giới hạn chi tiêu hằng tháng** cho API key trong console.anthropic.com (mục Limits).

## Chi phí và giới hạn
- Giá Claude Opus 5.5 (tháng 10/2026): $4 cho mỗi 1 triệu token đầu vào, $20 cho mỗi 1 triệu token đầu ra.
- Ước tính một lần tạo 10 câu từ tài liệu khoảng 20 trang: **$0,2–0,4**. Mỗi lần sửa sau đó rẻ hơn nhờ cache.
- `daily_limit` chặn số lần gọi AI của mỗi giáo viên mỗi ngày (mặc định 40).
- Cần Internet lúc soạn quiz. Lúc chạy quiz trên lớp vẫn offline như trước.

## Lưu ý
- **Tài liệu được gửi tới API của Anthropic để xử lý.** Không tải lên tài liệu có thông tin cá nhân của học sinh.
- **AI có thể sai.** Giáo viên phải đọc lại từng câu trước khi lưu. Trang không cho lưu thẳng mà luôn qua bước xem lại.
- **API key chỉ nằm trong `/var/www/iot-private/ai_config.php`** (ngoài thư mục web). Không ghi key vào code, không gửi key qua chat hay email.
