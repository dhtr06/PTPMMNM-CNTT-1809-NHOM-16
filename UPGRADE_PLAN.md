# LinkScope Pro v3.0 — Kế hoạch Nâng cấp, Kiểm thử & Nhật ký Rà soát

## 1. Các vấn đề của phiên bản cũ đã được khắc phục triệt để

1. **Lỗi cú pháp trong `preview.php`:** Phiên bản cũ bị lẫn số thứ tự dòng (`2..22`, ký tự lạ) gây lỗi `PHP Parse error`. Đã viết lại hoàn chỉnh `preview.php` và tích hợp truyền dữ liệu trực tiếp từ trang chủ.
2. **Nghẽn cổ chai khi quét Website lớn:** Phiên bản cũ gọi `file_get_contents()` tuần tự từng liên kết và gọi `dns_get_record()` lặp lại cho mọi URL làm phiên quét bị treo hoặc hết giờ ngay ở trang đầu tiên. Đã thay bằng **Multi-cURL bất đồng bộ đa luồng (`ls_fetch_multi_batch`)** trong `engine.php`, bổ sung bộ nhớ đệm DNS in-memory và giới hạn tải 24KB cho tài nguyên tĩnh.
3. **Bỏ sót kiểm tra `#fragment` trùng URL cơ sở:** Đã tách riêng bộ nhớ đệm phản hồi HTTP (`$targetCache[$baseUrl]`) và khóa lưu phát hiện (`$savedFindings[$fullUrl]`), đảm bảo chỉ gửi HTTP request 1 lần nhưng kiểm tra đầy đủ mọi `#anchor` khác nhau.
4. **Chưa ghi nhận các trang còn trong hàng đợi khi vượt giới hạn (`max_pages` / `max_duration`):** Đã bổ sung bước vét hàng đợi sau khi dừng để ghi nhận đầy đủ các mục chưa kiểm tra vào `findings` với trạng thái `unchecked` (đúng Phép thử bắt buộc số 4).
5. **Thiếu Website thử nghiệm (~20 trang, ~60 liên kết) & Bộ kiểm thử tự động:** Đã tích hợp thư mục `testbed/` (22 trang, >70 liên kết) và trang `tests.php` (chạy tự động 5 phép thử bắt buộc + bảng đáp án chuẩn + công tắc sửa lỗi cho Tình huống 2).
6. **Nâng cấp Lọc báo cáo, Kiểm tra lại nhiều mục & So sánh phiên:**
   - `report.php`: Thêm bộ lọc theo **Trang nguồn (`source_page`)**, tích chọn nhiều mục (checkbox) để kiểm tra lại hàng loạt.
   - `compare.php`, `export.php`, `pdf.php`: Hiển thị và xuất CSV/PDF báo cáo so sánh kèm đầy đủ vị trí dòng HTML trong trang nguồn; nhận diện cả trường hợp lỗi được sửa bằng cách khôi phục trang đích lẫn trường hợp sửa/thay thế thẻ `href` trên trang nguồn.
