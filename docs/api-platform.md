# API người dùng và quản trị zaloMind — v1 đề xuất

Ngày: 20/10/2026. **Tài liệu thiết kế, chưa phải danh sách API đã chạy.**
Hiện chỉ có `GET /api/user` dùng Sanctum trong `routes/api.php`; chưa có token login API,
model đơn hàng/gói hoặc controller nghiệp vụ. Các route giao diện `/admin/*` không phải API.

Tài liệu này dành cho APP frontend/mobile hoặc đội xây backend người dùng/admin.
Nếu APP là agent chạy phiên Zalo, dùng [hợp đồng App A](api-app-integration.md).

## 1. Quy ước

Base: `https://<domain-web>/api/v1`. JSON UTF-8; thời gian UTC RFC 3339; ID UUID chuỗi,
ID Zalo chuỗi. Tiền là số nguyên đơn vị VND, `currency=VND`; không dùng float.
Xác thực Bearer token; token user/admin tách hoàn toàn token node. Quyền admin phải kiểm
ở server trên từng request, không chỉ ẩn menu. User chỉ truy cập tài nguyên của mình;
truy cập chéo chủ sở hữu trả 404, user vào admin trả 403.

Thành công: `{"data":{},"meta":{"request_id":"<uuid>"}}`.
Danh sách: `{"data":[],"meta":{"next_cursor":null,"has_more":false,"request_id":"<uuid>"}}`.
Query chung `cursor`, `limit` mặc định 50, tối đa 100. Tìm kiếm `q` tối đa 100 ký tự;
filter enum sai trả 422. Sắp xếp mặc định `created_at DESC,id DESC`, cursor opaque.

Lỗi:

```json
{"error":{"code":"VALIDATION_FAILED","message":"Dữ liệu không hợp lệ","fields":{"email":["Email không hợp lệ"]},"retryable":false},"meta":{"request_id":"<uuid>"}}
```

HTTP: 200 đọc/sửa/action; 201 tạo; 202 tạo tác vụ bất đồng bộ; 204 xóa không có body;
401 token sai/hết hạn; 403 thiếu quyền; 404 không tồn tại; 409 xung đột trạng thái;
422 validation; 429 rate limit có `Retry-After`; 500/503 lỗi tạm thời.
`POST` tạo đơn, hủy/hoàn tiền và tạo job phải có `Idempotency-Key: <uuid>`.
Server lưu khóa tối thiểu 30 ngày theo user/method/path; cùng key khác body trả 409.
Các POST khác cũng hỗ trợ khóa nếu client cần retry an toàn.

Mỗi resource trả `version` số nguyên. PATCH và các action thay đổi resource hiện có
gửi `expected_version`; khác phiên bản hiện tại trả `409 VERSION_CONFLICT`.
Các body trong bảng chỉ liệt kê trường nghiệp vụ, cần thêm expected_version khi áp dụng.
Không chấp nhận mass assignment ngoài danh sách trường cho phép.

## 2. Đăng nhập và tài khoản

| Method + path | Request | Response data |
| --- | --- | --- |
| POST `/auth/login` | `email,password,device_name` | `access_token,token_type=Bearer,expires_at,user` |
| POST `/auth/logout` | Không body | 204, thu hồi token hiện tại |
| GET `/me` | — | User |
| PATCH `/me` | `name` | User đã cập nhật |
| PUT `/me/password` | `current_password,password,password_confirmation` | 204, thu hồi token khác |

`User`: `id,name,email,status,roles[],email_verified_at,created_at,updated_at,version`.
`status=active|suspended`. Không trả password/hash/token khác.
Login sai trả `401 INVALID_CREDENTIALS`, tài khoản khóa trả `403 ACCOUNT_SUSPENDED`;
giới hạn đề xuất 5 lần/phút/IP và email chuẩn hóa. Token đề xuất hết hạn sau 24 giờ;
hết hạn cần đăng nhập lại, P0 không có refresh token. Logout token đã hết hạn trả 401.
Thay mật khẩu kiểm mật khẩu hiện tại, xác nhận khớp và tối thiểu 12 ký tự.
Đổi email/xóa tài khoản/khôi phục mật khẩu vẫn dùng luồng web hiện tại trong P0;
không ngầm coi các endpoint Blade đó là JSON API.

