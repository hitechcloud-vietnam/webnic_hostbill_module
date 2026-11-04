# Chính sách bảo mật

[English](SECURITY.md) | [Tiếng Việt](SECURITY.vi.md)

Tài liệu này áp dụng cho các module WebNIC Domain, DNS và SSL trong repo. Đây là hướng dẫn triển khai, không phải chứng nhận bảo mật hay cam kết mọi cài đặt HostBill đều an toàn.

## Báo cáo lỗ hổng

Nếu tính năng báo cáo lỗ hổng riêng tư của GitHub được bật cho repo, hãy sử dụng tính năng đó. Nếu không khả dụng, liên hệ người bảo trì qua tổ chức sở hữu repo để xin kênh báo cáo riêng trước khi chia sẻ chi tiết kỹ thuật. Không tạo issue công khai hoặc công bố công khai lỗ hổng chưa được vá.

Nêu module và version bị ảnh hưởng, phiên bản HostBill/PHP, tác động, điều kiện tiên quyết, cách tái hiện ngắn gọn và biện pháp giảm thiểu đề xuất. Dùng dữ liệu giả lập và môi trường thử nghiệm. Không bao giờ đưa vào báo cáo password live, bearer token, bản ghi khách hàng, private key, CSR chưa làm sạch hoặc response production API.

Hiện không có cam kết công khai về thời gian phản hồi, nhánh được hỗ trợ hoặc lịch công bố lỗ hổng chính thức. Người bảo trì và người báo cáo cần phối hợp riêng để xác minh, khắc phục và công bố. Chính sách này không cam kết sửa lỗi cho môi trường không được hỗ trợ hoặc sản phẩm bên thứ ba.

## Lưu ý về thành phần nhạy cảm bảo mật

Khi bật debug log HostBill, module DNS hiện gửi response từ token endpoint tới `HBDebug::debug`; response thành công chứa `access_token`. Module cũng ghi response API và dữ liệu tham số request. Module Domain ghi một số tham số đăng ký và response API. Các bản ghi đó có thể chứa credential, thông tin liên hệ hoặc dữ liệu nhạy cảm khác.

Coi debug output của HostBill là dữ liệu có bí mật. Khi có thể, tắt verbose/debug logging trong production, giới hạn quyền đọc log, giảm thời gian lưu và rà soát/làm sạch log trước khi chia sẻ. Nếu bearer token hoặc credential có thể đã bị ghi lại hay lộ, hãy coi là bị xâm phạm: xoay WebNIC API credential theo quy trình provider hỗ trợ, thu hồi hoặc để token cache hết hạn khi phù hợp, và rà soát quyền truy cập liên quan. Tài liệu này ghi nhận hành vi hiện tại; không sửa hoặc khắc phục code.

## Danh mục và cách xử lý dữ liệu

| Dữ liệu | Nơi xử lý | Quy tắc xử lý tối thiểu |
| --- | --- | --- |
| WebNIC username/password và bearer token | Cài đặt kết nối HostBill, request module, token cache HostBill; debug output có thể lộ token/API như mô tả trên | Giới hạn quyền admin/cache/log. Không đưa giá trị vào source control, ticket, ảnh chụp hoặc shell history. Xoay credential khi nghi ngờ lộ. |
| Thông tin registrant/contact và định danh domain | Dữ liệu service HostBill và `hb_domain_contacts` | Giới hạn quyền database, backup, export và admin. Chỉ thu thập dữ liệu cần cho đăng ký; tuân thủ yêu cầu lưu/xóa dữ liệu áp dụng. |
| Tên DNS zone, record, đích forwarding và trạng thái subscription | Dữ liệu service HostBill, WebNIC API và có thể cả debug output | Coi nội dung zone và địa chỉ đích là cấu hình khách hàng. Giới hạn quyền API và log. |
| CSR, hướng dẫn/trạng thái DCV, dữ liệu contact/order chứng chỉ | Chi tiết SSL service, WebNIC API và có thể cả debug output | Coi là thông tin khách hàng/đơn hàng mật. Làm sạch khỏi gói hỗ trợ. CSR không chứa private key nhưng có thể nhận diện khách hàng/domain và vẫn cần xử lý cẩn thận. |
| Certificate và private key | Response certificate có thể được API SSL trả về; private key do khách hàng/quy trình HostBill quản lý và không nên gửi hỗ trợ | Bảo vệ quyền truy cập certificate đã cấp. Không thu thập hoặc truyền private key tương ứng trong yêu cầu hỗ trợ. |
| HostBill ID, API URL, timestamp, thông báo lỗi | Log HostBill/API và báo cáo hỗ trợ | Rà soát chi tiết cá nhân hoặc vận hành nhạy cảm; chỉ chia sẻ mức tối thiểu cần thiết. |

