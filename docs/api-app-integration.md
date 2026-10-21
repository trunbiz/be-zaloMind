# Hợp đồng API tích hợp APP ↔ zaloMind — v1

Ngày soạn: 20/10/2026. Trạng thái: **ĐỀ XUẤT ĐỂ HAI BÊN TRIỂN KHAI**.

Tài liệu bàn giao cho đội App A/agent kết nối Zalo. APP chạy phiên Zalo và thực thi
tác vụ; web zaloMind quản lý người dùng, phân quyền, gói, đơn hàng, lập lịch và giao việc.
APP chủ động gọi web qua HTTPS; web không cần truy cập cổng mở trên máy APP.

**Các endpoint `/api/v1/*` dưới đây chưa tồn tại trong mã nguồn hiện tại.**
`routes/api.php` hiện chỉ có `GET /api/user` dùng `auth:sanctum`; chưa có API cấp
token cho APP. Không dùng route Blade `/admin/*` làm API JSON.
Xem [hiện trạng giai đoạn 2](phase-2.md) và [API người dùng/admin đề xuất](api-platform.md).

## 1. Phân công và thứ tự triển khai

| Hạng mục | Web zaloMind | APP |
| --- | --- | --- |
| Tài khoản người dùng, vai trò, gói, đơn hàng | Lưu trữ, kiểm quyền, quản trị | Không tự sửa |
| Node | Tạo node, cấp/thu hồi token, giao profile | Lưu token an toàn, heartbeat |
| Phiên Zalo | Lưu trạng thái và định danh công khai | Đăng nhập, giữ session, khôi phục kết nối |
| QR đăng nhập | Tạo tác vụ, hiển thị QR đúng người sở hữu | Tạo QR, báo hết hạn/thành công |
| Gửi tin và thao tác Zalo | Kiểm hạn mức, người nhận từ chối, lịch, cấp tác vụ | Kiểm khả năng thực thi, thực thi, báo kết quả |
| Bạn bè, nhóm, hộp thư | Lưu và hiển thị theo chủ sở hữu | Đồng bộ dữ liệu được phép truy cập |
| Retry và lịch sử | Quản lý lần thử, không tạo tác vụ trùng | Ghi sổ chống thực thi lặp, gửi lại kết quả |

Ưu tiên P0: token node → heartbeat → claim/lease → QR/profile → gửi tin → kết quả.
P1: đồng bộ bạn bè/nhóm, sự kiện hộp thư. P2: nhóm, kết bạn và tác vụ bổ sung.
Khả năng Zalo nào APP chưa hỗ trợ phải bỏ khỏi `capabilities`; web không giao loại đó.
Danh sách dưới đây là giao thức nội bộ đề xuất, không khẳng định SDK Zalo đang hỗ trợ sẵn.

## 2. Quy ước chung

- Base URL: `https://<domain-web>/api/v1`. Domain thật do bên web cung cấp.
- JSON UTF-8, timestamp RFC 3339 UTC, ví dụ `2026-10-20T10:00:00Z`.
- ID nội bộ là UUID dạng chuỗi. ID Zalo luôn là chuỗi, không ép kiểu số.
- Header: `Authorization: Bearer <node_token>`, `Accept: application/json`,
  `Content-Type: application/json`, `X-Request-Id: <uuid>`.
- Token node chỉ gọi `/agent/*`, ràng buộc một `node_id`; không được gọi API admin.
  Web kiểm node, profile được giao và lease trên mọi request, không tin `user_id` do APP gửi.
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
| 401 | `TOKEN_INVALID`, `TOKEN_REVOKED` | Dừng claim, yêu cầu cấp lại token |
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

## 3. Cấp token và khởi động node

Admin tạo node trên web, nhận `node_id` và token ngẫu nhiên chỉ hiển thị một lần,
sau đó cấu hình thủ công vào APP. Không có endpoint đăng ký node công khai.
Web lưu hash token, hỗ trợ rotate/revoke; APP lưu trong kho bí mật hệ điều hành.

### `GET /agent/config`

