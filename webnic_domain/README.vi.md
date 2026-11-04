# WebNIC Domain (`webnic_domain`)

[English](README.md) | [Tiếng Việt](README.vi.md)

Module HostBill **đăng ký tên miền** cho [WebNIC](https://www.webnic.cc/), được xây dựng mới theo **WebNIC REST v2 API**. Đây là một phần của HiTechCloud WebNIC Suite (`webnic_domain` / `webnic_ssl` / `webnic_dns`).

Module tự chứa trong `Domain/webnic_domain/`, không tham chiếu hoặc phụ thuộc runtime vào module WebNIC nào khác.

## Tổng quan

`webnic_domain` đăng ký, chuyển, gia hạn, khôi phục và quản lý domain qua WebNIC; triển khai hợp đồng HostBill `DomainModule` cùng `Observer`, `DomainLookupInterface`, `DomainPremiumInterface` và `DomainPriceImport`. Module bao phủ vòng đời WebNIC Domain: kiểm tra khả dụng và premium, nhập giá, contact và registry contact, nameserver và glue host, mã EPP/auth, trạng thái/khóa registry, WHOIS privacy, DNSSEC, tài khoản registrant, phê duyệt transfer-away và thao tác hàng loạt.

### Dịch vụ WebNIC được hỗ trợ

WebNIC **Domain** (`domain/v2/*`) cùng endpoint reseller (`reseller/v2/token`, `reseller/v2/balance`).

- LIVE base: `https://api.webnic.cc/`
- TEST/OTE base: `https://oteapi.webnic.cc/`
- Xác thực: `POST reseller/v2/api-user/token {username,password}` trả Bearer token (cache trong `HBCache`, tự làm mới và retry một lần khi gặp 401/403).
- Envelope thành công: cấp cao nhất có `code == 1000`, payload trong `data`; lỗi nằm trong `message` / `error.message`.

## Kết nối và trường cấu hình

Hiển thị tại **Settings → Apps** (kết nối registrar):

| Trường | Loại | Mục đích |
|-------|------|---------|
| `Username` | input | Username API WebNIC reseller |
| `Password` | password | Mật khẩu API WebNIC reseller |
| `OTE Environment` | check | Chọn để dùng host TEST/OTE; bỏ chọn để dùng LIVE (`$live = OTE != 1`) |
| `Market` | select | **Vietnam (VN)** hoặc **Global** — xem bên dưới |
| `registrant_user_id` | input | ID tài khoản registrant WebNIC dùng khi đăng ký/chuyển domain |

### Chọn market (VN / Global)

`Market` điều khiển toàn bộ hành vi riêng cho Việt Nam. Mặc định là VN nếu chưa lưu.

- **Vietnam (VN):** áp dụng quy tắc tên registrant Việt Nam, lọc từ khóa nhạy cảm và đối chiếu EKYC; bật xử lý proxy/local presence cho TLD đủ điều kiện; đổi giá TLD nhập về **VND**; bỏ qua TLD `.vn` khi nhập giá (do registrar VNNIC nội địa xử lý).
- **Global:** luồng quốc tế tiêu chuẩn, không áp dụng kiểm tra tên/EKYC cho registrant, giữ nguyên tiền tệ nguồn và nhập `.vn` như TLD khác.

## Bản đồ endpoint → method

Mọi endpoint WebNIC module gọi (đường dẫn tính từ base của môi trường):

### Xác thực / tài khoản
| Endpoint | Method |
|----------|--------|
| `POST reseller/v2/api-user/token` | `access_token()` |
| `GET reseller/v2/balance` | `testConnection()`, balance widget |

### Khả dụng / giá
| Endpoint | Method |
|----------|--------|
| `GET domain/v2/query` | `checkAvailability()`, `lookupDomain()` |
| `GET domain/v2/exts` | `getDomainPrices()` |
| `POST domain/v2/exts/pricing` | `getDomainPrices()` |
| `GET domain/v2/smart-query` | `smartQuery()` |
| `GET domain/v2/ext-rules` | `getExtensionRules()` |

### Đăng ký / chuyển / gia hạn / xóa / khôi phục
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/register` | `Register()` / `ManualRegister()` |
| `POST domain/v2/transfer-in` | `Transfer()` |
| `POST domain/v2/renew` | `Renew()` |
| `DELETE domain/v2/delete` | `Delete()` |
| `POST domain/v2/restore` | `RestoreDomain()` / `Restore()` |
| `POST domain/v2/reseller-transfer` | `submitResellerTransfer()` |

### Thông tin / đồng bộ / trạng thái / khóa / đình chỉ
| Endpoint | Method |
|----------|--------|
| `GET domain/v2/info` | `_domain_details()`, `synchInfo()`, `getDomainInfo()`, `TransferAction()` |
| `PUT domain/v2/status` | `Lock()`, `Unlock()`, `updateStatus()` |
| `POST domain/v2/suspend` | `domainSuspend()` |
| `GET domain/v2/suspendStatus` | `getDomainSuspendStatus()` |
| `GET domain/v2/query-transfer-type` | `queryTransferType()` |
| `GET domain/v2/transfer-in/status` (+ `/{id}`) | `getTransferInStatus()`, `getTransferInStatusById()` |

### EPP / xác minh / certificate
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/auth-info/send` | `getEppCode()` |
| `POST domain/v2/auth-info/reset` | `ChangeEpp()` |
| `POST domain/v2/resend-verification-email` | `SendVerify()` |
| `GET domain/v2/download/certificate` | `downloadCertificateApi()` |

### Nameserver / glue host
| Endpoint | Method |
|----------|--------|
| `PUT domain/v2/dns` | `updateNameServers()`, `Suspended()`, `UnSuspended()` |
| `POST domain/v2/host/list` | `getRegisterNameServers()` |
| `GET domain/v2/host/info` | `getRegisterNameServers()`, `modifyNameServer()`, `deleteNameServer()` |
| `POST domain/v2/host/create/extension` | `registerNameServer()` |
| `POST domain/v2/host/modify` | `modifyNameServer()`, `deleteNameServer()` |
| `DELETE domain/v2/host/extension` | `deleteNameServer()` |
| `POST domain/v2/host/domainlist/search` | `getHostLinkedDomains()` |
| `POST domain/v2/host/check` | `checkHost()` |

### WHOIS privacy / proxy / WHOIS
| Endpoint | Method |
|----------|--------|
| `PUT domain/v2/whois-privacy/toggle` | `updateIDProtection()` |
| `PUT domain/v2/proxy` | `toggleProxyService()` |
| `GET domain/v2/whois` | `getUniversalWhois()` |

### Contact
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/contact/create` | `createContact()` |
| `POST domain/v2/contact/create/{tld}/{type}` | `createContact()` |
| `GET domain/v2/contact/query` | `getContact()` |
| `POST domain/v2/contact/modify` | `SyncContact()` |
| `POST domain/v2/contact/modify-at-registry` | `SyncContact()` |
| `DELETE domain/v2/contact` | `Delete()` |
| `POST domain/v2/contact/replace` | `bulkReplaceContact()` |

### DNSSEC (registry)
| Endpoint | Method |
|----------|--------|
| `GET domain/v2/dnssec/support` | `checkDnssecSupported()` |
| `GET domain/v2/dnssec` | `getDnssecInfo()` |
| `POST domain/v2/dnssec` | `updateDnssec()` |
| `DELETE domain/v2/dnssec` | `deleteDnssec()` |

### Tài khoản registrant
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/registrant/create` | `createRegistrantAccount()` |
| `GET domain/v2/registrant/list` | `listRegistrantAccounts()` |
| `PUT domain/v2/registrant-account` | `reassignRegistrantAccount()` |

### Transfer-away (registrar + reseller)
| Endpoint | Method |
|----------|--------|
| `GET domain/v2/transfer-away/status` (+ `/{id}`) | `getRegistrarTransferAwayStatus()` / `…ById()` |
| `PUT domain/v2/transfer-away/status/{id}` | `updateRegistrarTransferAwayStatus()` |
| `GET domain/v2/reseller-transfer/status` (+ `/{id}`) | `getResellerTransferAwayStatus()` / `…ById()` |
| `PUT domain/v2/reseller-transfer/status/{id}` | `updateResellerTransferAwayStatus()` |

### Thao tác hàng loạt
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/bulk/register` | `bulkRegister()` |
| `POST domain/v2/bulk/renew` | `bulkRenew()` |
| `GET domain/v2/bulk/report` | `getBulkReportOverview()` |
| `POST domain/v2/bulk/report/{taskId}/details/list` | `getBulkReportDetails()` |

## Backend helper mới

Các method sau kết nối thêm endpoint WebNIC cho code HostBill đáng tin cậy hoặc công cụ operator. Đây là method module, không phải route User API mới cho khách hàng hay action admin panel tự động:

- `submitResellerTransfer()` gửi yêu cầu bàn giao domain **giữa hai reseller** WebNIC. Đây là luồng riêng, khác registrar transfer-in từ bên ngoài và thao tác phê duyệt transfer-away.
- `getTransferInStatus()` / `getTransferInStatusById()` đọc trạng thái registrar transfer-in theo domain hoặc ID record provider.
- `domainSuspend()` áp dụng đình chỉ cấp registry (`clientHold`), còn `Suspended()` đổi nameserver sang hold nameserver nội bộ. Hai biện pháp khác nhau và ảnh hưởng khách hàng khác nhau.
- `toggleProxyService()` đăng ký/hủy dịch vụ proxy WebNIC; không phải WHOIS privacy. Xác nhận TLD đủ điều kiện, giá provider và trạng thái subscription mong muốn trước khi thay đổi.
- `checkHost()` kiểm tra khả dụng nameserver/glue host cho extension.

Các binding này gọi trực tiếp WebNIC; tự chúng không bổ sung xác thực, phê duyệt hoặc quy trình billing cho HostBill. Giới hạn caller sử dụng chúng và xác minh trạng thái provider trước khi retry mutation bị timeout.

## Giao diện quản trị

Admin controller (`admin/class.webnic_domain_controller.php`) hiển thị:

**Panel trong trang domain** (trang sửa domain):
- Chi tiết/thao tác domain (SyncContact, ChangeEpp, Lock, Unlock, Hold/RemoveHold, SendVerify, Certificate, Restore)
- Cờ trạng thái domain
- Contact domain
- Trạng thái transfer (kèm transfer-type)
- DNSSEC (xem/cập nhật/xóa DS record)
- Tra cứu Universal WHOIS
- Transfer-away (registrar + reseller, approve/reject)
- Tải certificate PDF (đa ngôn ngữ)

**Công cụ admin độc lập** (Extras / admin main menu):
- Tài khoản registrant — tạo / tìm kiếm / gán lại
- Domain liên kết host — liệt kê domain gắn với nameserver
- Thao tác hàng loạt — đăng ký/gia hạn, xem batch report, thay contact hàng loạt

**Dashboard widget** (`widgets/webnic_domain_balance/`): hiển thị số dư tài khoản reseller WebNIC (chỉ gọi sau thao tác submit có kiểm tra token; không gọi khi render thông thường).

## Cài đặt / kích hoạt

1. Chép thư mục `Domain/webnic_domain/` vào `includes/modules/Domain/`.
2. Đảm bảo ownership là `www:www` (`chown -R www:www Domain/webnic_domain`).
3. Module có `sql.sql` tạo bảng dùng chung `hb_domain_contacts` (lưu contact cục bộ); chỉ áp dụng nếu bảng chưa tồn tại.
4. Trong HostBill: **Settings → Modules → Domain Registrars**, kích hoạt **WebNIC Domain**.
5. **Settings → Apps** → thêm WebNIC app với Username / Password / Market và tùy chọn OTE Environment, sau đó **Test Connection** (thành công sẽ báo số dư tài khoản).
6. Nhập giá TLD bằng chức năng price-import của registrar và gắn TLD cần dùng.

## Danh sách tính năng

- Tra cứu khả dụng và domain premium; nhập giá TLD (VND cho VN, tiền tệ nguồn cho Global).
- Đăng ký (standard/premium, xử lý pending order), đăng ký thủ công (bỏ qua kiểm tra tên registrant), transfer-in, gia hạn (có kiểm tra idempotency theo ngày hết hạn), xóa và khôi phục (redemption).
- Hỗ trợ IDN (language code qua extended attribute).
- Contact: tạo contact và registry contact, truy vấn, sửa và sửa tại registry, xóa, thay hàng loạt; chuẩn hóa dấu tiếng Việt trong payload outbound.
- Nameserver và glue host: đọc/cập nhật NS, đăng ký/sửa/xóa glue host, kiểm tra khả dụng host và domain liên kết.
- Gửi/reset EPP/auth code; khóa/mở khóa registry và cờ trạng thái; soft suspend (hold nameserver) và registry suspend; bật/tắt WHOIS privacy và proxy privacy.
- Registry DNSSEC (kiểm tra hỗ trợ/đọc/cập nhật/xóa DS).
- Tài khoản registrant (tạo/tìm/gán lại); phê duyệt transfer-away (registrar + reseller); bulk register/renew/report và thay contact hàng loạt.
- Extended attribute theo TLD (`.us` nexus, `.cn` loại tổ chức/cá nhân, domain gộp HK, ngày sinh HK/MY, IDN language code, v.v.).
- Xác thực registrant Việt Nam: lọc từ khóa nhạy cảm (`libs/keywords.json`), heuristic tên registrant và đối chiếu EKYC.

## Giới hạn đã biết

- **Tải tài liệu** cho TLD cần xác minh danh tính (`POST domain/v2/upload-document/{id}`) chưa được kết nối: endpoint cần multipart request, không phù hợp với JSON client hiện tại của module. Tải tài liệu xác minh qua reseller console WebNIC.
- Đối chiếu EKYC phụ thuộc module tùy chọn `Other\domain_documents`; nếu module này không có, bước kiểm tra EKYC sẽ bị bỏ qua.
- WHOIS/RDDS đang được registry loại bỏ; `getUniversalWhois()` chỉ hoạt động theo khả năng còn lại.
- Nhập giá VN dùng hệ số chuyển đổi VND cố định 26.000 và làm tròn tới 1.000 gần nhất; cần rà soát trước khi dùng cho billing.

## Nhật ký thay đổi

- **1.2026-11-04.1**
  - Bổ sung bản dịch tiếng Việt đầy đủ bên cạnh hướng dẫn module tiếng Anh.

- **1.2026-11-03.1**
  - Đặt timeout kết nối 10 giây và timeout request 30 giây cho API WebNIC để endpoint không phản hồi không thể treo trang admin vô thời hạn.

- **1.2026-11-02.2**
  - Hoàn thiện docs/license: MIT `LICENSE`, author/license headers và README.
  - Bổ sung endpoint theo WebNIC API docs: `submitResellerTransfer()`, `getTransferInStatus()` / `…ById()`, `domainSuspend()` / `getDomainSuspendStatus()`, `toggleProxyService()`, `checkHost()`, `smartQuery()`, `getExtensionRules()`.
  - Thêm dashboard widget số dư tài khoản WebNIC.

## License và tác giả

MIT — xem [`LICENSE`](LICENSE).

© 2026 **HiTechCloud by Pho Tue SoftWare Solutions JSC**.
