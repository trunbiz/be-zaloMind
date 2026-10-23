# Vận hành zaloMind sau khi triển khai nghiệp vụ

Cập nhật 21/10/2026. Các trang giữ chỗ “sẽ triển khai ở giai đoạn…” đã được thay bằng
giao diện và thao tác lưu dữ liệu thật. Hoạt động Zalo được giao cho APP agent và chỉ
ghi nhận thành công sau khi agent trả kết quả; web không tự mô phỏng kết nối hoặc gửi tin.

## Khởi động

```powershell
$env:Path = 'C:\OpenServer\modules\php\PHP_8.1;' + $env:Path
php artisan migrate
php artisan config:clear
php artisan route:clear
php artisan view:clear
npm.cmd run build
php artisan schedule:work
```

OpenServer phục vụ public. APP dùng cùng giá trị AGENT_API_KEY với .env web.
Chỉ chạy một schedule:work cho dự án này; scheduler gọi platform:tick mỗi phút và dọn QR.
Tác vụ đến giờ được agent claim qua polling, không yêu cầu queue worker Redis riêng.
Khi khởi động lại máy cần chạy lại scheduler hoặc cấu hình dịch vụ tự khởi động của máy.
Không chạy artisan test với database thật; phpunit.xml ép dùng SQLite :memory:.

## Người dùng

| Menu | Thao tác đã triển khai |
| --- | --- |
| Tài khoản Zalo | Tạo, đổi tên, QR tự cập nhật; bấm lại dùng yêu cầu hiện tại; ngắt kết nối tự hủy hàng đợi; xóa khỏi danh sách ngay và gửi yêu cầu đăng xuất tới agent |
| Nhắn bạn bè / nhóm | Tìm theo tên, chọn một hoặc nhiều mục từ danh sách đồng bộ của tài khoản; tạo chiến dịch, lịch gửi, khoảng cách; tạm dừng/tiếp tục/hủy |
| Theo tệp / nhãn | Lấy khách hàng theo list_name hoặc labels; cùng cơ chế chiến dịch |
| Theo số điện thoại | Resolve số ra ID thật qua agent, sau đó tạo message.send; không gửi số điện thoại như ID Zalo |
| Sinh nhật | Lọc ngày sinh hôm nay, gửi ngay hoặc tạo quy tắc chạy hằng ngày; tạm dừng/xóa quy tắc |
| Chăm sóc theo nhãn | Khi có tin nhắn đến từ khách có nhãn phù hợp, tạo phản hồi và áp dụng khoảng nghỉ |
| Kịch bản nhiều bước | Tạo các bước theo phút kể từ lúc bắt đầu; scheduler tạo lịch gửi từng bước đúng một lần |
| Xoay vòng | Tạo chiến dịch định kỳ, luân phiên tài khoản trong danh sách được chọn |
| Hộp thư | Hội thoại cá nhân/nhóm theo từng tài khoản, tìm tên, xem lịch sử gửi/nhận, tự cập nhật 5 giây, phân trang tin cũ, đánh dấu đã đọc trên web và trả lời đúng hội thoại |
| Bạn bè | Xem danh sách đồng bộ, kết bạn theo danh sách số, chấp nhận lời mời |
| Khách hàng | Thêm/sửa/xóa liên hệ, nhãn, ngày sinh, tệp; nhập CSV hoặc dán nội dung |
| Quét nhóm/link | Inspect link, đồng bộ thành viên và nhập vào tệp khách hàng sau snapshot hoàn tất |
| Từ chối nhận tin | Quản lý ID/số bị chặn; kiểm trước tạo và trước claim |
| Nhóm | Mời thành viên, tham gia bằng link, kiểm tra link, rời nhiều nhóm; theo dõi kết quả agent |
| Báo cáo | Trạng thái/tiến độ/kết quả, lọc trạng thái, hủy lệnh, xuất CSV có chống công thức spreadsheet |
| Gói & thanh toán | Xem quyền lợi/hạn mức, tạo đơn/gia hạn, hướng dẫn chuyển khoản, xem trạng thái/hủy đơn pending |
| Affiliate | Link giới thiệu khi đăng ký, hoa hồng đơn đã thanh toán, đề nghị chi trả sau khi admin duyệt |

Gói miễn phí ban đầu: 1 tài khoản Zalo và 100 tin/ngày, chỉnh được ở admin/Gói dịch vụ.
Từ 22/10/2026, ngắt kết nối/xóa không yêu cầu người dùng đi hủy từng tác vụ. Tác vụ
chưa chạy được hủy tự động, kịch bản dùng profile được tạm dừng; tác vụ đang chạy nhận
yêu cầu dừng. Xóa profile là lưu trữ mềm: không chiếm slot gói nhưng vẫn giữ lịch sử
và hạn mức đã sử dụng. Phiên Zalo được đăng xuất khi agent xử lý yêu cầu. Lệnh gửi có
kết quả chưa rõ không chặn giao lệnh mới. App tự chạy tuần tự; lệnh đã giao mà sau
12 tiếng chưa hoàn thành chuyển thất bại `COMMAND_TIMEOUT`, không tự gửi lại.
Chưa tạo sẵn gói trả phí hoặc tài khoản ngân hàng giả. Admin nhập giá và hướng dẫn ngân hàng thật.
Tin nhắn hiện hỗ trợ văn bản tối đa 2000 ký tự. Upload ảnh/tệp và đồng bộ media nhận vào
cần bổ sung hợp đồng với APP; hiện không hiển thị thao tác media chưa có backend.

