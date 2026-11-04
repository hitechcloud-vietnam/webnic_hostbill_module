# Đóng góp

[English](CONTRIBUTING.md) | [Tiếng Việt](CONTRIBUTING.vi.md)

## Phạm vi

Giữ thay đổi tập trung vào module hoặc tài liệu liên quan. Domain, DNS và SSL là các module HostBill riêng biệt; tránh tạo phụ thuộc runtime giữa chúng trừ khi yêu cầu thay đổi nêu rõ.

## Trước khi gửi

1. Mô tả module bị ảnh hưởng và hành vi thay đổi.
2. Kiểm thử với WebNIC OTE ở nơi luồng API cho phép; không chạy thử nghiệm phá hủy trên dịch vụ khách hàng live.
3. Chạy kiểm tra cú pháp PHP trên file PHP đã đổi bằng `php -l` và xác thực manifest JSON đã đổi bằng `python -m json.tool <file>`. Thay đổi chỉ có tài liệu không cần các kiểm tra PHP.
4. Rà soát diff để tìm credential, dữ liệu khách hàng, artifact được sinh và thay đổi không liên quan.
5. Cập nhật tài liệu module phù hợp khi có thay đổi cấu hình, hành vi, API route hoặc yêu cầu vận hành.

## Pull request và issue

Cung cấp bước tái hiện, kết quả mong đợi/thực tế, version module và log liên quan đã làm sạch dữ liệu. Nêu môi trường OTE/live đã dùng. Không đính kèm credential, bearer token, dữ liệu liên hệ khách hàng hoặc private key chứng chỉ.

Đóng góp cho phần mềm và tài liệu repo chịu sự điều chỉnh của giấy phép MIT trong [`LICENSE`](LICENSE). Điều khoản và nhãn hiệu của nền tảng bên thứ ba vẫn do chủ sở hữu tương ứng quy định.
