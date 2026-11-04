# Cài đặt và cấu hình dùng chung

[English](INSTALLATION.md) | [Tiếng Việt](INSTALLATION.vi.md)

## Trước khi cài đặt

1. Xác nhận HostBill đích hỗ trợ đúng loại module: Domain cho `webnic_domain`, Hosting/DNS cho `webnic_dns`, và Hosting/SSL cho `webnic_ssl`.
2. Kiểm tra khả năng tương thích với phiên bản HostBill và PHP thực tế trong môi trường staging. Repo này chưa công bố phiên bản HostBill tối thiểu hoặc ma trận tương thích PHP đầy đủ.
3. Chuẩn bị tài khoản reseller/API WebNIC và quyền truy cập môi trường OTE. Xác nhận quyền API và sản phẩm khả dụng với WebNIC.
4. Sao lưu database HostBill và các thư mục module hiện có.

Đối với ZIP module công khai, tải asset phù hợp từ [GitHub Release](RELEASING.md#download-and-install) và giải nén tại thư mục gốc của HostBill. Archive đã có tiền tố `includes/modules/...`; không giải nén bên trong `includes/modules/` để tránh tạo đường dẫn lặp.

## Sao chép file module

Sao chép thư mục module cần dùng, giữ nguyên cấu trúc bên trong, vào đúng thư mục module của HostBill:

| Thư mục trong repo | Đích trong HostBill |
| --- | --- |
| `webnic_domain/` | `includes/modules/Domain/webnic_domain/` |
| `webnic_dns/` | `includes/modules/Hosting/webnic_dns/` |
| `webnic_ssl/` | `includes/modules/Hosting/webnic_ssl/` |

Chỉ cài module cần sử dụng. Manifest API của DNS và SSL khai báo đường dẫn module Hosting. Đối chiếu đường dẫn với phiên bản HostBill đích và tài liệu cài module của HostBill.

## Đăng ký và cấu hình

1. Mở phần quản lý module/app của HostBill và nạp module đã cài. Thêm username và password WebNIC reseller/API vào cài đặt kết nối.
2. Bật **OTE Environment** để kiểm thử ban đầu. Module dùng `oteapi.webnic.cc` trong OTE và `api.webnic.cc` cho request live.
3. Kiểm tra kết nối và chạy các luồng đại diện với domain, chứng chỉ và DNS zone thử nghiệm.
4. Cấu hình tùy chọn cấp sản phẩm theo hướng dẫn của module tương ứng, sau đó kết nối một sản phẩm HostBill thử nghiệm với module.
5. Chỉ bật manifest User API của DNS/SSL trong hệ thống User API của HostBill nếu cần các route đó. Giữ bật xác thực và kiểm tra quyền client/service.
6. Sau khi kiểm thử OTE thành công, cấu hình thông tin xác thực live có chủ đích và xác minh một thao tác live không phá hủy trước khi nhận đơn hàng.

Xem [Hướng dẫn vận hành production](OPERATIONS.vi.md) để biết checklist trước triển khai và cách retry an toàn.

## Bảng liên hệ Domain

Module domain có file [`webnic_domain/sql.sql`](../webnic_domain/sql.sql), tạo bảng cục bộ `hb_domain_contacts` bằng `CREATE TABLE IF NOT EXISTS`. Xem lại SQL và sao lưu database trước khi áp dụng vào database HostBill nếu bảng chưa tồn tại. Bảng lưu dữ liệu liên hệ và định danh domain; giới hạn quyền truy cập database và bản sao lưu. Không xóa hoặc thay thế bảng hiện hữu khi nâng cấp.

## Nâng cấp và khôi phục

- Sao lưu file module và database trước khi thay thế file.
- Xem thay đổi của bản phát hành và chạy lại các luồng OTE liên quan đến module bị ảnh hưởng.
- Giữ nguyên cấu hình HostBill và dữ liệu liên hệ domain hiện có.
- Khi rollback, khôi phục file module trước đó và database backup theo quy trình khôi phục thông thường của hệ thống. Không giả định rollback code sẽ đảo ngược đơn hàng hoặc thay đổi cấu hình đã gửi tới WebNIC.

## Gỡ cài đặt

Tắt sản phẩm và API route, sau đó làm theo quy trình gỡ module của HostBill. Xóa file module không nhất thiết hủy dịch vụ hoặc đơn hàng từ xa tại WebNIC. Bảng liên hệ domain có thể chứa dữ liệu mà tích hợp khác đang dùng; không xóa bảng nếu chưa xác nhận quyền sở hữu, phụ thuộc, nghĩa vụ lưu trữ và bản sao lưu đã kiểm chứng.

## Hỗ trợ và bảo mật

Dùng [SUPPORT.vi.md](../SUPPORT.vi.md) để chọn kênh hỗ trợ và chuẩn bị báo cáo đã làm sạch dữ liệu. Nếu nghi ngờ lộ thông tin xác thực hoặc có lỗ hổng, làm theo [SECURITY.vi.md](../SECURITY.vi.md), không mở issue công khai. Giới hạn quyền truy cập log, bản sao lưu database và bản xuất cấu hình; chúng có thể chứa dữ liệu khách hàng hoặc bí mật.