Đơn vị vận hành chịu trách nhiệm về nghĩa vụ data controller/processor, rà soát quyền, lưu trữ, xóa dữ liệu, backup và thông báo sự cố áp dụng cho môi trường của họ.

## Chuẩn cơ bản khi triển khai

### Credential và bảo mật máy chủ

- Dùng tài khoản WebNIC reseller/API riêng với đúng quyền cần cho module và sản phẩm đang triển khai. Nếu chính sách provider cho phép, tránh dùng chung credential giữa các môi trường không liên quan.
- Tách riêng credential OTE và live. Xác nhận môi trường được chọn trước mỗi thay đổi production; không để credential thử nghiệm vô tình trở thành credential live.
- Chỉ nhập secret qua cấu hình kết nối được bảo vệ của HostBill. Không commit credential, file `.env`, bản export, request capture hoặc cấu hình production vào repo.
- Giới hạn quyền quản trị HostBill bằng tài khoản riêng, xác thực mạnh theo khả năng triển khai, quyền tối thiểu và rà soát định kỳ.
- Chỉ cấp quyền filesystem, database, cache, backup và log cho người vận hành được ủy quyền. Mã hóa backup và bảo vệ riêng khóa mã hóa.
- Cập nhật HostBill, PHP, hệ điều hành, thư viện TLS và dependencies theo hướng dẫn hỗ trợ của nhà cung cấp. Repo không khai báo đầy đủ ma trận tương thích hoặc hỗ trợ bản vá.
- Khi chính sách mạng cho phép, chỉ mở HTTPS outbound tới host WebNIC và dịch vụ HostBill cần thiết. Không tắt xác minh chứng chỉ TLS để xử lý lỗi kết nối.

### User API và phân quyền

DNS và SSL có manifest User API; Domain không khai báo manifest route riêng. Handler DNS/SSL đánh dấu route cần xác thực, đồng thời kiểm tra client đã xác thực có sở hữu HostBill service mang `@id` hay không. Giữ bật cả xác thực HostBill và kiểm tra quyền client/service. Service ID chỉ là mã định danh, không phải bí mật phân quyền.

Nếu có thể, dùng credential API riêng cho từng tích hợp; chỉ cấp scope/quyền cần thiết, bảo vệ như secret, xoay sau khi thay đổi nhân sự/tích hợp và theo dõi truy cập lỗi/bất thường. Tránh công khai API route qua proxy/cache không xác thực hoặc code trình duyệt làm lộ credential.

### Backend helper và thao tác tác động lớn

Một số method module có thể được code HostBill tin cậy gọi nhưng hiện chưa được công khai thành User API route của khách hàng hoặc thao tác trên admin panel. Thêm một PHP method không tự động bổ sung xác thực, kiểm tra quyền sở hữu service, xác nhận của người vận hành, ngữ cảnh audit hay logic billing. Caller tương lai phải cung cấp các kiểm soát đó tại ranh giới của mình.

- Domain `submitResellerTransfer()` thay đổi quy trình bàn giao phía provider; không nhầm với registrar transfer-in hoặc transfer-away approval. `domainSuspend()` thay đổi trạng thái registry và có thể khiến domain không truy cập được; khác với việc chuyển nameserver sang hold. `toggleProxyService()` thay đổi subscription proxy của provider và có thể ảnh hưởng giá/trạng thái gia hạn.
- DNS `deleteZoneRecordUnified()` xóa mọi giá trị record cho cặp zone/type/name, không chỉ một giá trị. Chỉ dùng sau khi xác nhận đúng zone và owner/name; tốt nhất thử trên zone OTE có thể hủy.
- SSL `searchOrders()` có thể trả về dữ liệu order cấp reseller vượt ngoài một HostBill service. `deleteSslContact()` và `updateOrganizationContact()` có thể thay đổi dữ liệu dùng chung phía provider. Giữ helper trong quy trình operator tin cậy; không công khai rộng nếu chưa có phân quyền rõ ràng, kiểm tra phạm vi và audit.

