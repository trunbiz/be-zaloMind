# Hợp đồng API tích hợp APP ↔ zaloMind — v1

Ngày soạn: 20/10/2026. Cập nhật: 22/10/2026. Trạng thái: **API AGENT ĐÃ TRIỂN KHAI PHÍA WEB**.

Tài liệu bàn giao cho đội App A/agent kết nối Zalo. APP chạy phiên Zalo và thực thi
tác vụ; web zaloMind quản lý người dùng, phân quyền, gói, đơn hàng, lập lịch và giao việc.
APP chủ động gọi web qua HTTPS; web không cần truy cập cổng mở trên máy APP.

**12 endpoint `/api/v1/agent/*` trong tài liệu đã được triển khai**, có migration,
xác thực key, lưu dữ liệu và kiểm thử. Domain local đã kiểm tra:
`http://zalomind.local/api/v1/agent/config` trả 200 với key hợp lệ.
API người dùng/admin và giao diện nghiệp vụ cũng đã được triển khai; xem tài liệu riêng
và [hướng dẫn vận hành](platform-implementation.md).
Không dùng route Blade `/admin/*` làm API JSON.
Xem [hiện trạng giai đoạn 2](phase-2.md) và [API người dùng/admin đề xuất](api-platform.md).

### Chạy backend và tạo dữ liệu để thử tích hợp

```powershell
$env:Path = 'C:\OpenServer\modules\php\PHP_8.1;' + $env:Path
php artisan migrate
php artisan config:clear
php artisan route:clear
```

Không phải tạo node/token. Nếu `/agent/profiles` trả mảng rỗng thì chưa có profile.
Người vận hành tạo profile cho **email user đã có**, rồi tạo lệnh QR login:

```powershell
php artisan agent:profile 'user1@zalomind.test' 'CSKH 01'
php artisan agent:login '<profile_uuid-vua-tao>'
```

Bạn cũng có thể tạo profile và yêu cầu QR trực tiếp ở **Tài khoản Zalo** trên web,
không cần dùng CLI. Hai lệnh trên tạo dữ liệu thật; không chạy lặp để tránh tạo profile thừa. Agent gửi heartbeat
có capability `profile.login_qr`, sau đó claim sẽ nhận lệnh. Các lệnh nghiệp vụ khác do
backend tạo trong bảng `agent_commands` qua model `AgentCommand`; giao diện tạo chiến dịch,
API user/admin, billing/quota và opt-out đã được nối vào luồng tạo lệnh. Agent không được tự tạo job.
Chạy `php artisan schedule:work` để dọn QR hết hạn mỗi phút kể cả khi agent mất kết nối;
heartbeat cũng dọn QR. Scheduler chạy `platform:tick` mỗi phút để kích hoạt kịch bản,
đối soát trạng thái chiến dịch và hết hạn đơn/gói. Không cần queue worker cho polling agent.

## 1. Phân công và thứ tự triển khai

| Hạng mục | Web zaloMind | APP |
| --- | --- | --- |
| Tài khoản người dùng, vai trò, gói, đơn hàng | Lưu trữ, kiểm quyền, quản trị | Không tự sửa |
| Agent | Cấu hình key chung trong `.env`, theo dõi heartbeat, giao profile | Cấu hình cùng key, heartbeat |
| Phiên Zalo | Lưu trạng thái và định danh công khai | Đăng nhập, giữ session, khôi phục kết nối |
| QR đăng nhập | Tạo tác vụ, hiển thị QR đúng người sở hữu | Tạo QR, báo hết hạn/thành công |
| Gửi tin và thao tác Zalo | Kiểm quyền, hạn mức, opt-out, lịch và kiểm duyệt; cấp tác vụ | Kiểm khả năng thực thi, thực thi, báo kết quả |
| Bạn bè, nhóm, hộp thư | Lưu và hiển thị theo chủ sở hữu | Đồng bộ dữ liệu được phép truy cập |
| Retry và lịch sử | Quản lý lần thử, không tạo tác vụ trùng | Ghi sổ chống thực thi lặp, gửi lại kết quả |

Ưu tiên P0: key chung trong `.env` → heartbeat → claim/lease → QR/profile → gửi tin → kết quả.
P1: đồng bộ bạn bè/nhóm, sự kiện hộp thư. P2: nhóm, kết bạn và tác vụ bổ sung.
Web đã có màn thao tác và xử lý các nhóm này; APP chỉ nhận loại lệnh đã công bố hỗ trợ.
Khả năng Zalo nào APP chưa hỗ trợ phải bỏ khỏi `capabilities`; web không giao loại đó.
Danh sách dưới đây là giao thức nội bộ, không khẳng định SDK Zalo đang hỗ trợ sẵn.

## 2. Quy ước chung

- Base URL production: `https://<domain-web>/api/v1`; local: `http://zalomind.local/api/v1`.
- JSON UTF-8, timestamp RFC 3339 UTC, ví dụ `2026-10-20T10:00:00Z`.
- ID nội bộ là UUID dạng chuỗi. ID Zalo luôn là chuỗi, không ép kiểu số.
- Header: `Authorization: Bearer <AGENT_API_KEY>`, `Accept: application/json`,
  `Content-Type: application/json`, `X-Request-Id: <uuid>`.