```json
{"data":{"node_id":"<node_uuid>","enabled":true,"heartbeat_interval_seconds":30,"offline_after_seconds":90,"lease_seconds":120,"max_concurrency":2,"poll_interval_seconds":5,"rate_limit_per_minute":120,"event_batch_limit":100,"snapshot_page_limit":500}}
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
Heartbeat **không gia hạn lease command**. APP dừng claim khi unhealthy/capacity đã đầy.

### `GET /agent/profiles?cursor=...&limit=50`

Chỉ trả profile được giao node này:

```json
{"data":[{"id":"<profile_uuid>","assignment_version":3,"display_name":"CSKH 01","status":"disconnected","zalo_user_id":null}],"meta":{"next_cursor":null,"has_more":false}}
```

Web tăng `assignment_version` khi đổi node. Mọi job/sự kiện/snapshot gắn profile phải
đúng phiên bản này; dữ liệu từ node cũ trả `409 STALE_ASSIGNMENT`.
P0 không chuyển profile khi còn lệnh chạy hoặc kết quả chưa rõ; phải đối soát và dừng
worker cũ trước khi giao node mới. Phiên bản giao việc không thể thu hồi một lời gọi
Zalo đã bắt đầu; không dùng nó làm bảo đảm gửi đúng một lần.

## 4. Nhận và giữ tác vụ

### `POST /agent/commands/claim`

Header `Idempotency-Key: <uuid>`. Body `{"limit":2}`; giới hạn 1 đến số slot trống,
server kiểm thêm `max_concurrency`. Poll mỗi 5 giây khi còn slot. Trả `200` với mảng
rỗng nếu không có việc. Claim trong transaction, không phát cùng lease cho hai worker.

```json
{"data":[{"id":"<command_uuid>","attempt":1,"type":"message.send","profile_id":"<profile_uuid>","assignment_version":3,"execution_key":"<stable_uuid>","lease_token":"<opaque_secret>","lease_expires_at":"2026-10-20T10:02:00Z","deadline_at":"2026-10-20T10:10:00Z","payload":{"recipient":{"type":"user","id":"zalo-123"},"content":{"type":"text","text":"Xin chào"}}}]}
```

`execution_key` giữ nguyên khi thử lại cùng thao tác logic; `attempt` và `lease_token`
thay đổi mỗi lần giao. Claim replay cùng khóa trả cùng lô, không claim lô mới.
Nếu lease của lô đã hết, APP phải claim bằng khóa mới và không thực thi lô cũ.

### `POST /agent/commands/{id}/lease`

Body `{"lease_token":"<token>","attempt":1,"assignment_version":3}`.
Gia hạn mỗi 30 giây khi lệnh chạy. Trả `200` với
`data: {"lease_expires_at":"...","cancel_requested":false}`.
Kiểm thời gian theo server; `409` khi lease sai/hết hạn. APP ngừng bắt đầu tác động mới
khi không gia hạn được trước hạn; lời gọi Zalo đã bắt đầu phải ghi nhận kết quả.

Nếu `cancel_requested=true`, APP hủy tại điểm an toàn rồi báo `cancelled`.
Hủy không thu hồi tin đã gửi; nếu tác động đã hoàn tất thì báo đúng kết quả `succeeded`.

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
Chỉ lease hiện hành được ghi kết quả chính thức. Nếu đã hết lease, APP giữ bản ghi cục bộ,
gửi `command.late_result` ở mục 8; web chuyển đối soát, không tự đánh dấu thành công.

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

Web phải đánh dấu job hết lease là `unknown` nếu có thể đã bắt đầu tác động;
không tự phát lại lệnh gửi tin/kết bạn chỉ dựa vào timeout. Lệnh đọc có thể retry an toàn.

## 6. Danh mục command web giao APP

Mọi `payload` nằm trong envelope tại mục 4. Mỗi lệnh chỉ có một profile và một thao tác;
web tách chiến dịch thành lệnh từng người nhận, không giao APP tự mở rộng danh sách.

| Type | Payload bắt buộc | Output thành công | Ưu tiên |
| --- | --- | --- | --- |
| `profile.login_qr` | `{}` | `{"zalo_user_id":"...","display_name":"...","avatar_url":null}` | P0 |
| `profile.logout` | `{}` | `{"logged_out":true}` | P0 |
| `message.send` | `recipient`, `content` | `{"provider_message_id":"..."}` | P0 |
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
APP báo khoảng trống QR đã hết hạn và phát trạng thái hiện hành; web cho phép bỏ qua
sequence QR đó sau khi kiểm command/expiry, không chặn stream vĩnh viễn vì QR đã xóa.

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

Web chống trùng tin theo `(profile_id, message_id)`. Provider không cung cấp loại sự kiện
nào thì APP không tự dựng sự kiện đó. Tin ảnh/tệp cần hợp đồng media riêng trước khi bật.

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

APP dùng Bearer node để tải trực tiếp binary, chỉ khi attachment được tham chiếu bởi
command có lease hiện hành của node. Trả `200` với `Content-Type`, `Content-Length`,
`X-Content-SHA256` (hex), hoặc `404/409` nếu không được cấp/lease hết hạn.
Không trả envelope JSON khi thành công, không redirect sang URL tùy ý.
APP kiểm kích thước/checksum, chỉ tải qua HTTPS, xóa tệp tạm sau xử lý.
P0 chưa hỗ trợ media; upload từ người dùng nằm phía web, không thuộc API agent này.

## 11. Idempotency và trạng thái lệnh

Web lưu khóa idempotency cho claim, tạo/complete snapshot và result tối thiểu 30 ngày,
scope `(node_id, method, path, key)`, kèm hash body và response. Bản ghi đang xử lý trả
`409 REQUEST_IN_PROGRESS` với `retryable=true`. Kiểm replay đã xác thực trước kiểm lease
để APP có thể nhận lại response result đã commit dù lease sau đó hết hạn.
Token đã thu hồi luôn bị chặn, kể cả replay.

Trạng thái web: `queued → leased → running → succeeded|failed|cancelled|unknown`.
`running` khi nhận progress hoặc tín hiệu bắt đầu; việc thiếu tín hiệu không chứng minh
APP chưa thực thi. `unknown` cần đối soát trước retry tác động. Lệnh đã terminal không
được ghi đè bằng kết quả khác; sửa trạng thái qua quy trình đối soát có audit.
Web chỉ queue lệnh sau kiểm quyền, hạn mức, opt-out; trước claim kiểm lại profile/node
và điều kiện hủy. Hạn mức giữ chỗ và quyết toán thuộc web, APP không được tự tăng quota.

## 12. Ví dụ gọi thử khi server đã triển khai

Biến `BASE_URL` và `NODE_TOKEN` do đội web cung cấp; ví dụ cho shell bash, không phải
lệnh chạy trực tiếp trong PowerShell. Không dùng token thật trong tài liệu hoặc Git.

```bash
curl "$BASE_URL/api/v1/agent/config" \
  -H "Authorization: Bearer $NODE_TOKEN" -H 'Accept: application/json'

