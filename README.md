# LinkScope Pro v3.0 — Công cụ kiểm tra liên kết và tài nguyên bị lỗi trên Website (Đề tài 13)

LinkScope Pro v3.0 là hệ thống web hoàn chỉnh phục vụ **Đề tài 13: Xây dựng công cụ kiểm tra liên kết và tài nguyên bị lỗi trên website**, được phát triển bằng **PHP thuần (8.0+), PDO, MySQL/MariaDB, Multi-cURL song song và Bootstrap 5**.

Phiên bản v3.0 được nâng cấp toàn diện để:
1. **Đạt tuyệt đối 10/10 điểm theo Rubric Đề tài 13** (đầy đủ 5 chức năng, 2 yêu cầu kỹ thuật, 2 tình huống sử dụng, Website thử nghiệm 22 trang / >70 liên kết kèm danh sách đáp án lỗi dự kiến và bộ kiểm thử tự động 5 phép thử bắt buộc).
2. **Quét mượt mà các Website lớn ngoài thực tế** (nhờ kiến trúc **Multi-cURL bất đồng bộ đa luồng**, bộ nhớ đệm DNS in-memory, nén Gzip tự động, giới hạn tải thông minh 24KB cho ảnh/tài nguyên nặng và gom nhóm Transaction MySQL).

---

## 1. Năm chức năng cốt lõi & Các nâng cấp nổi bật

1. **Chức năng 1 — Khai báo phạm vi & Xem trước trước khi chạy (`index.php`, `preview.php`):**
   - Khai báo URL bắt đầu, danh sách các miền được phép (`allowed_domains`, hỗ trợ cả `*.domain.com` và tự động nhận diện subdomain), giới hạn số trang (tối đa 2.000 trang), độ sâu BFS (0–15), timeout mỗi request (1–30s), tổng thời gian (3–600s) và số luồng Multi-cURL song song (1–32 luồng).
   - Trang **Xem trước phạm vi (`preview.php`)** phân tích và hiển thị bảng quy tắc phạm vi trước khi chạy mà chưa gửi request tới máy chủ đích.
2. **Chức năng 2 — Đọc HTML, chuẩn hóa URL tương đối & Duyệt BFS không lặp (`engine.php`, `scan.php`):**
   - Tự cài đặt thuật toán chuẩn hóa địa chỉ theo chuẩn **RFC 3986** (`ls_normalize_url`): xử lý `<base href>`, đường dẫn tương đối (`./`, `../`, `/root`, `?query`, `//cdn...`), khử trùng lặp segment và tách riêng `#fragment`.
   - Tự động loại bỏ comment `<!-- ... -->` và nội dung trong `<script>`/`<style>` nhưng **giữ nguyên chính xác số dòng HTML (`line_number`)**.
   - Hỗ trợ trích xuất liên kết từ `<a>`, `<area>`, `<img src/data-src/srcset>`, `<link>`, `<script>`, `<iframe>`, `<source>`, `<video>`, `<audio>`, `<embed>`, `<object>`.
3. **Chức năng 3 — Kiểm tra trang, ảnh, chuyển hướng & mục neo (`#anchor`); phân loại chính xác (`engine.php`):**
   - Phân biệt rõ ràng 7 trạng thái:
     - `ok`: Phản hồi thành công (HTTP 2xx).
     - `redirect`: Chuyển hướng hợp lệ trong phạm vi (HTTP 3xx -> 2xx).
     - `http_error`: Lỗi HTTP (4xx, 5xx như 404, 403, 500) hoặc **Ảnh hỏng** (ảnh trả mã lỗi, trả về `text/html` soft-404, tệp 0 byte hoặc hỏng header).
     - `missing_anchor`: Trang đích tải thành công (2xx) nhưng không có phần tử `id="..."` hoặc `name="..."` tương ứng với `#fragment`.
     - `connection_error`: Lỗi phân giải tên miền (DNS), từ chối kết nối TCP hoặc lỗi chứng chỉ TLS.
     - `unknown`: **Hết thời gian chờ (Timeout)** — tuân thủ nghiêm ngặt Yêu cầu kỹ thuật 2: *hết hạn kết nối được ghi đúng là chưa xác định phản hồi (`unknown`), tuyệt đối không tự kết luận trang không tồn tại (`404`)*.
     - `unchecked`: Địa chỉ hoặc đích chuyển hướng nằm ngoài miền cho phép (Allowlist) hoặc **chưa kiểm tra do dừng khi chạm giới hạn số trang/thời gian**.