- Key agent chỉ gọi `/agent/*`, không cấp quyền gọi API user/admin.
  Không gửi Node ID hay token riêng. Web kiểm profile, assignment và lease trên mọi request,
  không tin `user_id` do APP gửi. Key chung xác thực dịch vụ agent, không định danh từng máy.
- APP không gửi cookie/session Zalo, mật khẩu Zalo hoặc token đăng nhập Zalo về web.
- Trường không có dữ liệu dùng `null`; trường tùy chọn có thể bỏ qua. Enum lạ phải báo lỗi,
  không tự chuyển thành thành công. Trường JSON bổ sung có thể được bỏ qua để tương thích.
- Giới hạn đề xuất: JSON 1 MiB; sự kiện tối đa 100 mục/request; một trang đồng bộ
  tối đa 500 mục; server trả `413` nếu quá giới hạn.
- API danh sách dùng `?cursor=<opaque>&limit=50`, `limit` từ 1–100, trả
  `meta: {"next_cursor": null, "has_more": false}`. Snapshot dùng giới hạn riêng ở mục 9.

Thành công:

```json
{"data":{"id":"<uuid>"},"meta":{"request_id":"<uuid>","server_time":"2026-10-20T10:00:00Z"}}
```

Lỗi (không trả stack trace hoặc bí mật):

```json
{"error":{"code":"VALIDATION_FAILED","message":"Dữ liệu không hợp lệ","fields":{"status":["Giá trị không được hỗ trợ"]},"retryable":false},"meta":{"request_id":"<uuid>"}}
```

| HTTP | Code điển hình | APP xử lý |
| --- | --- | --- |
| 400 | `INVALID_JSON` | Sửa request |
| 401 | `AGENT_KEY_INVALID` | Dừng claim, kiểm tra key cấu hình hai bên |
| 403 | `NODE_DISABLED`, `CAPABILITY_DENIED` | Dừng tác vụ bị chặn |
| 404 | `RESOURCE_NOT_FOUND` | Không tự tạo profile thay thế |
| 409 | `LEASE_EXPIRED`, `STALE_ASSIGNMENT`, `IDEMPOTENCY_CONFLICT`, `INVALID_STATE` | Đồng bộ lại, không tiếp tục thực thi |
| 413 / 422 | `PAYLOAD_TOO_LARGE` / `VALIDATION_FAILED` | Chia nhỏ hoặc sửa dữ liệu |
| 429 | `RATE_LIMITED` | Chờ `Retry-After` tính bằng giây |
| 500 / 503 | `INTERNAL_ERROR` / `SERVICE_UNAVAILABLE` | Retry có backoff |

Mạng lỗi/timeout không có nghĩa là server chưa nhận request. Retry cùng khóa idempotency.
Backoff đề xuất 1, 2, 4, 8… tối đa 60 giây, thêm jitter; ưu tiên `Retry-After`.
Web đề xuất 120 request/phút/node; heartbeat và lease cần có quota riêng để không mất lease
do đang gửi snapshot. Giá trị cuối cùng trả trong cấu hình node.

## 3. Cấu hình key chung và khởi động agent

Không cần tạo node trên admin, không có bước lấy Node ID hoặc Node token từ backend.
Người vận hành tự tạo một key ngẫu nhiên tối thiểu 32 byte rồi đặt **cùng giá trị**
trong `.env` của web và `.env` của APP agent:

```dotenv
AGENT_API_KEY=<key-ngau-nhien-dung-chung-cho-web-va-agent>
```

APP chỉ cần cấu hình URL web và key trên, gửi mỗi request:

```http
Authorization: Bearer <gia-tri-AGENT_API_KEY>
```

Phía Laravel đọc qua `config('zalomind.agent_api_key')`, ánh xạ từ `AGENT_API_KEY`.
Middleware phải từ chối nếu key server rỗng hoặc key request thiếu/sai, so sánh bằng
`hash_equals`; không dùng `APP_KEY` làm key agent. Sau đổi `.env` trên Laravel,
chạy `php artisan config:clear` (hoặc build lại config cache khi deploy).
Không đưa key vào Git, URL, log hay biến `VITE_*`. Đổi key bằng cách cập nhật `.env`
hai bên và reload tiến trình; key cũ bị từ chối, không có API cấp/rotate token.

P0 chỉ có **một agent logic**; web tự quản lý bản ghi node mặc định nội bộ và tự giao
profile cho agent này. Mọi chỗ nhắc node trong tài liệu là bản ghi nội bộ đó;
APP không cần biết ID của nó. Các worker cùng agent phải dùng chung execution ledger/outbox.
Chưa hỗ trợ tách quyền hoặc giao profile cho nhiều máy độc lập bằng key chung.

### `GET /agent/config`

```json
{"data":{"enabled":true,"heartbeat_interval_seconds":30,"offline_after_seconds":90,"lease_seconds":43200,"command_timeout_seconds":43200,"execution_managed_by":"app","max_concurrency":2,"poll_interval_seconds":5,"rate_limit_per_minute":120,"event_batch_limit":100,"snapshot_page_limit":500}}
```

Các response ví dụ sau có thể lược `meta`. APP lấy lại config sau reconnect.
Node mất heartbeat trên 90 giây được đánh dấu offline; không tự coi job đang chạy đã thất bại.

### `POST /agent/heartbeat`

Gửi mỗi 30 giây; `capabilities` là các loại command tại mục 6 mà APP thực sự hỗ trợ.

