# WebNIC SSL (`webnic_ssl`)

[English](README.md) | [Tiếng Việt](README.vi.md)

Module HostBill **cấp chứng chỉ SSL** cho [WebNIC](https://www.webnic.cc/), xây dựng theo **WebNIC REST v2 API** (`ssl/v2/*`). Thuộc HiTechCloud WebNIC Suite (`webnic_domain` / `webnic_ssl` / `webnic_dns`).

Module triển khai hợp đồng HostBill `SSLModule`: khám phá sản phẩm, đặt hàng bằng CSR, xác minh quyền kiểm soát domain (DNS / HTTP file / email approver), cấp lại, gia hạn, đồng bộ trạng thái, xác minh tổ chức (OV/EV) và tải certificate.

## Tổng quan

`webnic_ssl` tạo và quản lý **đơn hàng** chứng chỉ SSL qua WebNIC. Module khám phá sản phẩm/quy tắc đặt hàng, tạo contact quản trị/kỹ thuật và tổ chức khi cần, gửi đơn từ CSR đã lưu cùng lựa chọn DCV, xử lý xác minh quyền kiểm soát domain và đồng bộ trạng thái order/certificate về HostBill.

### Dịch vụ WebNIC được hỗ trợ

WebNIC **SSL** (`ssl/v2/*`) và endpoint cấp token reseller.

- LIVE host: `https://api.webnic.cc`
- TEST/OTE host: `https://oteapi.webnic.cc`
- Xác thực: `POST /reseller/v2/api-user/token` → Bearer token (cache trong `HBCache` theo base URL + user, tự refresh và retry một lần khi 401).
- Envelope thành công: `code == 1000`, payload ở `data`; transport trả trực tiếp object `data` và ném exception với code khác 1000 hoặc HTTP ≥ 400.

## Trường kết nối / cấu hình

### Trường kết nối (server/app)

| Trường | Bắt buộc | Mục đích |
|-------|----------|---------|
| Username (`CONNECTION_FIELD_USERNAME`) | có | **API Username** |
| Password (`CONNECTION_FIELD_PASSWORD`) | có | **API Secret** |
| Checkbox (`CONNECTION_FIELD_CHECKBOX`) | — | **OTE Environment** (đánh dấu → host TEST) |

### Product option (trang cấu hình sản phẩm HostBill)

| Option | Loại | Mục đích |
|--------|------|---------|
| `product_key` | loadable (`listProducts`) | Sản phẩm SSL WebNIC |
| `market` | select | **Vietnam (VN)** / **Global** |
| `DCV` | loadablemultiple (`listDCVMethod`) | Phương thức DCV được phép (Email / DNS / HTTP) |
| `SAN_LIMIT` | input | Số SAN tối đa (còn bị giới hạn thêm theo quy tắc sản phẩm) |
| `special_instruction` | textarea | Nội dung tự do gửi WebNIC khi tạo order |
| `san` | form | Bật biểu mẫu nhập SAN |

### Chọn market (VN / Global)

Credential dùng chung giữa market (một WebNIC API user); lựa chọn này chỉ điều khiển hành vi riêng theo market. Hiện tại đó là **country code mặc định**: contact/tổ chức/CSR của VN tự dùng `VN` nếu không có quốc gia được cung cấp; Global không áp dụng mặc định. Hook lọc sản phẩm và (tương lai) định giá VN hiện vẫn trả toàn bộ danh mục. Mặc định là VN.

## Bản đồ endpoint → method

| Endpoint | Method |
|----------|--------|
| `POST /reseller/v2/api-user/token` | `getAccessToken()` |
| `GET /ssl/v2/products/list` | `getProducts()` / `listProducts()` |
| `GET /ssl/v2/products/list/price` (deprecated) | `getProductPriceList()` / `listProductsWithPrice()` |
| `GET /ssl/v2/products/rules` | `getProductRules()` |
| `GET /ssl/v2/products/info` | `getProductInfo()` |
| `GET /ssl/v2/products/pricing` | `getProductPricing()` |
| `POST /ssl/v2/orders/new` | `Create()` |
| `POST /ssl/v2/orders/{id}/renew` | `Renewal()` |
| `POST /ssl/v2/orders/{id}/reissue` | `Reissue()` |
| `POST /ssl/v2/orders/{id}/cancel` | `Terminate()` |
| `GET /ssl/v2/orders/info` | `getOrderInfo()` / `getSynchInfo()` |
| `POST /ssl/v2/orders/search` | `searchOrders()` |
| `PUT /ssl/v2/orders/{id}/auth` | `changeAuthType()` (đổi phương thức DCV) |
| `GET /ssl/v2/orders/{id}/auth/info` | `fetchAuthInfo()` |
| `GET /ssl/v2/orders/{id}/dcv-status` | `fetchDcvStatus()` / `getDcvStatus()` |
| `POST /ssl/v2/orders/{id}/download/format/{format}` | `downloadCertificateArchive()` / `CertDownload()` |
| `GET /ssl/v2/domainValidations/approver-list` | `CertDCVEmail()` |
| `PUT /ssl/v2/orders/{id}/email/approver` | `CertDCVMethodChange()` |
| `POST /ssl/v2/orders/{id}/send/email/approver` | `CertDCVEmailResend()` |
| `POST /ssl/v2/csr/generate` | `generateCsr()` / `generateCsrForService()` |
| `POST /ssl/v2/csr/decode` | `decodeCsr()` / `decodeStoredCsr()` |
| `POST /ssl/v2/contact/create` | `ensureContacts()` |
| `GET /ssl/v2/contact/info/{contactID}` | `getSslContactInfo()` |
| `DELETE /ssl/v2/contact/delete/{contactId}` | `deleteSslContact()` |
| `POST /ssl/v2/organization/create` | `ensureOrganizationId()` |
| `POST /ssl/v2/organization/{id}/validate` | `submitOrganizationValidation()` |
| `GET /ssl/v2/organization/{id}/validate/status` | `getOrganizationValidationStatus()` |
| `GET /ssl/v2/organization/{id}/info` | `getOrganizationInfo()` |
| `POST /ssl/v2/organization/search` | `searchOrganizations()` |
| `PUT /ssl/v2/organization/{id}/contact/update` | `updateOrganizationContact()` |

## Giao diện quản trị

**Panel dịch vụ admin** — `admin/class.webnic_ssl_controller.php` và `admin/myadminarea.tpl`. Hợp đồng HostBill `SSLModule` hỗ trợ module tự render trang dịch vụ admin (cơ chế tương tự `thesslstore`): `accountdetails()` gán `custom_template`, nhờ đó panel xuất hiện trong trang dịch vụ admin. Panel hiển thị trạng thái/order ID/sản phẩm/phương thức DCV/common name/SAN; CSR đã lưu (kèm dữ liệu giải mã); và khi order đang xác minh, thông tin approver DNS/HTTP-file/email hiện tại. Action (gọi method module hiện có) qua `?cmd=webnic_ssl&action=<x>&id=<id>`:

| Action | Method |
|--------|--------|
| `sync` | `getSynchInfo()` |
| `renew` | `Renewal()` |
| `reissue` (CSR mới tùy chọn) | `Reissue()` |
| `changedcv` | `CertDCVMethodChange()` |
| `resend` | `CertDCVEmailResend()` |
| `download` | `CertDownload()` → PEM bundle |

> **Dựa trên hợp đồng nào?** `SSLModule` của HostBill **có** hỗ trợ trang dịch vụ admin do module render (`accountdetails()` → `custom_template`), vì vậy module có controller admin và template đầy đủ thay vì chỉ có dashboard widget.

### REST API phía client (User API)

`api/class.webnic_ssl_apiroutes.php` (manifest `api/webnic_ssl_apiroutes.json`) cung cấp self-service an toàn; mọi route đều có `auth: true` **và** kiểm tra lại quyền sở hữu service:

- `GET /service/@id/webnic_ssl/status` — đồng bộ và trả trạng thái certificate
- `GET /service/@id/webnic_ssl/dcv-methods` — phương thức DCV được hỗ trợ
- `GET /service/@id/webnic_ssl/certificate` — PEM chain đã phát hành
- `GET /service/@id/webnic_ssl/auth-info` — chi tiết DCV theo phương thức
- `GET /service/@id/webnic_ssl/dcv-status` — trạng thái DCV hiện tại
- `POST /service/@id/webnic_ssl/change-dcv-method` — đổi phương thức DCV
- `POST /service/@id/webnic_ssl/resend-approver-email` — gửi lại email approver
- `GET /service/@id/webnic_ssl/csr` — giải mã CSR đã lưu
- `POST /service/@id/webnic_ssl/generate-csr` — tạo CSR + key

Không công khai cancel/terminate qua API.

## Cài đặt / kích hoạt

1. Chép `Hosting/webnic_ssl/` vào `includes/modules/Hosting/`.
2. Chạy `chown -R www:www Hosting/webnic_ssl`.
3. **Settings → Modules**, kích hoạt **WebNIC SSL**.
4. **Settings → Apps** → thêm app WebNIC SSL (API Username / Secret, OTE checkbox), sau đó **Test Connection** (thao tác này tải danh sách sản phẩm).
5. Tạo SSL product, chọn **Connect with Module → WebNIC SSL**, đặt `product_key`, phương thức DCV được phép, giới hạn SAN và market.
6. Nếu cần REST route, kích hoạt plugin User API.

## Danh sách tính năng

- Khám phá sản phẩm và quy tắc đặt hàng (wildcard/SAN, giới hạn SAN, loại certificate) → `CertOptions()`.
- Tạo order từ CSR + lựa chọn DCV; tạo tổ chức và xác minh tổ chức OV/EV.
- DCV: DNS (TXT), HTTP (file), email approver — chọn/đổi/gửi lại và định dạng chi tiết theo domain cho HostBill.
- Cấp lại (CSR + SAN mới), gia hạn (theo dõi order ID mới), kết thúc/hủy.
- Đồng bộ trạng thái vendor→HostBill và thông báo khi certificate được cấp; `next_due` lấy theo ngày hết hạn certificate.
- Tải certificate (PEM/DER/PKCS7/PKCS12/NGINX/JKS; phân tích PEM, hỗ trợ ZIP).
- Tạo / giải mã CSR phía server.
- Tìm order, xem thông tin sản phẩm và giá realtime, đọc/xóa SSL contact, cập nhật contact tổ chức.

## Giới hạn đã biết

- `GET /ssl/v2/products/list/price` đã **deprecated** bởi WebNIC để chuyển sang `/products/list`; vẫn giữ qua `listProductsWithPrice()` như nguồn chọn thay thế.
- `searchOrders()`, `getProductInfo()`, `getProductPricing()`, `getSslContactInfo()`, `deleteSslContact()` và `updateOrganizationContact()` là backend helper, chưa được đưa lên UI admin.
- Đặt hàng OV/EV cần đầy đủ thông tin tổ chức và admin contact; nếu thiếu, module ném validation exception mô tả rõ trước khi gọi API.

## Nhật ký thay đổi

- **1.1.2**
  - Bổ sung bản dịch tiếng Việt đầy đủ bên cạnh hướng dẫn module tiếng Anh.

- **1.1.1**
  - Giới hạn thời gian kết nối/request và cache lỗi kết nối trong 45 giây, kèm thông báo WebNIC có hướng xử lý.
  - Giữ lại sản phẩm đã lưu khi không tải được danh mục sản phẩm.

- **1.1.0**
  - Hoàn thiện docs/license: MIT `LICENSE`, author/license headers và README.
  - Thêm giao diện quản lý dịch vụ admin (controller + `myadminarea.tpl`).
  - Bổ sung theo WebNIC API docs: `searchOrders()`, `getProductInfo()`, `getProductPricing()`, `getSslContactInfo()`, `deleteSslContact()`, `updateOrganizationContact()`.

## License và tác giả

MIT — xem [`LICENSE`](LICENSE).

© 2026 **HiTechCloud by Pho Tue SoftWare Solutions JSC**.
