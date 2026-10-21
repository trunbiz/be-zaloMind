# zaloMind

Chăm sóc khách hàng Zalo thông minh.

## Trạng thái triển khai

Đã triển khai phần nền giai đoạn 1 theo prompt: Laravel 10/PHP 8.1, Breeze Blade
chuyển sang Bootstrap 5.3, Bootstrap Icons, font Be Vietnam Pro tự host, Vite,
Sanctum, Spatie Permission 6, Predis, Pusher SDK và Echo.

Đã có đăng nhập, đăng ký, quên/đặt lại mật khẩu, xác nhận mật khẩu,
cập nhật/xóa tài khoản, chuyển sáng/tối và các trang kiểm tra đăng nhập.
Route /admin được bảo vệ bằng auth + role:admin. Đăng ký chỉ nhận vai trò user,
không nhận quyền từ dữ liệu gửi lên. Nghiệp vụ tạo user nằm trong service và transaction;
các form xác thực sử dụng Form Request.

Đã hoàn tất bộ nhận diện tự tạo theo yêu cầu mới và giai đoạn 2:
layout user/admin riêng, sidebar dropdown và thu gọn 72px, flyout, offcanvas mobile,
topbar, dark mode, command palette Ctrl+K, toast, modal xác nhận, trang lỗi
403/404/419/500 và các Blade component nền. Menu nằm trong config/menu.php;
được lọc theo vai trò/quyền và dùng cùng nguồn cho sidebar, tìm kiếm và route.

Các trang nghiệp vụ hiện hiển thị trạng thái chuẩn bị và giai đoạn triển khai;
chưa thực hiện thao tác gửi tin, kết nối Zalo hoặc thanh toán.
Bảng admin dùng số tài khoản thực. Chưa triển khai schema nghiệp vụ/gói,
agent API/QR, batch, automation, báo cáo và thanh toán (giai đoạn 3–11).
Chi tiết và hướng dẫn kiểm tra giao diện: docs/phase-2.md.

## Môi trường

- PHP 8.1+ và Composer 2; extension pdo_mysql, pdo_sqlite (test), mbstring, openssl, fileinfo, dom.
- MySQL 8 và Redis. Máy hiện tại đã có hai dịch vụ đang chạy.
- Node.js 20.19+ hoặc 22.12+ (máy hiện tại Node 24).
- Document root của OpenServer phải trỏ vào thư mục public, không phải gốc dự án.

Dự án tiếp tục dùng thư mục hiện tại zalo-mind để giữ cấu hình OpenServer;
tên hiển thị là zaloMind và namespace cấu hình là zalomind.

## Cài đặt

Trên máy hiện tại, PHP và Composer nằm trong OpenServer, chưa có trong PATH mặc định.
Chạy PowerShell tại thư mục dự án:

```powershell
$env:Path = 'C:\OpenServer\modules\php\PHP_8.1;' + $env:Path
php C:\OpenServer\userdata\composer\composer.phar install
npm.cmd ci
```

Với bản checkout mới chưa có .env: chép .env.example thành .env, nhập cấu hình
database/Redis/mail riêng và chạy php artisan key:generate. Không chép đè .env đang có.
Không đưa .env lên Git. Trên máy này đã giữ nguyên APP_KEY và thông tin kết nối của bạn.

```powershell
php artisan migrate --seed
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Mở http://127.0.0.1:8000/login. Khi dùng OpenServer, mở domain đã cấu hình
với document root trỏ public và sửa APP_URL cho khớp domain đó.

## Tài khoản mẫu

Chỉ được seed khi APP_ENV=local hoặc testing và ZALOMIND_DEMO_ACCOUNTS=true.

| Vai trò | Email | Mật khẩu |
| --- | --- | --- |
| admin | admin@zalomind.test | password |
| user | user1@zalomind.test | password |
| user | user2@zalomind.test | password |

Seeder có thể chạy lại mà không tạo trùng user hoặc đặt lại mật khẩu đã đổi.
Trên môi trường thật đặt ZALOMIND_DEMO_ACCOUNTS=false và tạo tài khoản quản trị riêng.

## Phát triển

Ở terminal khác, chạy npm.cmd run dev khi sửa CSS/JS. Nếu đã dùng npm.cmd run build
thì không cần chạy Vite dev server để xem ứng dụng.

Redis dùng Predis vì PHP hiện tại không có extension phpredis. Cấu hình .env:
QUEUE_CONNECTION=redis, CACHE_DRIVER=redis, REDIS_CLIENT=predis.

Khi có job và scheduler ở các giai đoạn sau:

```powershell
php artisan queue:work redis --tries=3 --timeout=90
php artisan schedule:work
```

BROADCAST_DRIVER hiện để log. Echo chỉ mở kết nối khi VITE_PUSHER_APP_KEY có giá trị.
Khi cấu hình Soketi ở giai đoạn realtime, đặt PUSHER_APP_ID/KEY/SECRET khớp Soketi,
PUSHER_HOST=127.0.0.1, PUSHER_PORT=6001, PUSHER_SCHEME=http, BROADCAST_DRIVER=pusher;
các biến VITE_PUSHER_* tham chiếu tương ứng rồi build lại. Chỉ public app key được
đưa vào Vite; không đưa PUSHER_APP_SECRET vào biến VITE_*.
Nếu dùng Pusher cloud, thay host/port/scheme và cluster theo cấu hình dịch vụ.
Soketi chưa được cài/khởi chạy ở giai đoạn này.

## Bộ nhận diện

Bộ nhận diện nằm trong public/brand: logo SVG chuyển chữ thành path, PNG,
favicon/manifest, ảnh chia sẻ 1200×630, sáu hình minh họa và brand-tokens.css.
Có bản sáng/tối, trắng, xếp dọc và biểu tượng. Xem nhanh public/brand/preview.png.
Nguồn tạo ảnh nằm trong scripts/generate-brand.mjs; tái tạo bằng npm.cmd run brand.
CSS tokens được quản lý riêng, không bị script ghi đè. Giấy phép font kèm tại
public/brand/FONT-LICENSE.txt. Không sử dụng logo hay tài sản của Zalo/VNG.
Logo và theme đổi ngay, không cần tải lại trang. Theme và trạng thái sidebar
được lưu riêng trong localStorage. Font được đóng gói cục bộ bằng Vite.

## Kiểm tra

```powershell
php artisan test
php vendor/bin/pint --test
php artisan view:cache
npm.cmd run build
npm.cmd audit
```

PHPUnit được ép dùng SQLite :memory:, cache array và session array;
không chạy RefreshDatabase vào MySQL thật. Bộ test gồm Breeze auth/profile,
seed lặp lại, đăng ký không tự cấp admin, user bị chặn khỏi /admin
và admin truy cập thành công. WorkspaceTest kiểm tra toàn bộ đích menu user/admin, URL không hợp lệ và trang lỗi 500.

Đã chạy thành công migrate --seed trên MySQL cấu hình trong .env,
35 test/203 assertions qua bộ test hiện có và WorkspaceTest mới, production build và Blade view cache.
Không có trình duyệt kết nối với công cụ kiểm tra ở phiên này nên chưa kiểm tra
hiển thị trực quan, mobile, console hoặc Lighthouse.

Nguồn tham khảo: [Breeze](https://github.com/laravel/breeze),
[Spatie Permission](https://spatie.be/docs/laravel-permission/v6/introduction).