```json
{"app_version":"1.0.0","protocol_version":"1","capabilities":["profile.login_qr","profile.logout","message.send","contacts.sync","groups.sync"],"capacity":2,"running_command_ids":["<command_uuid>"],"health":{"status":"healthy","queue_depth":0}}
```

Trả `200`, `data: {"accepted":true,"server_time":"...","config_changed":false}`.
`health.status`: `healthy|degraded|unhealthy`; không gửi địa chỉ proxy kèm mật khẩu.
Heartbeat **không kéo dài thời hạn hoàn thành command**. APP dừng claim khi unhealthy.
`capacity`, `running_command_ids`, `health.queue_depth` và `max_concurrency` dùng theo dõi;
backend không dùng các số này để chặn giao lệnh. APP tự quản lý hàng đợi và chạy tuần tự.

### `GET /agent/profiles?cursor=...&limit=50`

Chỉ trả profile được web giao agent mặc định; APP không truyền Node ID:

```json
{"data":[{"id":"<profile_uuid>","assignment_version":3,"display_name":"CSKH 01","status":"disconnected","zalo_user_id":null}],"meta":{"next_cursor":null,"has_more":false}}
```

Mỗi profile có thể kèm `proxy: null` hoặc object
`{"scheme":"http|socks5","host":"...","port":8080,"username":null,"password":null}`.
APP tải lại `/agent/profiles` định kỳ (ví dụ sau mỗi heartbeat) để nhận thay đổi proxy.
Thông tin proxy chỉ gửi qua API agent đã xác thực, không ghi ra log. APP cần áp dụng proxy
khi tạo/khôi phục phiên; thay proxy của phiên đang mở có thể cần đăng nhập lại.

Cập nhật 22/10/2026: profile còn có `deleted` (boolean) và `desired_state`
(`connected|disconnected|null`). Khi người dùng xóa, web gỡ khỏi danh sách ngay,
hủy lệnh chưa chạy và vẫn trả profile với `deleted=true, desired_state=disconnected`
trong danh sách agent để dọn phiên. Agent phải dừng công việc cho profile này, đăng xuất,
đóng phiên cục bộ và xử lý `profile.logout` nếu được giao. Sau khi xác nhận đăng xuất,
profile sẽ không còn trong danh sách agent. `desired_state=null` nghĩa là chưa có yêu cầu
đổi kết nối; không tự bắt đầu login chỉ từ trường metadata này, vẫn thực thi bằng command.
Xóa trên web không khẳng định phiên Zalo đã đăng xuất khi agent còn offline.

Web tăng `assignment_version` khi đặt lại phiên giao profile. Mọi job/sự kiện/snapshot gắn profile phải
đúng phiên bản này; dữ liệu từ node cũ trả `409 STALE_ASSIGNMENT`.
P0 không chuyển profile khi còn lệnh chạy hoặc kết quả chưa rõ; phải đối soát và dừng
worker cũ trước khi giao node mới. Phiên bản giao việc không thể thu hồi một lời gọi
Zalo đã bắt đầu; không dùng nó làm bảo đảm gửi đúng một lần.

## 4. Nhận và giữ tác vụ

### `POST /agent/commands/claim`

Header `Idempotency-Key: <uuid>`. Body `{"limit":2}`; `limit` từ 1 đến 100 là số lệnh
tối đa mỗi lần nhận, không phụ thuộc số lệnh App đang chạy. Poll mỗi 5 giây.
Backend tiếp tục giao lệnh `queued` đủ điều kiện, kể cả cùng profile đang có lệnh
`leased`, `running` hoặc `unknown`. APP lưu hàng đợi bền vững và tự thực thi tuần tự.
Trả `200` với mảng rỗng nếu không có lệnh đủ điều kiện. Claim trong transaction,
không giao lại lệnh đã nhận khi gọi bằng khóa mới.

```json
{"data":[{"id":"<command_uuid>","attempt":1,"type":"message.send","profile_id":"<profile_uuid>","assignment_version":3,"execution_key":"<stable_uuid>","lease_token":"<opaque_secret>","lease_expires_at":"2026-10-20T22:00:00Z","deadline_at":null,"payload":{"recipient":{"type":"user","id":"zalo-123"},"content":{"type":"text","text":"Xin chào"}}}]}
```

`execution_key` giữ nguyên khi thử lại cùng thao tác logic; `attempt` và `lease_token`
thay đổi mỗi lần giao. Claim replay cùng khóa trả cùng lô, không claim lô mới.
Nếu lease của lô đã hết, APP phải claim bằng khóa mới và không thực thi lô cũ.

**Chính sách từ 22/10/2026:** thời hạn hoàn thành cố định **12 tiếng kể từ lần giao
lệnh cho APP**, được lưu bằng `dispatched_at` phía web. Thời gian chờ trong hàng đợi
cục bộ APP nằm trong 12 tiếng này. Không tính từ lúc tạo chiến dịch hay lịch gửi tương lai.
Sau 12 tiếng, lệnh `leased|running|unknown` chuyển `failed`, mã `COMMAND_TIMEOUT`,
có `finished_at`; không tự đưa về `queued`. `platform:tick` kiểm tra mỗi phút ngay cả khi
APP tắt; heartbeat và claim cũng dọn lệnh quá hạn. Cần chạy scheduler trên server.
Kết quả thành công/thất bại đã chốt không bị ghi đè bởi kết quả đến sau.