## 3. API người dùng

| Method + path | Input | Response data / hành vi |
| --- | --- | --- |
| GET `/dashboard` | — | `profile_count,connected_profile_count,queued_command_count,current_subscription` |
| GET `/profiles` | `status` | Danh sách Profile |
| POST `/profiles` | `display_name` | 201 Profile; kiểm hạn mức gói |
| GET `/profiles/{id}` | — | Profile |
| PATCH `/profiles/{id}` | `display_name` | Profile |
| POST `/profiles/{id}/login-qr` | `{}` | 202 `command_id,status=queued` |
| GET `/profiles/{id}/login-qr` | — | `qr_id,image_base64,mime_type,expires_at,status`; 404 nếu không có QR còn hạn |
| POST `/profiles/{id}/logout` | `{}` | 202 `command_id,status=queued` |
| GET `/profiles/{id}/contacts` | `q` | Contact từ snapshot đã commit |
| GET `/profiles/{id}/groups` | `q` | Group từ snapshot đã commit |
| POST `/profiles/{id}/sync` | `kind=contacts|groups` | 202 `command_id,status=queued` |
| POST `/messages` | `profile_id,recipient,content` | 202 Command; web kiểm opt-out và quota |
| GET `/commands/{id}` | — | Command với kết quả/lỗi |
| POST `/commands/{id}/cancel` | `{}` | Command; đang chạy chỉ đặt cancel_requested |
| GET `/plans` | — | Gói active công khai |
| GET `/subscription` | — | Subscription hiện hành hoặc null |
| POST `/orders` | `plan_id,billing_period=monthly|yearly` | 201 Order; server tự tính giá |
| GET `/orders` | `status` | Order của user |
| GET `/orders/{id}` | — | Order kèm payment instructions nếu đã cấu hình |
| POST `/orders/{id}/cancel` | `{}` | Order; chỉ cho pending |

Profile: `id,display_name,status,zalo_user_id,connected_at,last_seen_at,created_at,version`.
Command: `id,type,profile_id,status,progress_percent,cancel_requested,output,error,created_at,finished_at,version`.
Contact/Group, recipient/content và trạng thái command theo [hợp đồng agent](api-app-integration.md).
User không được truyền node_id, execution_key, giá tiền hoặc trạng thái thanh toán.
Đơn giá được chụp vào order tại thời điểm tạo; sửa gói không sửa đơn đã tạo.
Profile chưa được admin giao node trả `409 PROFILE_UNASSIGNED` khi yêu cầu chạy lệnh.

Ví dụ gửi tin:

```json
{"profile_id":"<uuid>","recipient":{"type":"user","id":"zalo-123"},"content":{"type":"text","text":"Xin chào"}}
```

```json
{"data":{"id":"<command_uuid>","type":"message.send","status":"queued","version":1}}
```

APP frontend poll command/profile mỗi 3–5 giây trong lúc xử lý; chưa yêu cầu WebSocket.
QR là bí mật ngắn hạn: không cache/log, không hiển thị sau expires_at.

## 4. Quản trị người dùng

Tất cả `/admin/*` sau đây là **API dưới `/api/v1`**, yêu cầu role admin.

| Method + path | Input | Response / ràng buộc |
| --- | --- | --- |
| GET `/admin/dashboard` | — | `users_total,users_active,profiles_connected,orders_pending,revenue_paid_vnd,nodes_online` |
| GET `/admin/users` | `q,status,role` | Danh sách User |
| POST `/admin/users` | `name,email,password,role=user|admin` | 201 User; email duy nhất |
| GET `/admin/users/{id}` | — | User, subscription, tổng profile/đơn |
| PATCH `/admin/users/{id}` | `name` | User |
| PUT `/admin/users/{id}/role` | `role=user|admin,reason` | User; audit thay đổi quyền |
| POST `/admin/users/{id}/suspend` | `reason` | User; thu hồi token, ngừng giao lệnh |
| POST `/admin/users/{id}/activate` | `reason` | User; không tự phục hồi token cũ |

