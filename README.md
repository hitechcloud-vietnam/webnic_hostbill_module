# WebNIC Modules for HostBill

[Tiếng Việt](README.md) | [English](README.en.md)

Bộ tích hợp HostBill với dịch vụ WebNIC, được tổ chức thành ba module độc lập:

| Module | Loại HostBill | Version metadata | Phạm vi |
| --- | --- | --- | --- |
| [`webnic_domain`](webnic_domain/README.md) ([VI](webnic_domain/README.vi.md)) | Domain registrar | `1.2026-11-04.1` | Đăng ký và quản lý tên miền qua WebNIC REST v2. |
| [`webnic_dns`](webnic_dns/README.md) ([VI](webnic_dns/README.vi.md)) | Hosting / DNS | `1.1.2` | DNS cơ bản và premium, DNSSEC, forwarding, subscription và API cho khách hàng. |
| [`webnic_ssl`](webnic_ssl/README.md) ([VI](webnic_ssl/README.vi.md)) | Hosting / SSL | `1.1.2` | Đặt và quản lý chứng chỉ, xác minh DCV, CSR và tải chứng chỉ. |

Các module được triển khai riêng; việc cài một module không tự động cài hai module còn lại. Xem [hướng dẫn cài đặt](docs/INSTALLATION.md) trước khi triển khai.

## Tài liệu

- [CI/CD, đóng gói ZIP và phát hành module](docs/RELEASING.vi.md) | [English](docs/RELEASING.md)
- [Cài đặt và cấu hình chung](docs/INSTALLATION.vi.md) | [English](docs/INSTALLATION.md)
- [Vận hành production và xử lý sự cố](docs/OPERATIONS.vi.md) | [English](docs/OPERATIONS.md)
- [Chính sách hỗ trợ và mẫu ticket](SUPPORT.vi.md) | [English](SUPPORT.md)
- [Thương hiệu và ghi nhận nguồn gốc](BRAND.vi.md) | [English](BRAND.md)
- [Báo cáo lỗ hổng bảo mật](SECURITY.vi.md) | [English](SECURITY.md)
- [Đóng góp và kiểm tra thay đổi](CONTRIBUTING.vi.md) | [English](CONTRIBUTING.md)
- [Giấy phép MIT](LICENSE)

## Yêu cầu và lưu ý

- Cần có HostBill tương thích với loại module tương ứng và tài khoản WebNIC reseller/API được cấp quyền.
- Phiên bản HostBill tối thiểu, phiên bản PHP tổng thể và ma trận tương thích chưa được khai báo trong repository; hãy xác minh trên môi trường staging trước khi triển khai production.
- Module dùng WebNIC OTE cho kiểm thử và API WebNIC live cho hoạt động thật. Bắt đầu bằng OTE.
- Các API route của DNS và SSL cần được bật trong cơ chế User API của HostBill. Domain hiện không khai báo API route riêng.
- HostBill, WebNIC API/dịch vụ và thương hiệu bên thứ ba không thuộc giấy phép MIT của mã trong repository này.

## Hỗ trợ

Khi báo lỗi, làm theo [chính sách hỗ trợ](SUPPORT.md). Tuyệt đối không gửi mật khẩu, bearer token, private key, CSR có dữ liệu nhận diện, hoặc dữ liệu cá nhân của khách hàng.
