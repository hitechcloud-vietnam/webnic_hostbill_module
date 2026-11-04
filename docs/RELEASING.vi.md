# CI, gói cài đặt và phát hành

[English](RELEASING.md) | [Tiếng Việt](RELEASING.vi.md)

## Cấu trúc gói

Mỗi module được build và phát hành độc lập. ZIP chứa module theo đúng đường dẫn tương ứng trong HostBill và không có thư mục archive bọc ngoài:

| Tên ZIP | Đường dẫn đầu tiên bên trong ZIP | Giải nén tại |
| --- | --- | --- |
| `webnic_domain-<version>.zip` | `includes/modules/Domain/webnic_domain/` | Thư mục gốc HostBill |
| `webnic_dns-<version>.zip` | `includes/modules/Hosting/webnic_dns/` | Thư mục gốc HostBill |
| `webnic_ssl-<version>.zip` | `includes/modules/Hosting/webnic_ssl/` | Thư mục gốc HostBill |

Ví dụ, giải nén gói Domain tại thư mục gốc HostBill sẽ đặt file vào `includes/modules/Domain/webnic_domain/`. Giải nén DNS và SSL tại cùng vị trí, chúng sẽ nằm trong các đường dẫn tương ứng dưới `Hosting/`. Archive bao gồm README và giấy phép MIT của module.

## Tích hợp liên tục

`.github/workflows/ci.yml` chạy cho pull request và push lên `main` (cũng có thể chạy thủ công). Workflow:

- kiểm tra cú pháp PHP của cả ba module bằng PHP 8.3;
- parse file JSON/manifest;
- build cả ba ZIP và xác minh các file chỉ nằm trong đúng đường dẫn module HostBill;
- tải ZIP lên artifact `webnic-hostbill-module-zips`, lưu trong 30 ngày.

Workflow chỉ kiểm tra tĩnh và đóng gói; không kết nối HostBill hoặc WebNIC và không phải kiểm thử tích hợp end-to-end.

## Phát hành module

Workflow release lắng nghe tag riêng cho từng module:

```text
webnic_domain-v<đúng version module>
webnic_dns-v<đúng version module>
webnic_ssl-v<đúng version module>
```

Ví dụ theo metadata hiện tại:

```text
webnic_domain-v1.2026-11-04.1
webnic_dns-v1.1.2
webnic_ssl-v1.1.2
```

Trước khi gắn tag, cập nhật `$version` trong class chính của module được chọn, rà soát README/changelog và license, rồi merge thay đổi vào nhánh mặc định. Tạo và push tag khớp chính xác version:

```sh
git tag webnic_dns-v1.1.2
git push origin webnic_dns-v1.1.2
```

Thay ví dụ bằng module và version cần phát hành. Tag phải trỏ tới commit đã được rà soát. Workflow kiểm tra module/version trên tag với source, chạy kiểm tra PHP và JSON, build và xác minh ZIP của module đó, sau đó tạo GitHub Release đính kèm ZIP. Phát hành DNS không tạo asset mới cho Domain hoặc SSL.

Không tái sử dụng hoặc di chuyển tag đã phát hành. Hãy phát hành version mới. Workflow cần `GITHUB_TOKEN` có quyền ghi release; workflow khai báo `contents: write`. Chính sách Actions của repo/tổ chức phải cho phép các GitHub Actions và GitHub CLI được tham chiếu.

## Tải xuống và cài đặt

Tải asset module cần dùng từ GitHub Release, sao lưu module hiện tại và database, sau đó giải nén ZIP tại thư mục gốc HostBill. Xác nhận đường dẫn kết quả khớp bảng trên trước khi bật/nâng cấp module. Làm theo [hướng dẫn cài đặt và nâng cấp](INSTALLATION.vi.md), rồi kiểm thử module qua WebNIC OTE trước khi vận hành production.

## Đóng gói cục bộ

Có thể chạy packager dùng bởi CI từ thư mục gốc repo:

```sh
./scripts/package-module.sh webnic_domain dist
./scripts/package-module.sh webnic_dns dist
./scripts/package-module.sh webnic_ssl dist
```

Script đọc version từ class module và ghi `dist/<module>-<version>.zip`. Tham số thứ ba tùy chọn sẽ yêu cầu đúng version, như workflow release. Kiểm tra archive bằng `unzip -l dist/<module>-<version>.zip` trước khi phân phối thủ công.
