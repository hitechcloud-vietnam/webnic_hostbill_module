# Chính sách hỗ trợ

[English](SUPPORT.md) | [Tiếng Việt](SUPPORT.vi.md)

Trang này mô tả cách yêu cầu trợ giúp cho các module WebNIC trong repo. Nội dung không tạo thỏa thuận mức dịch vụ (SLA) và không cam kết thời gian phản hồi.

## Kênh hỗ trợ

| Yêu cầu | Kênh |
| --- | --- |
| Báo lỗi, sửa tài liệu hoặc đề xuất tính năng | Mở GitHub issue tại [`hitechcloud-vietnam/webnic_hostbill_module`](https://github.com/hitechcloud-vietnam/webnic_hostbill_module/issues). Tìm issue hiện có trước và chỉ tập trung một vấn đề trong mỗi issue. |
| Nghi ngờ lỗ hổng hoặc lộ thông tin xác thực | Làm theo [`SECURITY.vi.md`](SECURITY.vi.md); dùng kênh báo cáo riêng, không dùng issue công khai. |
| Tài khoản WebNIC, quyền API, điều kiện TLD/sản phẩm, giá, trạng thái order hoặc sự cố phía provider | Liên hệ WebNIC qua kênh hỗ trợ được ủy quyền cho tài khoản. Cung cấp mã tham chiếu provider và thời điểm, không cung cấp credential. |
| Hành vi HostBill core, license, cập nhật hoặc hợp đồng API nền tảng | Liên hệ HostBill. Gửi bản tái hiện tối thiểu đã làm sạch dữ liệu và phiên bản HostBill. |
| Sự cố server/network, PHP, database, backup, firewall hoặc reverse proxy | Liên hệ đơn vị vận hành hosting/nền tảng phụ trách môi trường đó. |

Repo không công bố điều khoản hỗ trợ trả phí, giờ hỗ trợ, mục tiêu phản hồi, số điện thoại escalation hoặc thời gian hỗ trợ cho từng bản phát hành. Không giả định issue công khai được theo dõi liên tục.

## Nội dung cần cung cấp

Dùng checklist sau trong báo lỗi:

1. Module: Domain, DNS hoặc SSL; version hiển thị trong HostBill hoặc metadata source.
2. Môi trường: phiên bản HostBill, PHP, hệ điều hành nếu liên quan, OTE hay live, staging hay production bị ảnh hưởng.
3. Luồng: cài đặt app/product liên quan sau khi xóa secret, thao tác đã thực hiện, bước tái hiện chính xác, kết quả mong đợi/thực tế và khả năng tái hiện.
4. Phạm vi và tác động: số service/order bị ảnh hưởng, thời điểm bắt đầu kèm múi giờ, lần thao tác thành công gần nhất, mức ảnh hưởng khách hàng và workaround an toàn nếu có.
5. Bằng chứng: lỗi HostBill/module đã làm sạch, mã/thông báo lỗi WebNIC, route/method (không gửi authorization header) và request/correlation ID nếu WebNIC cung cấp.
6. Thay đổi gần đây: cập nhật module, HostBill/PHP, xoay credential, thay tùy chọn sản phẩm, thay đổi DNS/SSL/domain hoặc mạng.

Ưu tiên domain, contact data, CSR và service ID giả lập trong báo cáo công khai. Ảnh chụp màn hình là tùy chọn; hãy crop và che dữ liệu trước khi tải lên.

## Tuyệt đối không đính kèm

- Password, API secret, bearer/access token, cookie, session ID, private key hoặc database credential.
- Request/response body chưa làm sạch, authorization header, bản xuất `hb_domain_contacts` hoặc dữ liệu liên hệ/định danh khách hàng.
- Nội dung CSR khách hàng, certificate bundle, địa chỉ email DCV, nội dung zone riêng tư hoặc archive log production không giới hạn.
- Database dump, toàn bộ bản xuất cấu hình HostBill hoặc backup.

DNS debug output có thể chứa token response và dữ liệu request/response API. Xem [`SECURITY.vi.md`](SECURITY.vi.md) trước khi thu thập/chia sẻ debug log. Nếu secret đã bị đăng, hãy hạn chế/xóa báo cáo nếu có thể và xoay credential bị lộ ngay lập tức; xóa bài đăng không đảm bảo loại bỏ các bản sao hoặc thông báo đã gửi.

## Phân loại và chẩn đoán

Bắt đầu bằng các kiểm tra ít rủi ro nhất:

1. Xác nhận app HostBill dự kiến được kết nối với product/service và cài đặt OTE/live chính xác.
2. Kiểm tra kết nối; xác minh quyền tài khoản WebNIC và tính khả dụng của sản phẩm/TLD với provider.
3. Tái hiện trong OTE bằng service thử nghiệm không phải của khách hàng và luồng nhỏ nhất thể hiện lỗi.
4. So sánh trạng thái HostBill với trạng thái order/domain/zone chuẩn tại WebNIC trước khi retry thao tác tạo, chuyển, xóa, gia hạn, cấp lại hoặc subscription.
5. Nếu lỗi vẫn còn, mở issue kèm checklist trên và trace lỗi đã làm sạch.

Không lặp lại thao tác thay đổi trạng thái để xử lý timeout chưa rõ kết quả. Trước tiên kiểm tra trạng thái từ xa; đăng ký trùng, reseller transfer, renewal, registry suspension, proxy subscription, DNS deletion, thay đổi certificate hoặc contact/organization dùng chung có thể gây tác động ngoài hệ thống.

## Nhóm nguyên nhân

- **Cấu hình:** sai môi trường, credential, gán module, product option, market, DNS tier hoặc chọn sản phẩm không được hỗ trợ.
- **Provider:** lỗi WebNIC API, quyền tài khoản, quy tắc TLD/sản phẩm SSL, trạng thái phía provider hoặc outage.
- **Nền tảng:** hợp đồng module HostBill, đăng ký User API, PHP/runtime, database hoặc nâng cấp nền tảng.
- **Lỗi tích hợp:** hành vi khác hợp đồng module với cài đặt được hỗ trợ và một trường hợp OTE có thể tái hiện.
- **Bảo mật/quyền riêng tư:** lộ secret, vấn đề phân quyền hoặc xử lý dữ liệu nhạy cảm; báo riêng theo [`SECURITY.vi.md`](SECURITY.vi.md).

Người bảo trì có thể yêu cầu bản tái hiện đã làm sạch hoặc thêm thông tin. Không đăng công khai hiện vật riêng của khách hàng để đẩy nhanh chẩn đoán.

## Đóng báo cáo

Khi workaround hoặc bản sửa đã được xác nhận, ghi lại version module và môi trường đã thử. Xác minh thao tác bị ảnh hưởng trong OTE rồi làm theo quy trình kiểm soát thay đổi của đơn vị vận hành trước khi đưa lên production. Sửa code không tự động sửa trạng thái provider từ xa hoặc đảo ngược giao dịch đã hoàn tất.