### `POST /agent/commands/{id}/lease`

Body `{"lease_token":"<token>","attempt":1,"assignment_version":3}`.
Giữ endpoint để APP hiện tại kiểm tra yêu cầu hủy. Trả `200` với
`data: {"lease_expires_at":"...","cancel_requested":false}`.
`lease_expires_at` là mốc 12 tiếng cố định; gọi endpoint này không kéo dài mốc đó.
Không cần gia hạn mỗi 30 giây để duy trì lệnh. Kiểm thời gian theo server;
`409` khi token/attempt sai, lệnh đã chốt hoặc đã quá 12 tiếng.

Nếu `cancel_requested=true`, APP hủy tại điểm an toàn rồi báo `cancelled`.
Hủy không thu hồi tin đã gửi; nếu tác động đã hoàn tất thì báo đúng kết quả `succeeded`.
Lease có yêu cầu hủy sẽ không được gia hạn thêm: response vẫn trả hạn hiện tại kèm
cancel_requested=true. APP không tiếp tục thực hiện tác động mới sau hạn này.

## 5. Báo tiến độ và kết quả

### `POST /agent/commands/{id}/progress`

```json
{"lease_token":"<token>","attempt":1,"assignment_version":3,"progress_seq":1,"progress_percent":20,"stage":"connecting"}
```

Trả `200`, `data: {"accepted":true}`. `progress_percent` từ 0–100; `stage` tối đa
100 ký tự, không chứa bí mật. `progress_seq` tăng dần theo attempt; server bỏ qua bản cũ.
Progress không gia hạn lease và không được dùng làm kết quả cuối.

### `POST /agent/commands/{id}/result`

Header `Idempotency-Key: <uuid>`; lưu bền request trước khi gửi.

```json
{"lease_token":"<token>","attempt":1,"assignment_version":3,"execution_key":"<stable_uuid>","status":"succeeded","started_at":"2026-10-20T10:00:05Z","finished_at":"2026-10-20T10:00:06Z","output":{"provider_message_id":"zalo-message-123"},"error":null}
```

`status`: `succeeded|failed|cancelled|unknown`. Khi thất bại:

```json
{"code":"PROFILE_DISCONNECTED","message":"Phiên Zalo đã ngắt","retryable":false,"provider_code":null}
```

Trả `200`, `data: {"command_id":"<uuid>","status":"succeeded","duplicate":false}`.
Replay giống hệt trả kết quả đã lưu, `duplicate=true`; cùng khóa khác body trả `409`.
Token/attempt/assignment phải khớp. Trong 12 tiếng, web nhận kết quả cho cả lệnh đang
`unknown`, kể cả token từng hết hạn theo chính sách 120 giây cũ. `command.late_result`
trong cửa sổ này cũng được kiểm tra và áp dụng như `/result`. Sau 12 tiếng, web giữ
sự kiện trễ làm lịch sử, không ghi đè kết quả đã chốt.

Mã nghiệp vụ: `PROFILE_DISCONNECTED`, `QR_EXPIRED`, `RECIPIENT_NOT_FOUND`,
`PROVIDER_RATE_LIMITED`, `UNSUPPORTED_COMMAND`, `MEDIA_UNAVAILABLE`,
`PROVIDER_REJECTED`, `EXECUTION_UNKNOWN`, `INTERNAL_ERROR`.
Web quyết định retry theo loại lệnh và kết quả; `error.retryable` chỉ là thông tin APP đề xuất.

### Chống gửi trùng và kết quả không xác định

APP cần sổ thực thi bền vững theo `execution_key`: ghi `started` trước tác động,
ghi kết quả sau tác động, giữ tối thiểu 30 ngày; web không tự retry ngoài thời hạn này.
Nếu nhận lại key đã hoàn tất, APP trả lại kết quả cũ. Nếu crash sau khi gửi nhưng trước
khi ghi kết quả và không có cách kiểm chứng phía Zalo, báo `unknown/EXECUTION_UNKNOWN`.
Không tự gửi lại trường hợp này; web cho admin đối soát. Giao thức này không bảo đảm
exactly-once ở phía Zalo nếu provider không hỗ trợ khóa idempotency.

Mất heartbeat hoặc không gia hạn sau 120 giây không làm lệnh chuyển `unknown`.
`unknown` do APP báo không chặn giao lệnh mới; sau 12 tiếng từ khi giao, nếu chưa có
kết quả xác định thì chuyển `failed/COMMAND_TIMEOUT`. Không tự phát lại do timeout.

## 6. Danh mục command web giao APP

Mọi `payload` nằm trong envelope tại mục 4. Mỗi lệnh chỉ có một profile và một thao tác;
web tách chiến dịch thành lệnh từng người nhận, không giao APP tự mở rộng danh sách.

