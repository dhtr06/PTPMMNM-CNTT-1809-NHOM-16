# LinkScope — Website kiểm tra liên kết bằng PHP thuần

LinkScope là đồ án web kiểm tra liên kết và tài nguyên trên website. Dự án sử dụng PHP thuần, PDO, MySQL và Bootstrap 5; không dùng Laravel hay framework PHP.

## 1. Chức năng

- Dashboard tổng hợp phiên quét, số trang, số liên kết và số đích lỗi.
- Nhập URL bắt đầu, danh sách domain được phép quét, giới hạn trang/độ sâu/timeout/thời gian.
- Duyệt các trang trong phạm vi domain bằng hàng đợi BFS.
- Phát hiện các liên kết trong `a`, `img`, `script`, `link`, `iframe`, `source`, `video`, `audio` và `form`.
- Chuẩn hóa URL tương đối, URL tuyệt đối và URL dạng `//host/path`; bỏ qua `mailto:`, `tel:`, `javascript:`, `data:` và fragment-only.
- Ghi dữ liệu trang, liên kết, kết quả HTTP và lịch sử quét vào MySQL.
- Báo cáo có lọc trạng thái, phân loại mã HTTP, tô màu trạng thái và xuất CSV UTF-8 có BOM để Excel đọc tiếng Việt.
- Giao diện dashboard responsive, biểu đồ xu hướng 7 ngày và biểu đồ phân bố kết quả.
- Lịch sử có tìm kiếm, lọc trạng thái và phân trang.
- So sánh hai phiên cùng phạm vi; xuất CSV và báo cáo in/lưu PDF từ trình duyệt.
- Đăng nhập, vai trò admin/viewer, quản lý tài khoản và CSRF token cho thao tác POST.
- Lịch giám sát định kỳ qua Windows Task Scheduler và trung tâm thông báo trong ứng dụng.

## 2. Yêu cầu môi trường

- XAMPP có Apache, MySQL/MariaDB và PHP 8.0+ (khuyến nghị PHP 8.1+).
- PHP extension PDO MySQL được bật.
- `allow_url_fopen=On` trong `php.ini`.
- Máy có quyền truy cập Internet nếu kiểm tra website công khai.
- Bootstrap tải từ CDN, vì vậy cần Internet để có giao diện đầy đủ; chức năng PHP vẫn nằm trong mã nguồn dự án.

## 3. Cài đặt trên Windows/XAMPP

1. Giải nén thư mục `broken_link_checker_php` vào `C:\xampp\htdocs\`.
2. Mở XAMPP Control Panel, bấm **Start** ở Apache và MySQL.
3. Mở `http://localhost/phpmyadmin/`.
4. Chọn **Import** → chọn `database.sql` trong thư mục dự án → **Import/Go**. File SQL tự tạo database `broken_link_checker` và các bảng.
5. Mở `config.php`. Thiết lập `$DB_HOST`, `$DB_NAME`, `$DB_USER`, `$DB_PASS` phù hợp với MySQL của máy. Cấu hình XAMPP mặc định thường là `root` và mật khẩu trống.
6. Mở `http://localhost/broken_link_checker_php/login.php`, đăng nhập bằng tài khoản demo.
7. Sau khi đăng nhập, mở `http://localhost/broken_link_checker_php/`.

### Nếu không kết nối được database

- Kiểm tra MySQL đã chạy và database `broken_link_checker` tồn tại.
- Kiểm tra tên database, username, password trong `config.php`.
- Nếu xuất hiện lỗi `could not find driver`, bật extension `pdo_mysql` trong `php.ini`, sau đó khởi động lại Apache.

### Bật `allow_url_fopen`

1. Trong XAMPP Control Panel → Apache → **Config** → `PHP (php.ini)`.
2. Tìm `allow_url_fopen=On`; nếu là `Off`, đổi thành `On`.
3. Lưu file và restart Apache. Tên menu có thể khác đôi chút tùy phiên bản XAMPP.

## 4. Cách sử dụng

1. Nhập tên cấu hình và URL bắt đầu, ví dụ `https://example.com/`.
2. Để trống domain được phép quét nếu chỉ muốn kiểm tra domain trong URL bắt đầu. Nếu nhập nhiều domain, phân cách bằng dấu phẩy; domain URL ban đầu phải nằm trong danh sách.
3. Chọn giới hạn trang, độ sâu, timeout và tổng thời gian.
4. Nhấn **Xem trước phạm vi** để rà soát allowlist và giới hạn; sau đó bắt đầu quét. Khi kết thúc, báo cáo hiển thị thống kê, phân loại mã HTTP và danh sách kết quả.
5. Dùng tìm kiếm, bộ lọc trạng thái/loại tài nguyên và phân trang để xem kết quả.
6. Nhấn **Xuất CSV** để mở báo cáo bằng Excel; mở **Báo cáo PDF** rồi nhấn **In / Lưu thành PDF** và chọn **Save as PDF** trong hộp thoại trình duyệt. Vào **Lịch sử quét** để tìm và xem lại các phiên cũ.

