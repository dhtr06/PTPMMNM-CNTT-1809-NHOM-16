# LinkScope — kế hoạch nâng cấp và ghi chú rà soát

## 1. Rà soát trước khi sửa

Bản ZIP đầu vào đã có PHP thuần, PDO/MySQL, crawler BFS, kiểm tra URL và redirect, dashboard, báo cáo, CSV, lịch sử, so sánh, tài khoản cơ bản và trang xem trước phạm vi. Những điểm cần bổ sung/hoàn thiện:

- Dashboard chưa có biểu đồ xu hướng theo ngày.
- Lịch sử thiếu tìm kiếm, lọc và phân trang.
- Báo cáo cần lọc theo loại tài nguyên và chế độ in PDF gọn gàng.
- Bảng lịch giám sát đã có trong schema nhưng chưa có UI/worker chạy lịch.
- Bảng thông báo đã có nhưng chưa có trang quản lý thông báo.
- Điều hướng chưa phân biệt vai trò rõ ràng; so sánh cần kiểm tra cùng phạm vi.

## 2. Phạm vi triển khai

1. **UI:** giữ Bootstrap 5 và CSS hiện tại; bổ sung biểu đồ xu hướng, liên kết nhanh thông báo/lịch, điều hướng theo vai trò.
2. **Báo cáo:** tìm kiếm URL/trang nguồn/lỗi; lọc trạng thái và loại tài nguyên; phân trang; CSV; in/lưu PDF bằng hộp thoại trình duyệt.
3. **Lịch sử:** tìm theo tên, URL hoặc mã phiên; lọc trạng thái; phân trang 10 phiên/trang.
4. **So sánh:** từ chối so sánh nếu URL gốc, allowlist hoặc các giới hạn cấu hình khác nhau.
5. **Giám sát:** CRUD lịch theo chu kỳ, worker CLI xử lý lịch đến hạn, thông báo trong ứng dụng khi phát hiện lỗi.
6. **Phân quyền:** admin quản lý tài khoản, chạy quét, quản lý lịch; viewer chỉ xem lịch sử/báo cáo/so sánh và thông báo.
7. **Tài liệu:** hướng dẫn XAMPP, Task Scheduler, kiểm thử và giới hạn đã biết.

## 3. Kiểm thử thực hiện trong môi trường dựng mã

- Chạy `php -l` cho toàn bộ tệp PHP.
- Kiểm tra hash mật khẩu demo `Admin@12345` bằng `password_verify`.
- Rà soát luồng worker CLI, CSRF cho request web, prepared statements cho truy vấn người dùng và escape HTML đầu ra.
- Kiểm tra ZIP chứa đủ các trang mới, SQL, README và hướng dẫn nâng cấp.

## 4. Kiểm thử cần chạy trên máy XAMPP

Môi trường đóng gói không có extension `pdo_mysql` và không chạy MySQL/MariaDB, vì vậy chưa thể xác nhận truy vấn DB hoặc quét website thực tế ở đây. Sau khi cài trên XAMPP, cần kiểm tra:

- Import `database.sql`, đăng nhập, tạo user viewer và xác nhận viewer không thể chạy quét.
- Quét website demo được phép quản lý, kiểm tra 404/ảnh lỗi/anchor lỗi/timeout và redirect ngoài allowlist.
- Tạo lịch, cấu hình Task Scheduler, đặt `next_run_at` đến hạn và xác nhận worker tạo phiên mới.
- Xác nhận thông báo xuất hiện sau phiên có lỗi; lọc và phân trang hoạt động.
- So sánh hai phiên cùng cấu hình; thử hai phiên khác cấu hình để xác nhận bị từ chối.
- Xuất CSV và dùng Xuất PDF → Save as PDF.

## 5. Giới hạn còn lại

- PDF được tạo từ chức năng in của trình duyệt, không phải PDF server-side.
- Thông báo là trong ứng dụng; chưa cấu hình SMTP/email/SMS.
- Worker xử lý một lịch đến hạn mỗi lượt chạy; Task Scheduler nên gọi mỗi 5 phút.
- Crawler đồng bộ và chỉ đọc HTML tĩnh; không chạy JavaScript, không đăng nhập website đích.
- Việc kiểm tra DNS trước request không thay thế giải pháp production-grade chống DNS rebinding; chỉ quét website được phép và không triển khai công khai nếu chưa có kiểm toán bảo mật đầy đủ.
