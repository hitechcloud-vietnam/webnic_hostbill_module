# Sổ tay vận hành Production

[English](OPERATIONS.md) | [Tiếng Việt](OPERATIONS.vi.md)

Sổ tay này bao quát vận hành thường ngày cho các module WebNIC Domain, DNS và SSL được cài trên HostBill. Trạng thái tại WebNIC là nguồn chuẩn đối với đơn hàng và thao tác domain/DNS/chứng chỉ từ xa đã hoàn tất; trạng thái HostBill có thể chậm cập nhật hoặc chưa đầy đủ.

## Sẵn sàng vận hành

Trước khi bật nhận đơn hàng khách, xác nhận tất cả mục sau:

- [ ] Đúng loại module và đường dẫn cài đặt; module hiển thị và đã kết nối trong HostBill.
- [ ] Kiểm tra kết nối WebNIC OTE thành công với tài khoản thử nghiệm dự kiến.
- [ ] Chủ dịch vụ đã rà soát cài đặt sản phẩm; market, DNS tier, product key, phương thức DCV được phép và các giới hạn đã được chọn có chủ đích.
- [ ] Các luồng OTE đại diện thành công với từng dịch vụ và sản phẩm được bật.
- [ ] Chỉ bật User API route khi cần và đã xác minh phân quyền client/service bằng cả tài khoản được phép lẫn tài khoản bị từ chối.
- [ ] Đã kiểm tra độc lập thông tin live và lựa chọn live/OTE trước khi bật production.
- [ ] Có bản sao lưu database và module, quyền khôi phục được kiểm soát và quy trình phục hồi đã diễn tập.
- [ ] Quyền truy cập admin, API, database, cache và log chỉ cấp cho các vai trò vận hành được chỉ định.
- [ ] Đã rà soát debug log production và thời hạn lưu log, đặc biệt cách DNS ghi log token/API trong [`SECURITY.vi.md`](../SECURITY.vi.md).
- [ ] Người vận hành biết cách liên hệ WebNIC, HostBill, nhà cung cấp hosting và người bảo trì repo.

## Kiểm tra môi trường và kết nối

1. Xác nhận username và secret của app HostBill là thông tin xác thực API WebNIC dự kiến. Không ghi giá trị bí mật vào nhật ký vận hành.
2. Xác nhận **OTE Environment** được bật ở staging và chỉ tắt khi có chủ đích thực hiện thao tác live.
3. Xác nhận HTTPS outbound và DNS resolution tới host API được chọn: `oteapi.webnic.cc` hoặc `api.webnic.cc`.
4. Dùng chức năng kiểm tra kết nối của module trong HostBill. Ghi thời gian, môi trường, kết quả và mã lỗi provider đã loại dữ liệu nhạy cảm.
5. Nếu xác thực thất bại, kiểm tra trạng thái tài khoản và quyền với WebNIC trước khi liên tục reset thông tin xác thực hoặc thay đổi trạng thái module.

## Smoke test theo module

### Domain

- Tra cứu domain/TLD thử nghiệm và xác nhận market cùng cách tính giá.
- Thử đăng ký hoặc chuyển domain với thông tin registrant giả lập hợp lệ và điều kiện thử nghiệm được provider chấp thuận.
- Kiểm tra đồng bộ nameserver/contact và làm mới trạng thái.
- Chỉ thử DNSSEC, lock/hold, gia hạn, restore hoặc tính năng có thể thay đổi trạng thái khi tài khoản OTE và domain thử nghiệm hỗ trợ.
- Kiểm tra riêng việc đọc trạng thái transfer-in từ registrar và gửi yêu cầu reseller-transfer. Chỉ gửi một giao dịch bàn giao OTE đã được duyệt; reseller handover không đồng nghĩa với transfer-away approval.
- Phân biệt đình chỉ tại registry (`domainSuspend()`) với chuyển nameserver sang hold (`Suspended()`); xác minh trạng thái provider trước khi khôi phục dịch vụ.
- Trước khi dùng `toggleProxyService()`, xác nhận TLD đủ điều kiện, subscription/giá hiện tại của provider và hành động dự kiến là subscribe hay unsubscribe. Không nhầm với nút WHOIS privacy riêng.
- Kiểm tra khả dụng glue host với đúng extension trước khi đăng ký nameserver host.
- Xác nhận bảng `hb_domain_contacts` đã tồn tại chưa trước khi áp dụng SQL cài đặt; bảo toàn dữ liệu bảng hiện có.

### DNS

- Tạo hoặc import zone thử nghiệm và xác nhận zone thuộc basic hay premium như dự kiến.
- Thêm, đọc, cập nhật, xóa record thử nghiệm; đối chiếu giá trị và TTL với WebNIC.
- `deleteZoneRecordUnified()` là helper nội bộ xóa mọi giá trị khớp cặp zone/type/name. Đây không phải luồng xóa thông thường của client/admin; chỉ thử trên zone có thể hủy và xác minh chính xác điều kiện khớp.
- Chỉ kiểm tra DNSSEC và forwarding trên zone thử nghiệm. Xác nhận premium subscription và trạng thái tự gia hạn trước khi thay đổi.
- Kiểm tra đăng ký User API route và xác nhận client sở hữu dịch vụ truy cập được, trong khi client khác bị từ chối.