Với mọi thao tác thay đổi từ xa, xác minh trạng thái provider trước khi retry sau timeout chưa rõ kết quả. Bổ sung bước phê duyệt/kiểm soát thay đổi tương xứng với mức tác động.

### Log và chẩn đoán

- Tránh bật verbose/debug logging production, trừ cuộc điều tra ngắn đã được phê duyệt.
- Giới hạn người đọc và bản xuất log; đặt thời hạn lưu theo yêu cầu vận hành và pháp lý.
- Trước khi gửi log, xóa header `Authorization`, bearer/access token, password, request body, thông tin liên hệ, mã định danh domain owner, nội dung CSR, payload certificate và đích forwarding riêng của khách hàng.
- Không mặc định một dòng log là an toàn chỉ vì nó là thông báo lỗi hoặc do HostBill tạo ra. Áp dụng lưu ý DNS/Domain phía trên.
- Không dán request/response API thô vào issue công khai. Ưu tiên mã lỗi đã làm sạch, tên route, timestamp/múi giờ và bước OTE có thể tái hiện.

## Kiểm soát production và thay đổi

- Diễn tập cài đặt, nâng cấp và khôi phục trong staging với OTE trước khi thay đổi production.
- Sao lưu file module và database HostBill, bao gồm bảng liên hệ domain; xác minh có thể khôi phục.
- Rà soát diff mỗi bản phát hành và kiểm thử luồng liên quan; rollback code không thể đảo ngược thao tác domain, DNS hoặc certificate đã hoàn tất tại WebNIC.
- Khi quy trình vận hành cho phép, yêu cầu người thứ hai rà soát thay đổi có tác động lớn: transfer registrar, xóa/khôi phục domain, đổi nameserver/DNSSEC, hủy subscription và SSL reissue/termination.
- Ghi nhận người phê duyệt và thực hiện thay đổi production mà không đưa secret khách hàng vào hồ sơ thay đổi.

## Checklist ứng phó sự cố

1. **Khoanh vùng:** vô hiệu credential tích hợp/API bị ảnh hưởng hoặc hạn chế truy cập bằng kiểm soát HostBill/provider. Tránh dọn dẹp phá hủy trước khi giữ lại bằng chứng.
2. **Bảo toàn:** ghi khung thời gian sự cố, module/service ID bị ảnh hưởng, tham chiếu log đã làm sạch và hành động đã thực hiện. Lưu bằng chứng ở nơi hạn chế quyền truy cập.
3. **Xoay credential:** thay WebNIC credential bị lộ theo quy trình provider hỗ trợ; rà soát session/token/cache đang hoạt động theo quy trình HostBill/provider.
4. **Đánh giá:** xác định service, khách hàng, record, contact, certificate và log nào có thể bị ảnh hưởng. Tra cứu trực tiếp trạng thái WebNIC trước khi retry thao tác thay đổi trạng thái.
5. **Khôi phục:** thiết lập lại cấu hình quyền tối thiểu, kiểm thử OTE nếu có thể, rồi xác minh production bằng thao tác có kiểm soát.
6. **Thông báo:** tuân thủ yêu cầu thông báo sự cố của tổ chức, hợp đồng và pháp luật. Phối hợp báo cáo riêng lỗ hổng sản phẩm như mô tả trên.
7. **Rút kinh nghiệm:** ghi nguyên nhân gốc và hành động tiếp theo. Không công bố dữ liệu khách hàng hoặc chi tiết khai thác khi lỗi chưa được vá.

## Ranh giới bên thứ ba

HostBill, dịch vụ/API WebNIC, hệ điều hành, PHP và dependencies có chính sách bảo mật và điều khoản riêng. Báo lỗi của các sản phẩm đó cho đơn vị bảo trì tương ứng. Giấy phép MIT của repo không bảo đảm an ninh cho các hệ thống đó hoặc môi trường triển khai dựa trên chúng.