4. **Chức năng 4 — Lưu lịch sử, giữ đầy đủ trang nguồn, lọc đa chiều & Kiểm tra lại mục đã chọn (`report.php`, `retry.php`):**
   - Một đích được nhiều trang dẫn tới chỉ gửi HTTP request kiểm tra **đúng 1 lần**, nhưng giữ đầy đủ danh sách mọi trang nguồn, số dòng HTML và đoạn thẻ HTML trong bảng `links`.
   - Lọc báo cáo theo **Trạng thái/Lỗi**, **Trang nguồn (`source_url`)**, **Loại tài nguyên** và **Từ khóa**.
   - Hỗ trợ tích chọn nhiều mục (checkbox) để **Kiểm tra lại các mục đã chọn** (cập nhật trực tiếp vào phiên hiện tại hoặc lưu thành phiên mới).
5. **Chức năng 5 — So sánh hai lần kiểm tra cùng phạm vi & Xuất báo cáo kèm vị trí nguồn (`compare.php`, `export.php`, `pdf.php`):**
   - Kiểm tra đồng nhất phạm vi trước khi so sánh.
   - Chỉ rõ 4 nhóm: **Lỗi đã hết (Đã khắc phục)**, **Lỗi mới phát sinh**, **Lỗi còn lại** và **Mục chưa kiểm tra / Chưa xác định**.
   - Xuất báo cáo chi tiết và báo cáo so sánh ra **CSV (UTF-8 BOM cho Excel)** và **PDF** kèm đầy đủ trang nguồn, số dòng và đoạn thẻ HTML.

---

## 2. Cấu trúc thư mục dự án

- `config.php`: Kết nối PDO MySQL (tự động khởi tạo database/bảng và nâng cấp schema nếu thiếu), quản lý phiên, CSRF và giao diện chung.
- `engine.php`: Lõi thuật toán chuẩn hóa URL RFC 3986, phân tích HTML, kiểm soát phạm vi Allowlist/SSRF, bộ nhớ đệm DNS và bộ máy kiểm tra song song Multi-cURL (`ls_fetch_multi_batch`).
- `index.php`: Trang tổng quan Dashboard, biểu đồ xu hướng 7 ngày và biểu mẫu tạo phiên quét kèm 3 Preset (Web thử Đề tài 13 / Tiêu chuẩn / Web lớn).
- `preview.php`: Trang xem trước và xác nhận phạm vi quét trước khi chạy (Chức năng 1).
- `scan.php`: Điều phối phiên quét từ giao diện web hoặc worker định kỳ.
- `report.php`: Báo cáo chi tiết phiên quét, bộ lọc theo lỗi/trang nguồn/loại tài nguyên và công cụ kiểm tra lại mục đã chọn.
- `retry.php`: Thực thi kiểm tra lại 1 hoặc nhiều mục đã chọn bằng Multi-cURL.
- `compare.php`: Đối chiếu hai phiên quét cùng phạm vi (Chức năng 5 & Tình huống 2).
- `tests.php`: **Trung tâm Kiểm thử tự động 5 phép thử bắt buộc (Mục 6)**, bảng đáp án lỗi dự kiến và công tắc sửa lỗi nhanh cho Website thử.
- `testbed/`: **Website thử nghiệm nội bộ gồm 22 trang và >70 liên kết** (`index.php`, `about.php`, `contact.php`, `pricing.php`, `faq.php`, `support.php`, `partners.php`, `terms.php`, `privacy.php`, `careers.php`, `status.php`, `docs/*`, `products/*`, `guides/cycle-*`, `news/*`, `endpoint.php`).
- `export.php` & `pdf.php`: Xuất báo cáo phiên quét hoặc báo cáo so sánh ra CSV và PDF.
- `history.php`, `schedules.php`, `schedule_worker.php`, `notifications.php`, `users.php`, `login.php`, `logout.php`.
- `database.sql`: Schema cơ sở dữ liệu MySQL (`sites`, `scan_runs`, `pages`, `links`, `findings`, `users`, `scan_schedules`, `notifications`).