Không cho admin tự khóa/hạ quyền mình hoặc hạ quyền admin hoạt động cuối cùng.
P0 không xóa cứng user có lịch sử đơn hàng. Admin không được đọc password hay session Zalo.
Khóa user chặn lệnh chưa chạy; lệnh đã tác động xử lý theo kết quả thực tế.
Tạo user dùng chính sách mật khẩu mục 2; không log mật khẩu trong audit.

## 5. Gói, đơn hàng và thanh toán

Plan: `id,code,name,monthly_price_vnd,yearly_price_vnd,limits,status,version`.
`limits`: `profiles_max,messages_per_day`; số nguyên không âm.
`code` duy nhất; `status=active|archived`. Gói archived không bán mới.
Subscription: `id,user_id,plan_id,status,starts_at,ends_at,limits_snapshot,version`;
`status=active|expired|cancelled`. Quyền dùng chỉ có khi active và chưa hết hạn.
Order: `id,number,user_id,plan_id,plan_snapshot,billing_period,amount_vnd,currency,status,
payment_status,paid_at,created_at,version`.
`status=pending|paid|cancelled|expired|refunded`; `payment_status=unpaid|paid|refunded`.
Payment: `id,order_id,method,reference,amount_vnd,currency,status,confirmed_at,version`.

| Method + path | Input | Response / ràng buộc |
| --- | --- | --- |
| GET `/admin/plans` | `status` | Plans |
| POST `/admin/plans` | `code,name,monthly_price_vnd,yearly_price_vnd,limits` | 201 Plan |
| PATCH `/admin/plans/{id}` | `name,monthly_price_vnd,yearly_price_vnd,limits` | Plan; áp dụng cho lần mua sau |
| POST `/admin/plans/{id}/archive` | `reason` | Plan; giữ lịch sử |
| GET `/admin/orders` | `q,user_id,status,from,to` | Orders; from/to lọc created_at UTC |
| GET `/admin/orders/{id}` | — | Order, payments, subscription liên quan |
| POST `/admin/orders/{id}/confirm-payment` | `reference,amount_vnd,currency,reason` | Order đã paid + payment + subscription |
| POST `/admin/orders/{id}/cancel` | `reason` | Order; chỉ pending |
| POST `/admin/orders/{id}/refund` | `reason,refund_reference,amount_vnd` | Order refunded; ghi nhận hoàn tiền đã thực hiện ngoài hệ thống |
| GET `/admin/payments` | `order_id,status,from,to` | Payments |
| GET `/admin/subscriptions` | `user_id,status` | Subscriptions |

P0 dùng xác nhận chuyển khoản thủ công, **không có API tự chuyển tiền ngân hàng**.
Confirm chỉ nhận đơn pending, đúng tiền/currency và reference duy nhất theo phương thức;
trong một transaction ghi payment, đổi order và cấp/gia hạn subscription đúng một lần.
Khóa hàng khi confirm để hai admin không cộng thời hạn hai lần.
Gói tháng/năm cộng 1 tháng/1 năm lịch theo UTC, chặn tràn ngày về ngày cuối tháng.
Gia hạn cùng gói từ `max(now,ends_at)`; đổi gói khi còn subscription active trả
`409 ACTIVE_SUBSCRIPTION_CONFLICT` trong P0, chưa tính prorate.
Refund P0 chỉ toàn bộ, chỉ đơn paid, tiền bằng số tiền đơn; không gọi provider để hoàn tiền.
Nếu subscription của đơn đã được dùng/gia hạn tiếp, trả `409 REFUND_REVIEW_REQUIRED`;
chỉ hỗ trợ tự cập nhật khi subscription chưa bắt đầu và chưa có đơn nối tiếp, khi đó hủy subscription.
Webhook cổng thanh toán sẽ có hợp đồng riêng sau khi chọn provider, không dùng node token.

Ví dụ admin xác nhận đơn:

```http
POST /api/v1/admin/orders/<uuid>/confirm-payment
Authorization: Bearer <admin_token>
Idempotency-Key: <uuid>
Content-Type: application/json
```

```json
{"expected_version":1,"reference":"BANK-TXN-123","amount_vnd":299000,"currency":"VND","reason":"Đã đối chiếu sao kê"}
```

## 6. Node, vận hành và nhật ký