### SSL

- Chọn sản phẩm thử nghiệm và phương thức DCV được hỗ trợ; xác minh CSR, contact, quy tắc SAN và yêu cầu tổ chức.
- Hoàn tất quy trình xác minh DNS, HTTP/file hoặc email thử nghiệm phù hợp.
- Xác minh đồng bộ trạng thái; chỉ tải chứng chỉ sau khi phát hành.
- Giữ các backend helper như tìm kiếm đơn hàng cấp reseller và thay đổi contact/tổ chức provider trong quy trình vận hành tin cậy; xác minh phạm vi và tác động dữ liệu dùng chung trước khi dùng production.
- Xem gia hạn, cấp lại và chấm dứt là các thao tác vòng đời riêng; xác minh trạng thái đơn hàng WebNIC trước khi lặp lại sau timeout.

## Vận hành định kỳ

- Rà soát thao tác module thất bại, lỗi HostBill API và thông báo provider với quyền log bị giới hạn.
- Theo dõi thay đổi HostBill/PHP, file module, cài đặt sản phẩm, thông tin xác thực và chính sách mạng để đối chiếu khi có sự cố.
- Định kỳ xác minh bản sao lưu và quyền khôi phục. Bảo vệ bản xuất database và log ở cùng mức nhạy cảm với dữ liệu khách hàng production.
- Rà soát quyền truy cập dịch vụ và API credential khi thay đổi nhân sự, tích hợp hoặc trách nhiệm.
- Không bật debug log diện rộng như giải pháp giám sát thường trực. Chỉ mở một khoảng chẩn đoán ngắn đã được phê duyệt; sau đó xóa hoặc làm sạch file thu thập.

Repo không định nghĩa metric tích hợp sẵn, ngưỡng cảnh báo, đường dẫn log cho mọi cài đặt HostBill, thời hạn lưu trữ hay tích hợp giám sát. Cấu hình chúng bằng các tiện ích HostBill và hạ tầng có trong môi trường triển khai cụ thể.

## Xử lý sự cố an toàn

Với timeout, HTTP 5xx hoặc mất phản hồi sau thao tác thay đổi trạng thái:

1. Không lập tức lặp lại thao tác.
2. Ghi lại thời gian, module, thao tác, mã tham chiếu service/order, môi trường và lỗi đã làm sạch dữ liệu.
3. Tra cứu HostBill và WebNIC để xác định thao tác từ xa đã hoàn tất chưa.
4. Nếu trạng thái provider chưa rõ, liên hệ WebNIC trước khi retry hoặc sửa dữ liệu cục bộ.
5. Đối soát trạng thái HostBill và provider theo quy trình module đã tài liệu hóa; lưu bằng chứng và thông báo cho người vận hành/khách hàng bị ảnh hưởng khi cần.

Điều này đặc biệt quan trọng với đăng ký/chuyển/gia hạn/khôi phục/xóa domain, đình chỉ registry, thay đổi subscription proxy, xóa DNS record theo type/name và thay đổi DNS subscription, cũng như thao tác SSL order/reissue/renewal/termination hoặc cập nhật contact/tổ chức.

## Quy trình nâng cấp và rollback

1. Xem release notes/diff và xác định module, API manifest, yêu cầu database và cài đặt sản phẩm bị thay đổi.
2. Sao lưu file và database; ghi lại version module hiện tại và các cài đặt liên quan không chứa secret.
3. Cài và kiểm thử module đã thay đổi ở staging bằng OTE.
4. Lên lịch thay đổi production, thông báo người vận hành và triển khai trong phạm vi nhỏ nhất có thể.
5. Sau triển khai, xác minh kết nối và một thao tác có kiểm soát, không phá hủy.
6. Nếu cần rollback, khôi phục file trước đó và làm theo kế hoạch phục hồi database. Không phục hồi database cũ đè lên hoạt động khách hàng mới nếu chưa có quyết định phục hồi rõ ràng.
7. Kiểm tra trạng thái WebNIC sau rollback. Thao tác từ xa không được hoàn tác chỉ bằng cách khôi phục file module.

## Bàn giao sự cố

Với sự cố production, dùng [`SUPPORT.vi.md`](../SUPPORT.vi.md) để ghi chi tiết issue và [`SECURITY.vi.md`](../SECURITY.vi.md) khi nghi ngờ lộ secret hoặc có lỗ hổng. Không đính kèm debug log thô: DNS có thể ghi response chứa bearer token và dữ liệu request/response. Chỉ gửi mã tham chiếu đã làm sạch và lưu bằng chứng gốc trong kho sự cố có kiểm soát truy cập.