## 5. Kiến trúc

- `config.php`: kết nối PDO, hàm escape HTML, thông báo flash và layout dùng chung.
- `index.php`: dashboard và form cấu hình phiên quét.
- `scan.php`: xác thực đầu vào, crawler BFS, chuẩn hóa URL, gọi HTTP và lưu kết quả.
- `report.php`: thống kê phiên, bảng mã HTTP và bộ lọc kết quả.
- `history.php`: lịch sử có tìm kiếm, lọc và phân trang.
- `schedules.php`: tạo, tạm dừng, bật lại và xóa lịch giám sát.
- `schedule_worker.php`: worker CLI chạy một lịch đến hạn mỗi lần gọi.
- `notifications.php`: xem và đánh dấu thông báo đã đọc.
- `export.php`: xuất dữ liệu phiên quét ra CSV UTF-8, kèm trang nguồn/dòng và thẻ HTML.
- `pdf.php`: trang báo cáo in gọn để lưu thành PDF từ trình duyệt.
- `database.sql`: tạo database và các bảng `sites`, `scan_runs`, `pages`, `links`, `findings`.
- `BAO_VE_DE_TAI.md`: sơ đồ hoạt động, kịch bản demo và bộ câu hỏi bảo vệ.
- `UPGRADE_PLAN.md`: rà soát mã nguồn trước khi sửa, kế hoạch, kiểm thử và giới hạn.
- `CHANGELOG.md`: danh sách thay đổi của bản nâng cấp.
- `LICENSE`: giấy phép MIT.

## 6. Quy tắc phân loại

- `2xx`: phản hồi thành công; được đánh dấu `ok`.
- `3xx`: chuyển hướng; được phân loại `redirect` nếu mã này là phản hồi cuối cùng nhận được.
- `4xx` và `5xx`: lỗi HTTP; được phân loại `http_error`.
- Timeout được phân loại `unknown`; lỗi kết nối có thông tin lỗi DNS/kết nối phù hợp được phân loại `connection_error`. Trường hợp không đủ thông tin được giữ là `unknown` thay vì kết luận URL không tồn tại.

Lưu ý: nhiều máy chủ chuyển hướng tự động đến URL cuối cùng; khi đó mã cuối cùng có thể là `200` và báo cáo sẽ ghi nhận thành công. Một số máy chủ trả `403` cho bot, yêu cầu cookie/đăng nhập, chặn theo IP hoặc có giới hạn tốc độ; do đó kết quả cần được xem trong ngữ cảnh website thực tế.

## 7. Giới hạn và an toàn

- Đây là crawler phục vụ học tập, không phải trình duyệt đầy đủ. Nội dung liên kết được tạo bằng JavaScript, SPA, shadow DOM hoặc API runtime có thể không được phát hiện.
- Số lượng URL cần kiểm tra có thể lớn; hãy bắt đầu với 10–30 trang và timeout ngắn khi demo.
- Chỉ kiểm tra website bạn sở hữu hoặc có sự cho phép. Không dùng công cụ để gây tải hoặc né giới hạn truy cập của website khác.
- Không nhập URL chứa thông tin đăng nhập. Nếu triển khai lên máy chủ công khai, cần bổ sung xác thực người dùng, CSRF token, giới hạn tốc độ, kiểm soát SSRF/DNS rebinding, hàng đợi nền và nhật ký truy cập.
- Các yêu cầu HTTP đang chạy đồng bộ trong một request PHP. Phiên quét dài có thể chạm giới hạn `max_execution_time` của PHP hoặc timeout trình duyệt; tăng giới hạn chỉ khi hiểu rõ tác động hoặc chuyển sang worker/CLI cho bản triển khai thực tế.

## 8. Kiểm thử nhanh

- Nhập URL hợp lệ và kiểm tra tạo bản ghi mới trong `scan_runs`.
- Thử URL sai định dạng để xác nhận hệ thống trả thông báo và không tạo phiên quét.
- Dùng một website thử nghiệm do bạn kiểm soát có đường dẫn trả `404` để xác nhận lỗi được ghi nhận.
- Mở báo cáo, lọc lỗi/hoạt động/chuyển hướng, tải CSV và kiểm tra tiếng Việt trong Excel.
- Mở lịch sử và truy cập lại báo cáo cũ.

## 9. Kiểm tra mã nguồn

Từ Command Prompt/PowerShell có PHP trong PATH, chạy:

