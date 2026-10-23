# zaloMind

Cập nhật 21/10/2026: đã triển khai giao diện nghiệp vụ người dùng/admin,
API người dùng/admin và 12 API agent dùng key chung `AGENT_API_KEY`.
Xem [hướng dẫn vận hành](docs/platform-implementation.md),
[API agent](docs/api-app-integration.md) và [API nền tảng](docs/api-platform.md).

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

Các menu đã có dữ liệu và thao tác thực: profile/QR, bạn bè/nhóm, khách hàng/nhãn/tệp,
chiến dịch/lịch gửi/kịch bản, inbox, báo cáo CSV, gói/đơn hàng/chuyển khoản thủ công,
affiliate, quản trị tài khoản/quyền, node/proxy, kiểm duyệt, cấu hình và audit.
Quyền owner, hạn mức và opt-out được kiểm ở server. Kết quả Zalo phải do APP agent xác nhận;
web không gửi tin hoặc kết nối Zalo giả. Thanh toán và chi hoa hồng được đối chiếu thủ công.
Ảnh/tệp trong tin nhắn, cổng thanh toán tự động và nhiều agent độc lập cần hợp đồng mở rộng.
docs/phase-2.md được giữ làm lịch sử giai đoạn dựng khung giao diện.

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

Scheduler cần chạy cho kịch bản tự động, dọn QR và hết hạn đơn/gói:

```powershell
php artisan queue:work redis --tries=3 --timeout=90
php artisan schedule:work
```

Polling agent hiện không yêu cầu queue worker; chỉ cần schedule:work cho nghiệp vụ định kỳ.

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