curl -X POST "$BASE_URL/api/v1/agent/commands/claim" \
  -H "Authorization: Bearer $NODE_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: <uuid>' -d '{"limit":1}'
```

## 13. Tiêu chí nghiệm thu liên thông

1. Token sai/thu hồi bị 401; node không thể đọc hoặc gửi dữ liệu cho profile node khác.
2. Restart APP vẫn giữ execution ledger, outbox và sequence; không phát lại thao tác đã xong.
3. QR mới/hết hạn/thành công hiển thị đúng; logout/ngắt phiên cập nhật đúng chủ profile.
4. Hai worker claim đồng thời không cùng nhận một lease; node cũ bị chặn sau đổi assignment.
5. Gửi tin trả provider_message_id; timeout sau tác động chuyển unknown, không tự gửi lại.
6. Kết quả đã commit nhưng response mất: retry trả kết quả cũ, không thực thi lại.
7. Event trùng/lệch thứ tự, lỗi một phần và snapshot gián đoạn không làm mất dữ liệu.
8. Hủy khi queued và hủy khi đang chạy phản ánh đúng tác động thực tế.
9. Rate limit, node offline, hết lease và rotate token có luồng phục hồi kiểm chứng được.
10. APP báo rõ capabilities, phiên bản; web không giao tác vụ chưa hỗ trợ.

## 14. Thông tin cần hai đội chốt khi bắt đầu

- Domain staging/production, đầu mối cấp node/token và phiên bản APP đầu tiên.
- Khả năng thực tế của APP cho từng command/event; cách phục hồi phiên và đối soát tin gửi.
- Giới hạn provider: độ dài tin, tốc độ, kích thước/media được hỗ trợ.
- P0 dùng polling như tài liệu; nếu cần push/WebSocket sẽ bổ sung giao thức, không thay ngầm.
- Media nhận vào, delta sync, proxy provisioning và chuyển phiên giữa node chưa nằm trong P0.

Các giá trị timeout/quota trong tài liệu là mặc định đề xuất. Trước khi kết nối thật,
hai đội phải thống nhất bản hợp đồng này và thực thi kiểm thử mục 13 trên staging.
