# Bộ nhận diện zaloMind

Bộ nhận diện được tạo theo yêu cầu của chủ dự án, gồm logo hội thoại với chữ m
kết nối và chấm xanh bạc hà. Không sử dụng tài sản thương hiệu Zalo/VNG.

Cấu trúc:

- logo/zalomind-logo.svg, zalomind-logo-dark.svg, zalomind-logo-stacked.svg, zalomind-mark.svg
- favicon/favicon.svg, favicon.ico, apple-touch-icon.png, site.webmanifest, icon-192.png, icon-512.png
- social/og-image.png
- illustrations/login-hero.svg và các hình empty/error
- brand-tokens.css

Logo gồm các biến thể nền sáng, tối, trắng, xếp dọc và biểu tượng. Chữ SVG
đã chuyển thành path từ Be Vietnam Pro (font OFL), không cần tải font ngoài.
Các bản PNG được xuất kèm. favicon.ico chứa các kích thước 16/32/48px.
Ảnh OG có kích thước 1200×630px. preview.png là bản xem nhanh bộ nhận diện.

Màu: xanh #1A56DB, chàm #4338CA, bạc hà #10B981. brand-tokens.css cung cấp
biến màu sáng/tối và map sang Bootstrap. Giữ vùng trống tối thiểu 1/4 chiều cao
biểu tượng, không kéo méo, đổi màu hoặc thêm bóng đổ vào logo.

Tái tạo ảnh bằng `npm.cmd run brand` tại thư mục gốc. Script nguồn:
scripts/generate-brand.mjs. CSS tokens được quản lý riêng, không bị script ghi đè.
Font gốc và giấy phép nằm trong node_modules/@fontsource/be-vietnam-pro.
