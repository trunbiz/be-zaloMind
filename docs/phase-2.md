# Giai đoạn 2 — Bộ nhận diện và khung giao diện

## Tài liệu API bàn giao đội APP

- [Hợp đồng API App A/agent kết nối Zalo](api-app-integration.md): node/token,
  heartbeat, nhận lệnh, lease, QR, trả kết quả, sự kiện và đồng bộ dữ liệu.
- [API người dùng và quản trị](api-platform.md): tài khoản, profile, gói dịch vụ,
  đơn hàng/thanh toán và vận hành admin.

Hai tài liệu trên là **hợp đồng đề xuất để triển khai**, không phải API đã có ở giai đoạn 2.
API hiện tại chỉ có `GET /api/user` được bảo vệ bằng Sanctum.

Theo yêu cầu tiếp theo của chủ dự án, tự tạo bộ brand thay cho bộ chưa được cung cấp.
Logo là biểu tượng hội thoại với chữ m kết nối và chấm xanh bạc hà. Chữ zaloMind
chuyển thành path từ Be Vietnam Pro; không phụ thuộc font khi mở SVG.

## Các file chính

- `scripts/generate-brand.mjs`, `public/brand/**`: nguồn tạo ảnh, logo, favicon,
  manifest, ảnh OG, minh họa, tokens sáng/tối và giấy phép font.
- `config/menu.php`, `app/Services/Navigation.php`: menu user/admin, lọc quyền,
  trạng thái trang hiện tại và danh sách đích của command palette.
- `app/Http/Controllers/HomeController.php`, `WorkspaceController.php`, `routes/web.php`:
  trang tổng quan, số tài khoản thực và các trang chuẩn bị nghiệp vụ theo whitelist menu.
- `resources/views/layouts/{app,admin,guest}.blade.php`, `partials/**`:
  khung giao diện, sidebar, topbar, tìm kiếm, phản hồi và metadata.
- `resources/views/components/**`: logo, card, stat-card, badge-status, empty-state,
  data-table, modal và shell. Component soạn tin/chọn người nhận/QR được làm ở giai đoạn tương ứng.
- `resources/css/app.css`, `resources/js/{app,workspace,data-table}.js`:
  responsive, theme, sidebar, phím tắt, bảng, modal, toast, bộ đếm KPI và trạng thái xử lý.
- `resources/views/errors/**`, `dashboard.blade.php`, `admin/dashboard.blade.php`:
  trang lỗi và các màn hình tổng quan của giai đoạn nền.
- `tests/Feature/WorkspaceTest.php`: kiểm tra mọi đích menu và phân quyền khu admin.

Không có thay đổi migration hay thông tin trong `.env` ở giai đoạn 2.
Các kết nối database/Redis hiện tại tiếp tục được dùng.

## Chạy

Trong PowerShell tại thư mục dự án:

```powershell
$env:Path = 'C:\OpenServer\modules\php\PHP_8.1;' + $env:Path
npm.cmd ci
npm.cmd run brand
npm.cmd run build
php artisan view:clear
php artisan serve --host=127.0.0.1 --port=8000
```

`brand` chỉ cần khi muốn tái tạo ảnh. Các ảnh đã được tạo sẵn trong public/brand.
Không chạy lại artisan serve nếu terminal cũ đang phục vụ cổng 8000.

## Kiểm tra thủ công khi có trình duyệt

1. Mở `/login`, `/register`, `/forgot-password`; xem logo, font tiếng Việt,
   minh họa, thay theme rồi tải lại để xác nhận lưu lựa chọn.
2. Đăng nhập `user1@zalomind.test` / `password`; xem `/dashboard` và `/profile`.
3. Mở các nhóm menu: chỉ một nhóm mở; trang con đang chọn có dấu chấm.
4. Thu gọn menu: còn thanh icon 72px; hover hoặc focus nhóm để xem flyout.
   Dùng Tab/Enter/Escape để kiểm tra bàn phím. Tải lại để kiểm tra lưu trạng thái.
5. Nhấn Ctrl+K hoặc Cmd+K, tìm có/không dấu, dùng mũi tên và Enter, Esc để đóng.
6. Chuyển kích thước 375px, 768px, 1440px; kiểm tra sidebar offcanvas và không
   cuộn ngang toàn trang. Nếu dùng trình đọc màn hình, thử link bỏ qua điều hướng.
7. Tài khoản user truy cập `/admin` và `/admin/users` phải trả 403.
   Tài khoản `admin@zalomind.test` / `password` thấy menu quản trị riêng.
8. Thử URL không tồn tại để xem 404. Trang 500 tùy chỉnh được dùng khi tắt debug;
   môi trường local bật debug sẽ tiếp tục hiển thị thông tin phục vụ phát triển.
9. Cập nhật thông tin tài khoản để xem toast. Nút xóa tài khoản mở modal xác nhận;
   hủy modal để kiểm tra mà không xóa tài khoản. Không dùng tài khoản thật để thử xóa.

## Xác minh đã thực hiện

Build Vite, Blade view cache và Pint chạy thành công. Bộ test cũ 30 bài/78 assertions
và WorkspaceTest mới 5 bài/125 assertions đều đạt. Đã kiểm tra đăng nhập qua HTTP,
trang tổng quan, trang profile và việc chặn user tại `/admin/users`.
Đã xem ảnh preview/OG và kiểm tra HTTP các tài nguyên brand.

Không có trình duyệt kết nối với công cụ trong phiên này; chưa đo mobile thực,
console JavaScript hoặc Lighthouse. Không coi các chỉ số đó đã đạt.

Các trang nghiệp vụ trong menu chỉ hiển thị mốc triển khai rõ ràng; chưa có
job gửi tin, kết nối agent, thanh toán hoặc dữ liệu nghiệp vụ mô phỏng.
Giai đoạn kế tiếp là migration, model, enum, policy và dữ liệu mẫu theo mục 10.3 của prompt.

Tham khảo API giao diện: [Bootstrap Offcanvas](https://getbootstrap.com/docs/5.3/components/offcanvas/),
[Bootstrap Modal](https://getbootstrap.com/docs/5.3/components/modal/).