| Type | Payload bắt buộc | Output thành công | Ưu tiên |
| --- | --- | --- | --- |
| `profile.login_qr` | `{}` | `{"zalo_user_id":"...","display_name":"...","avatar_url":null}` | P0 |
| `profile.logout` | `{}` | `{"logged_out":true}` | P0 |
| `message.send` | `recipient`, `content` | `{"provider_message_id":"..."}` | P0 |
| `contact.resolve` | `{"phone":"+84901234567"}` | `{"zalo_user_id":"<provider_user_id>"}` | Gửi theo số điện thoại |
| `contacts.sync` | `{"mode":"full"}` | `{"sync_id":"<uuid>","count":120}` | P1 |
| `groups.sync` | `{"mode":"full"}` | `{"sync_id":"<uuid>","count":10}` | P1 |
| `group.members.sync` | `{"group_id":"...","mode":"full"}` | `{"sync_id":"<uuid>","count":50}` | P1 |
| `friend.request` | `{"target":{"type":"phone","value":"+84901234567"},"message":"Xin chào"}` | `{"requested":true}` | P2 |
| `friend.accept` | `{"request_id":"..."}` | `{"accepted":true}` | P2 |
| `group.invite` | `{"group_id":"...","user_id":"..."}` | `{"invited":true}` | P2 |
| `group.join` | `{"invite_url":"..."}` | `{"group_id":"...","status":"joined"}` hoặc `status=pending_approval` | P2 |
| `group.leave` | `{"group_id":"..."}` | `{"left":true}` | P2 |
| `group.inspect_link` | `{"invite_url":"..."}` | `{"valid":true,"group_id":"...","name":"..."}` | P2 |

`recipient`: `{"type":"user|group","id":"<provider_id>"}`.
`content` P0 chỉ hỗ trợ `{"type":"text","text":"..."}` với chuỗi không rỗng,
tối đa 2.000 ký tự Unicode theo hợp đồng ban đầu; APP báo lỗi nếu provider có giới hạn thấp hơn.
Ảnh/tệp là P1, chỉ bật sau khi thống nhất giới hạn MIME/kích thước với đội APP:
`{"type":"image|file","attachment_id":"<uuid>","caption":null}`.
APP tuyệt đối không xử lý URL file do người dùng tự truyền làm địa chỉ tải tùy ý.

Sau `contacts.sync`/`groups.sync`, APP chỉ báo success khi web đã nhận và hoàn tất snapshot.
`group.members.sync` cũng áp dụng quy tắc đó. Capability không có thì web chặn ngay;
APP vẫn trả `UNSUPPORTED_COMMAND` nếu nhận type không hiểu.

Giao diện nhắn tin bạn bè/nhóm cho phép tìm theo tên và tick chọn một hoặc nhiều mục
từ snapshot của tài khoản Zalo đã chọn. Chưa chọn mục nào thì web báo lỗi, không tự gửi
toàn bộ danh sách. Web kiểm tra lại từng mục thuộc đúng profile và loại danh sách trước
khi tạo chiến dịch; mỗi người nhận/nhóm vẫn nhận một command `message.send` riêng với
`recipient.id` là ID Zalo dạng chuỗi. APP không cần thay đổi payload hay hỗ trợ lệnh gửi hàng loạt mới.

Luồng theo số điện thoại: web giao `contact.resolve`; khi APP trả thành công với ID thật,
web tự tạo `message.send` cho ID đó. Không tự coi số điện thoại là ID Zalo. APP cần thêm
capability `contact.resolve` để nhận loại này. Nếu không tìm được, trả failed với mã
`RECIPIENT_NOT_FOUND`; không tự gửi lời mời kết bạn thay cho việc tìm ID.
Luồng quét link nhóm: `group.inspect_link` → `group.members.sync` → web đưa thành viên
snapshot đã hoàn tất vào tệp khách hàng. Không tự complete snapshot khi chỉ đọc được một phần.

## 7. QR và vòng đời profile

Khi chạy `profile.login_qr`, APP tạo QR rồi gửi `profile.qr.updated` qua mục 8:

```json
{"qr_id":"<uuid>","image_base64":"<raw_base64_png>","mime_type":"image/png","expires_at":"2026-10-20T10:01:00Z"}
```

QR tối đa 256 KiB sau giải mã, chỉ `image/png`; không bao gồm tiền tố data URL.
Web chỉ hiển thị cho chủ profile hoặc admin được cấp quyền, không log nội dung QR,
xóa khi hết hạn hoặc đăng nhập xong. QR mới thay thế QR cũ theo `sequence`.
APP không gửi session credential trong QR event. Hết hạn báo failed `QR_EXPIRED`;
người dùng yêu cầu lại tạo command mới, không tái sử dụng QR.

Profile: `disconnected → connecting → awaiting_qr → connected`;
có thể chuyển sang `expired|error|disconnected`. Mọi thay đổi phát
`profile.status.changed` với payload
`{"status":"connected","zalo_user_id":"...","display_name":"...","avatar_url":null,"reason":null}`.
Trạng thái `connected` là phiên đăng nhập hoạt động, không chỉ là node online.
Sau đăng nhập thành công APP gửi trạng thái và result; node được giữ phiên cục bộ để khôi phục.

## 8. Sự kiện APP → web

### `POST /agent/events`

```json
{"events":[{"event_id":"<uuid>","profile_id":"<profile_uuid>","assignment_version":3,"stream_id":"<uuid>","sequence":1,"type":"profile.status.changed","occurred_at":"2026-10-20T10:00:00Z","command_id":null,"payload":{"status":"disconnected","reason":"session_expired"}}]}
```

APP dùng outbox bền vững. `stream_id` tồn tại bền theo profile/assignment;
`sequence` tăng dần từ 1 và không reset khi restart. Thay assignment tạo stream mới.
`event_id` là khóa chống trùng, giữ nguyên khi retry. Web giữ khóa chống trùng tối thiểu
30 ngày; APP không replay sự kiện quá hạn đó tự động, dùng full sync/đối soát.