---

## 3. Hướng dẫn cài đặt và chạy trên XAMPP (Windows)

1. Copy thư mục `broken_link_checker_php` vào `C:\xampp\htdocs\broken_link_checker_php`.
2. Mở **XAMPP Control Panel**, nhấn **Start** ở **Apache** và **MySQL**.
3. Mở trình duyệt truy cập `http://localhost/broken_link_checker_php/` (Hệ thống sẽ **tự động tạo database `broken_link_checker` và các bảng** ngay lần đầu truy cập, hoặc bạn có thể import thủ công `database.sql` trong phpMyAdmin).
4. Đăng nhập bằng tài khoản khởi tạo:
   - Tài khoản Quản trị (Admin): `admin` / `Admin@12345`
   - Tài khoản Chỉ xem (Viewer): `viewer` / `Viewer@12345`

---

## 4. Kịch bản trình diễn 2 tình huống bắt buộc (Mục 4 Đề tài 13)

### Tình huống 1: Chọn website thử → Duyệt phạm vi → Chạy kiểm tra → Mở một lỗi → Đến trang chứa liên kết để sửa
1. Tại trang **Tổng quan (`index.php`)**, nhấn nút mẫu **`🧪 Web thử Đề tài 13 (22 trang)`**.
2. Nhấn **`🔍 1. Xem trước phạm vi`** để mở trang `preview.php`, kiểm tra bảng phạm vi và nhấn **`▶ Bắt đầu quét với phạm vi đã duyệt`**.
3. Trong trang **Báo cáo (`report.php`)**, chọn bộ lọc **`⚠ Tất cả các lỗi`** để quan sát các lỗi phát hiện được (Trang 404, Ảnh hỏng, Thiếu `#anchor`, Lỗi 500) và các mục `unknown` (Timeout) / `unchecked` (Ngoài phạm vi).
4. Tại một dòng lỗi bất kỳ, nhấn nút **`🛠 Đến trang nguồn sửa`** để mở trực tiếp trang chứa liên kết hỏng trong Website thử nghiệm.

### Tình huống 2: Sửa website → Chạy lại cùng phạm vi → So sánh → Xác nhận lỗi đã hết hoặc xuất báo cáo lỗi còn lại
1. Ngay trên thanh điều khiển ở đầu trang nguồn của Website thử (hoặc trong menu **`🧪 Kiểm thử & Web mẫu`**), nhấn nút **`🔧 Sửa lỗi trên Website thử (Tình huống 2)`**.
2. Quay lại trang Báo cáo của phiên vừa chạy, nhấn nút **`↻ Chạy lại cùng phạm vi`** ở góc trên bên phải.
3. Sau khi quét xong phiên mới, nhấn nút **`⇄ So sánh với phiên #...`** ngay trên đầu báo cáo.
4. Trang **So sánh (`compare.php`)** sẽ chỉ rõ các mục nằm trong nhóm **`✓ Lỗi đã hết (Đã khắc phục thành công)`**, **`❗ Lỗi còn lại`** và **`⏳ Mục chưa kiểm tra / Chưa xác định`**. Nhấn **`↓ Xuất CSV So sánh & Lỗi còn lại`** hoặc **`🖨 Xuất PDF So sánh`** để hoàn tất.

---

## 5. Giấy phép & Nguồn sử dụng (Rubric Mã nguồn mở)

- Mã nguồn dự án được phát hành theo giấy phép **MIT License** (xem tệp `LICENSE`).
- Các thư viện giao diện sử dụng qua CDN (kèm giấy phép mã nguồn mở tương ứng):
  - **Bootstrap 5.3.3** (MIT License) — định dạng giao diện responsive.
  - **Chart.js 4.4** (MIT License) — vẽ biểu đồ xu hướng và biểu đồ tròn phân bố trạng thái.
- Toàn bộ thuật toán duyệt liên kết BFS, chuẩn hóa địa chỉ URL RFC 3986, kiểm tra phạm vi Allowlist/SSRF và điều phối Multi-cURL được nhóm **tự viết 100% bằng PHP thuần** trong `engine.php`.