## Biểu mẫu và API operations

Body API operations dùng cùng tên trường với biểu mẫu, ngoài Idempotency-Key:

- Chiến dịch nhắn tin: profile_id, name, text, recipient_ids (mỗi dòng một ID/số),
  source_value (tên tệp hoặc nhãn), scheduled_at (RFC3339 hoặc giờ local), interval_seconds.
  recipient_ids trống dùng nguồn tương ứng menu. Tối đa 1000 người nhận/lượt.
- Kết bạn: profile_id, phones (mỗi dòng một số), message. Tối đa 200 số/lượt.
- Quét thành viên: profile_id, group_id, list_name. Quét link: profile_id, invite_url, list_name.
- Mời nhóm: profile_id, group_id, ids (ID Zalo từng dòng). Rời nhóm: profile_id, ids (ID nhóm).
  Tham gia/kiểm link: profile_id, invite_url.
- Kịch bản: name, profile_id, source=message-friends|message-lists|message-labels|message-birthdays,
  source_value, recipient_ids, interval_minutes (ít nhất 5), scheduled_at tùy chọn.
  Chăm sóc nhãn thêm label và text; drip thêm steps, mỗi dòng số_phút|nội_dung;
  rotation thêm text và profile_ids[] tùy chọn để chọn tài khoản luân phiên.
- Khách hàng: name, phone hoặc zalo_user_id, list_name, labels (ngăn cách dấu phẩy), birthday YYYY-MM-DD.
  CSV: name,phone,zalo_user_id,labels,birthday; có thể có header. Dấu phẩy trong trường cần bao ngoặc kép.
  Import tối đa 1000 dòng và 2 MiB file. Tên tệp lấy từ trường list_name ngoài CSV.

## Quản trị

- Người dùng: tạo, đổi tên/quyền, khóa/mở khóa, đặt lại mật khẩu. Không được tự khóa/hạ quyền
  hoặc loại bỏ admin hoạt động cuối cùng. Khóa thu hồi API token và hủy/yêu cầu dừng các lệnh.
- Gói: thêm/sửa giá/hạn mức, ngừng/mở bán; đơn cũ và gói đang dùng giữ snapshot đã mua.
- Đơn hàng: xác nhận đúng số tiền và mã giao dịch duy nhất; cấp/gia hạn quyền lợi một lần
  trong transaction. Đơn pending hết hạn sau 24 giờ. Không tự chuyển tiền ngân hàng.
- Hoàn tiền: chỉ ghi nhận hoàn toàn bộ sau khi đã hoàn ngoài hệ thống. Gói đã bắt đầu,
  có kỳ gia hạn tiếp hoặc hoa hồng đã chi cần đối soát trước, không tự thu hồi quyền lợi.
- Affiliate: tỷ lệ cấu hình ở Settings, mặc định 0%; duyệt hoa hồng và ghi nhận chi trả bằng mã giao dịch.
- Node: theo dõi heartbeat/phiên bản/capabilities, bật/tắt giao việc, chỉnh số lệnh đồng thời.
  Một agent logic mặc định, dùng key .env, không cấp Node ID/token riêng.
- Proxy: quản lý host/port/giao thức/credential; credential được mã hóa trong DB;
  gắn proxy cho profile qua Giám sát profile. APP tải cấu hình và áp dụng proxy.
- Queue: hủy, duyệt, đối soát unknown bằng bằng chứng. Không tự retry lệnh có thể đã gửi thành công.
  Kết quả đối soát lệnh resolve số cần output_json chứa zalo_user_id.
- Kiểm duyệt: quản lý từ khóa chặn; tùy chọn yêu cầu admin duyệt mọi tin trước khi gửi.
- Settings: bảo trì thao tác Zalo, email hỗ trợ, thông tin chuyển khoản, tỷ lệ affiliate, chế độ duyệt.
- Thông báo: plain text có thời gian bắt đầu/hết hạn, hiển thị tại Tổng quan người dùng.
- Audit: nhật ký chỉ đọc ghi actor/action/resource/reason, không lưu mật khẩu, key hoặc ảnh QR.

## Những điều phụ thuộc hệ thống ngoài

APP phải thực thi các loại lệnh/sự kiện theo [hợp đồng agent](api-app-integration.md),
đặc biệt contact.resolve cho nhắn theo số và group.members.sync cho quét nhóm.
Web sẽ giữ lệnh chưa có capability phù hợp trong hàng đợi, không báo gửi thành công giả.
Thanh toán/hoa hồng dùng đối chiếu thủ công, chưa tích hợp cổng ngân hàng hay tự chuyển tiền.
Nhiều máy agent độc lập, di chuyển session Zalo và media cần hợp đồng mở rộng riêng.