Trả `200` với kết quả **từng sự kiện**:

```json
{"data":{"results":[{"event_id":"<uuid>","status":"accepted","error":null}]}}
```

`status`: `accepted|duplicate|rejected`. Lỗi từng phần có `error` theo cấu trúc mục 2;
APP chỉ xóa outbox cho accepted/duplicate, retry lỗi tạm thời. Payload sai phải cách ly
và báo vận hành, không retry vô hạn. Cùng event_id khác payload bị rejected
`IDEMPOTENCY_CONFLICT`. Request JSON/envelope sai trả `422` toàn bộ.
`accepted` nghĩa là web đã lưu bền. Web có thể xử lý bất đồng bộ nhưng không mất sự kiện.

Web xử lý theo sequence từng stream, bỏ cập nhật trạng thái cũ; nếu có khoảng trống,
trả thêm `expected_sequence` để APP phát lại từ outbox. Các event đến sau được lưu chờ,
không ghi đè trạng thái trước khi bù khoảng trống. Không xóa một event đã accepted khỏi
sổ cục bộ trước khi qua cửa sổ replay 30 ngày (có thể đánh dấu đã gửi và nén lưu trữ).
Riêng QR phải xóa ảnh khi hết hạn/đăng nhập xong ở cả hai phía; chỉ giữ event_id,
sequence và hash để chống trùng, không giữ ảnh trong lịch sử replay. Khi không còn ảnh,
APP có thể gửi lại event QR đã hết hạn với cùng sequence, bỏ `image_base64` và thêm
`image_removed=true`; web kiểm command/expiry rồi bỏ qua ảnh và tiến sequence bình thường.
Nếu event_id đã lưu trước đó, retry phải giữ body gốc để nhận duplicate; đổi body với cùng
event_id trả conflict. Với event chưa từng được gửi, có thể dùng event_id mới cho bản metadata.
Cửa sổ chờ tối đa 1000 sequence; vượt cửa sổ trả `INVALID_STATE` để tránh tích dữ liệu vô hạn.

| Type | Payload |
| --- | --- |
| `profile.qr.updated` | Mục 7; `command_id` bắt buộc là lệnh login |
| `profile.status.changed` | Mục 7 |
| `message.received` | `message_id`, `conversation`, `sender_id`, `content`, `sent_at` |
| `message.delivery.updated` | `message_id`, `status=delivered|read|failed`, `at` |
| `friend.request.received` | `request_id`, `sender_id`, `display_name`, `message`, `received_at` |
| `command.late_result` | `command_id` bắt buộc; payload là body result mục 5 |

Ví dụ payload tin đến:

```json
{"message_id":"zalo-msg-123","conversation":{"type":"user","id":"zalo-user-123"},"sender_id":"zalo-user-123","content":{"type":"text","text":"Tôi cần hỗ trợ"},"sent_at":"2026-10-20T10:00:00Z"}
```

Khi nhập lịch sử text cục bộ (cả tin gửi và tin nhận), APP gửi `message.received` với
`payload.historical=true`. Web lưu tin vào hộp thư nhưng không kích hoạt automation
cho tin lịch sử. Tin của chính tài khoản dùng Zalo ID của tài khoản làm `sender_id`,
không dùng giá trị nội bộ `0`. Tin mới dùng `historical=false` hoặc bỏ trường này.
Chỉ nhập dữ liệu provider thực sự cung cấp, không coi lịch sử cục bộ là đầy đủ lịch sử cloud.

Web chống trùng tin theo `(profile_id, message_id)`. Provider không cung cấp loại sự kiện
nào thì APP không tự dựng sự kiện đó. Tin ảnh/tệp cần hợp đồng media riêng trước khi bật.

Hộp thư web gom tin theo `(profile_id, conversation.type, conversation.id)`, hiển thị
lịch sử theo `sent_at`, cả tin gửi và tin nhận. `sender_id` trùng `zalo_user_id` của
profile hiển thị là “Bạn”. Tên bạn bè/nhóm lấy từ snapshot, nếu chưa có tên thì dùng ID.
Người dùng có thể tìm tên, lọc tài khoản/cá nhân/nhóm, xem tin cũ theo trang và trả lời
ngay trong hội thoại. Đánh dấu đã đọc chỉ áp dụng trên web; tin lịch sử và tin của
chính mình không tăng số chưa đọc.

APP tiếp tục dùng duy nhất `POST /agent/events`, không cần command hay API đồng bộ
tin nhắn riêng. Có thể gửi header `Idempotency-Key: <request_uuid>`; chống trùng theo
từng `event_id` và `(profile_id, message_id)`. Gửi lại cùng tin với event ID mới trả
`duplicate` và vẫn tiếp nhận sequence mới để không tạo khoảng trống trong stream.

## 9. Đồng bộ bạn bè, nhóm và thành viên

### `POST /agent/profiles/{id}/snapshots`

Header idempotency. Body:
`{"assignment_version":3,"kind":"contacts","mode":"full","group_id":null}`.
`kind`: `contacts|groups|group_members`; `group_id` bắt buộc cho `group_members`.
Trả `201`, `data: {"sync_id":"<uuid>","page_limit":500,"expires_at":"..."}`.
Snapshot hết hạn sau 1 giờ; chỉ một full snapshot cùng kind/profile/group chạy một lúc,
xung đột trả `409`. Snapshot thuộc node, assignment và profile tạo ra nó.