```powershell
php -l config.php
php -l index.php
php -l scan.php
php -l report.php
php -l history.php
php -l export.php
php -l compare.php
php -l schedules.php
php -l notifications.php
php -l schedule_worker.php
```

`php -l` chỉ kiểm tra cú pháp PHP, không thay thế kiểm thử thực tế trên XAMPP và MySQL.


## 10. Nâng cấp theo yêu cầu đề tài 13

- Xem trước phạm vi quét trước khi chạy (`preview.php`), bao gồm URL gốc, allowlist domain, giới hạn trang/độ sâu/timeout/thời gian.
- Kiểm tra domain cho phép trước khi gửi yêu cầu và kiểm tra lại mỗi đích redirect; chặn hostname localhost/mạng riêng theo mặc định. Chỉ dùng với website công khai mà bạn sở hữu/được cho phép.
- Crawler BFS có tập URL đã thăm; lưu từng liên kết theo trang nguồn, số dòng HTML và đoạn thẻ. Một URL đích chỉ được kiểm tra một lần trong mỗi phiên nhưng mọi nguồn liên kết đều được giữ lại.
- Phân biệt `http_error`, `unknown` (timeout/kết nối chưa xác định), `unchecked` (ngoài phạm vi), `redirect`, `missing_anchor` và `ok`.
- Báo cáo có tìm kiếm, lọc, phân trang, xem nguồn/dòng/thẻ HTML; so sánh hai phiên tại `compare.php`.
- Xuất CSV; nút **Báo cáo PDF** mở trang báo cáo in riêng; chọn **In / Lưu thành PDF** và dùng chức năng in của trình duyệt, chọn **Save as PDF**.
- Đăng nhập và phân vai admin/viewer; chỉ admin được chạy quét, xem trước phạm vi và quản lý tài khoản. Tài khoản khởi tạo: `admin` / `Admin@12345`. Trang `users.php` cho phép admin tạo tài khoản và phân vai; bản demo nên được dùng trong môi trường local và đổi mật khẩu trước khi triển khai thật.
- Khi phiên quét phát hiện lỗi, hệ thống ghi thông báo vào bảng `notifications`; worker lịch chạy bằng Windows Task Scheduler/cron. Email/SMS chưa được tích hợp.

### Giám sát định kỳ trên Windows

1. Vào **Giám sát định kỳ**, chọn một cấu hình website đã quét và đặt chu kỳ.
2. Mở Windows **Task Scheduler** → **Create Basic Task**.
3. Chọn lịch lặp (khuyến nghị mỗi 5 phút để worker phát hiện lịch đến hạn).
4. Program/script: `C:\xampp\php\php.exe`
5. Add arguments: `C:\xampp\htdocs\broken_link_checker_php\schedule_worker.php`
6. Chọn **Run whether user is logged on or not** nếu phù hợp với máy của bạn. Đảm bảo tài khoản Windows có quyền chạy PHP và MySQL đang bật.

Worker chỉ xử lý tối đa một lịch đến hạn mỗi lượt. Thông báo được lưu trong ứng dụng khi có lỗi; gửi email/SMS chưa được cấu hình trong bản này.

### Xuất PDF

Trong báo cáo phiên quét, nhấn **Báo cáo PDF** để mở trang in riêng, sau đó nhấn **In / Lưu thành PDF** → chọn **Save as PDF**. Bản in ẩn menu và nút thao tác, giữ lại các số liệu và bảng báo cáo. Đây là PDF tạo từ trình duyệt, không phải thư viện PDF server-side.

### Kiểm thử đề xuất

1. Dùng website demo do bạn kiểm soát với liên kết A→B→A; xác nhận crawler kết thúc và không lặp vô hạn.
2. Tạo hai liên kết trỏ cùng URL đích từ hai trang nguồn; xác nhận chỉ kiểm tra URL đích một lần nhưng cả hai nguồn được lưu trong `links`.
3. Tạo trang 404, ảnh lỗi, anchor không tồn tại và một URL thử nghiệm timeout; kiểm tra phân loại tương ứng. Timeout phải là `unknown`, không phải `http_error`.
4. Thêm domain ngoài allowlist và redirect ra domain ngoài; xác nhận hệ thống không truy cập đích ngoài phạm vi.
5. Chạy lần đầu, sửa liên kết lỗi, chạy lần hai cùng cấu hình và so sánh bằng `compare.php`.

> Ghi chú: lịch quét tự động chạy qua worker CLI khi bạn cấu hình Windows Task Scheduler/cron. Trung tâm thông báo lưu cảnh báo trong ứng dụng; email/SMS chưa được cấu hình. Chức năng thử lại tạo một phiên riêng cho URL được chọn để giữ nguyên lịch sử phiên cũ.