| Method + path | Input | Response / ràng buộc |
| --- | --- | --- |
| GET `/admin/nodes` | `status` | `id,name,status,last_heartbeat_at,app_version,capabilities,version` |
| POST `/admin/nodes` | `name,max_concurrency` | 201 node + node_token một lần |
| POST `/admin/nodes/{id}/rotate-token` | `reason` | node_token mới một lần; thu hồi token cũ |
| POST `/admin/nodes/{id}/disable` | `reason` | Node; dừng claim, xử lý lệnh dang dở qua đối soát |
| POST `/admin/nodes/{id}/enable` | `reason` | Node; không tự cấp lại token đã thu hồi |
| GET `/admin/profiles` | `user_id,node_id,status,q` | Profiles kèm owner/node |
| PUT `/admin/profiles/{id}/assignment` | `node_id,reason` | Profile + assignment_version mới |
| GET `/admin/commands` | `user_id,node_id,profile_id,status,type` | Commands |
| GET `/admin/commands/{id}` | — | Command, attempts và kết quả; bỏ lease_token |
| POST `/admin/commands/{id}/cancel` | `reason` | Command |
| POST `/admin/commands/{id}/resolve` | `resolution=succeeded|failed,reason,evidence_reference` | Command đã đối soát; chỉ unknown |
| GET `/admin/audit-logs` | `actor_id,action,resource_type,from,to` | Audit logs chỉ đọc |
| GET `/admin/settings` | — | Danh sách cấu hình công khai cho admin, không trả secret |
| PATCH `/admin/settings` | `support_email,maintenance_mode,expected_version` | Cấu hình; whitelist trường |
| GET `/admin/announcements` | — | Announcements |
| POST `/admin/announcements` | `title,body,published_at,expires_at` | 201 announcement |
| PATCH `/admin/announcements/{id}` | `title,body,published_at,expires_at` | Announcement |
| DELETE `/admin/announcements/{id}` | `expected_version` trong body | 204; audit |

Announcement dùng plain text; title tối đa 200 ký tự, body 10.000 ký tự;
expires_at phải sau published_at. `GET /announcements` cho user trả thông báo đang hiệu lực.
Đổi node phải không còn command đang chạy/unknown và phải dừng worker cũ;
việc chuyển session Zalo giữa máy chưa nằm trong P0. Resolve chỉ ghi nhận kết quả có chứng cứ,
không tự gửi lại hoặc trừ quota thêm. Không có endpoint retry lệnh unknown tự động.

Audit: `id,actor_id,action,resource_type,resource_id,reason,before,after,request_id,created_at`;
ghi cùng transaction thay đổi nghiệp vụ. Che password/token/QR và dữ liệu bí mật.
API admin không được sửa/xóa audit. Mọi action quản trị có reason bắt buộc 5–1.000 ký tự.
Dashboard revenue_paid_vnd là tổng paid amount trừ đơn đã refunded, không phải số dư ngân hàng.

## 7. Phạm vi chưa chốt

Các menu affiliate, proxy, kiểm duyệt, campaign/batch, automation, báo cáo chi tiết
và inbox đã có ở khung giao diện nhưng **chưa đủ quy tắc nghiệp vụ để chốt API**.
Không coi đường dẫn menu là chức năng backend đã hoàn tất. Trước khi đội APP làm các màn này
cần bổ sung schema và workflow: hoa hồng/đối soát, nguồn proxy, quy tắc kiểm duyệt,
khung giờ/tần suất chiến dịch và quyền truy cập hội thoại.

## 8. Bàn giao và nghiệm thu

Đội backend cần triển khai migration/model/policy/controller và token API trước;
đội APP có thể dựng màn hình và mock JSON theo tài liệu. Base URL staging và token thử
do người vận hành cấp riêng, không ghi vào Git. Không có API mới nào đã được kiểm thử HTTP
chỉ nhờ việc viết tài liệu này.

Kiểm thử bắt buộc: phân quyền user/admin/node và truy cập chéo owner; token hết hạn/thu hồi;
hai admin confirm cùng đơn; replay idempotency; sửa resource với version cũ; khóa user;
refund sai số tiền; giới hạn quota; mất kết nối giữa gửi tin và nhận kết quả.
Đội APP và web cần thống nhất JSON cuối cùng trước triển khai; OpenAPI/Postman collection
có thể sinh theo hợp đồng đã chốt để tránh đóng băng nhầm các mục P1/P2.