### `PUT /agent/profiles/{id}/snapshots/{sync_id}/pages/{page}`

`page` bắt đầu từ 1, liên tiếp. Body `{"items":[...]}` tối đa 500 mục và 1 MiB.
Trả `200`, `data: {"page":1,"received_count":1}`. Gửi lại cùng trang cùng nội dung là
an toàn; khác nội dung trả `409`. Các trang chỉ ghi vào vùng staging.

| Kind | Một item |
| --- | --- |
| contacts | `{"zalo_user_id":"...","display_name":"...","avatar_url":null,"phone":null}` |
| groups | `{"group_id":"...","name":"...","avatar_url":null,"member_count":50}` |
| group_members | `{"zalo_user_id":"...","display_name":"...","role":"member"}` |

`phone` chỉ gửi nếu dữ liệu hợp lệ thực sự có; không suy đoán. `role`: `owner|admin|member|unknown`.
ID phải duy nhất trong snapshot. URL avatar là dữ liệu hiển thị, server không tải URL tùy ý.

### `POST /agent/profiles/{id}/snapshots/{sync_id}/complete`

Header idempotency. Body `{"total_pages":1,"total_items":1}`. Trả `200`,
`data: {"sync_id":"<uuid>","status":"completed","count":1}`.
Web kiểm đủ trang, tổng item, ID trùng và assignment rồi thay dữ liệu trong transaction.
Snapshot rỗng hợp lệ gửi `total_pages=0,total_items=0`; không cần upload trang rỗng.
Chỉ xóa dữ liệu cũ vắng mặt sau complete thành công. Mất mạng/hết hạn không được xóa dữ liệu.
APP không complete nếu provider chỉ trả được danh sách một phần.

## 10. Tải media (P1)

### `GET /agent/attachments/{id}/download`

APP dùng Bearer key chung để tải trực tiếp binary, chỉ khi attachment được tham chiếu bởi
command có lease hiện hành của node. Trả `200` với `Content-Type`, `Content-Length`,
`X-Content-SHA256` (hex), hoặc `404/409` nếu không được cấp/lease hết hạn.
Không trả envelope JSON khi thành công, không redirect sang URL tùy ý.
APP kiểm kích thước/checksum, chỉ tải qua HTTPS, xóa tệp tạm sau xử lý.
P0 chưa hỗ trợ media; upload từ người dùng nằm phía web, không thuộc API agent này.

## 11. Idempotency và trạng thái lệnh

Web lưu khóa idempotency cho claim, tạo/complete snapshot và result tối thiểu 30 ngày,
scope `(agent_default, method, path, key)`, kèm hash body và response. Bản ghi đang xử lý trả
`409 REQUEST_IN_PROGRESS` với `retryable=true`. Kiểm replay đã xác thực trước kiểm lease
để APP có thể nhận lại response result đã commit dù lease sau đó hết hạn.
Key thiếu/sai luôn bị chặn, kể cả replay. Đổi key không xóa lịch sử chống trùng.

Trạng thái web: `pending_approval → queued → leased → running → succeeded|failed|cancelled|unknown`.
`pending_approval` chỉ xuất hiện nếu admin bật kiểm duyệt; APP không claim được trạng thái này.
Lệnh login/logout được ưu tiên hơn lệnh nghiệp vụ và được giao ngay khi đủ điều kiện;
không cần đợi lệnh đang chạy hay `unknown` của cùng profile. APP tự xếp thứ tự thực thi.
Lệnh đã giao nhưng chưa có kết quả xác định sau 12 tiếng chuyển `failed/COMMAND_TIMEOUT`.
Lệnh có thêm lịch nội bộ `available_at`; server chỉ giao khi đến giờ, chiến dịch không bị tạm dừng,
chủ sở hữu còn hoạt động và không có chặn nhận tin tại thời điểm claim.
`running` khi nhận progress hoặc tín hiệu bắt đầu; việc thiếu tín hiệu không chứng minh
APP chưa thực thi. `unknown` cần đối soát trước retry tác động. Lệnh đã terminal không
được ghi đè bằng kết quả khác; sửa trạng thái qua quy trình đối soát có audit.
Claim kiểm profile/assignment, heartbeat, capability, lịch gửi và điều kiện hủy;
không chặn bởi slot backend hoặc trạng thái các lệnh khác cùng profile.
Lớp tạo lệnh nghiệp vụ đã kiểm quyền người dùng, hạn mức, opt-out và nội dung bị chặn.
Hạn mức ngày tính theo Asia/Ho_Chi_Minh; lệnh queued/leased/running/succeeded/unknown
giữ chỗ hạn mức, failed/cancelled được giải phóng. APP không được tự tăng quota.

## 12. Ví dụ gọi thử

Trong PowerShell, sau khi đặt key vào biến môi trường của terminal APP:

```powershell
curl.exe -i 'http://zalomind.local/api/v1/agent/config' `
  -H "Authorization: Bearer $env:ZALOMIND_API_KEY" `
  -H 'Accept: application/json'
```

Tên biến APP `ZALOMIND_API_KEY` có thể khác tên biến web `AGENT_API_KEY`, nhưng **giá trị
phải giống hệt nhau**. `.env` của web không tự trở thành biến `$env:*` trong PowerShell.
Thiếu/sai giá trị trả `401 AGENT_KEY_INVALID`; 404 nghĩa là sai domain/path hoặc route chưa nạp.
Backend không trả key qua API; sao chép giá trị từ `.env` web sang cấu hình agent qua kênh riêng.

### Xử lý `401 AGENT_KEY_INVALID` trên APP

- APP phải gửi **giá trị** key, không gửi chuỗi `$env:ZALOMIND_API_KEY`, tên biến,
  tiền tố `AGENT_API_KEY=` hoặc dấu nháy bao quanh key.
- Nếu APP đọc cấu hình từ biến môi trường, đặt `ZALOMIND_API_KEY` bằng giá trị
  `AGENT_API_KEY` của web rồi khởi động lại APP. Terminal đã mở và tiến trình APP đang chạy
  có thể vẫn giữ giá trị cũ. APP cần tự nạp `.env` nếu dùng file cấu hình này.
- Khi key trống, APP nên báo thiếu cấu hình ngay tại màn hình kết nối và dừng claim.
  Với lỗi 401, kiểm tra lại cấu hình trước khi gửi tiếp; không lặp claim liên tục.
- Dòng cURL đã che key không chứng minh APP đang dùng đúng giá trị. Kiểm tra bằng
  `GET /api/v1/agent/config` trước; HTTP 200 xác nhận key và domain hợp lệ.

Để thử ngay trên máy có mã nguồn web, chạy trong PowerShell tại thư mục dự án
`C:\OpenServer\domains\zalo-mind`. Đoạn dưới chỉ nạp key vào terminal hiện tại,
không in key và không thay đổi cấu hình APP đang chạy:

```powershell
$webEnv = [IO.File]::ReadAllText((Join-Path (Get-Location) '.env'))
$keyMatch = [regex]::Match($webEnv, '(?m)^AGENT_API_KEY=([^\r\n]*)')
$env:ZALOMIND_API_KEY = $keyMatch.Groups[1].Value.Trim().Trim('"').Trim("'")
if ([string]::IsNullOrWhiteSpace($env:ZALOMIND_API_KEY)) {
  throw 'Chưa có AGENT_API_KEY trong .env web.'
}
curl.exe -i 'http://zalomind.local/api/v1/agent/config' `
  -H "Authorization: Bearer $env:ZALOMIND_API_KEY" `
  -H 'Accept: application/json'
```

Biến `BASE_URL` là URL web; `AGENT_API_KEY` lấy từ cấu hình `.env` đã thống nhất hai bên.
Ví dụ cho shell bash, không phải lệnh chạy trực tiếp trong PowerShell.
Không dùng key thật trong tài liệu hoặc Git.

```bash
curl "$BASE_URL/api/v1/agent/config" \
  -H "Authorization: Bearer $AGENT_API_KEY" -H 'Accept: application/json'

curl -X POST "$BASE_URL/api/v1/agent/commands/claim" \
  -H "Authorization: Bearer $AGENT_API_KEY" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: <uuid>' -d '{"limit":1}'
```

## 13. Tiêu chí nghiệm thu liên thông

1. Key thiếu/sai hoặc key server rỗng bị từ chối; key agent không truy cập được API user/admin.
2. Restart APP vẫn giữ execution ledger, outbox và sequence; không phát lại thao tác đã xong.
3. QR mới/hết hạn/thành công hiển thị đúng; logout/ngắt phiên cập nhật đúng chủ profile.
4. Hai worker claim đồng thời không cùng nhận một lease; assignment cũ bị chặn sau khi đặt lại.
5. Gửi tin trả provider_message_id; APP báo unknown không chặn lệnh mới; quá 12 tiếng từ khi giao thì failed/COMMAND_TIMEOUT, không tự gửi lại.
6. Kết quả đã commit nhưng response mất: retry trả kết quả cũ, không thực thi lại.
7. Event trùng/lệch thứ tự, lỗi một phần và snapshot gián đoạn không làm mất dữ liệu.
8. Hủy khi queued và hủy khi đang chạy phản ánh đúng tác động thực tế.
9. Rate limit, agent offline, hết lease và đổi key `.env` có luồng phục hồi kiểm chứng được.
10. APP báo rõ capabilities, phiên bản; web không giao tác vụ chưa hỗ trợ.

## 14. Thông tin cần hai đội chốt khi bắt đầu

- Domain staging/production, cấu hình cùng `AGENT_API_KEY` hai bên và phiên bản APP đầu tiên.
- Khả năng thực tế của APP cho từng command/event; cách phục hồi phiên và đối soát tin gửi.
- Giới hạn provider: độ dài tin, tốc độ, kích thước/media được hỗ trợ.
- P0 dùng polling như tài liệu; nếu cần push/WebSocket sẽ bổ sung giao thức, không thay ngầm.
- Web đã có quản lý proxy và trả cấu hình qua profile; APP thực hiện áp dụng proxy.
- Media nhận vào, delta sync và chuyển phiên giữa nhiều node chưa nằm trong giao thức hiện tại.

Các giá trị timeout/quota trong tài liệu là mặc định đề xuất. Trước khi kết nối thật,
hai đội phải thống nhất bản hợp đồng này và thực thi kiểm thử mục 13 trên staging.
