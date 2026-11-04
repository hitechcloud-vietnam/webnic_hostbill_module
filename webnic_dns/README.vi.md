# WebNIC DNS (`webnic_dns`)

[English](README.md) | [Tiếng Việt](README.vi.md)

Module HostBill **DNS** cho [WebNIC](https://www.webnic.cc/), xây dựng trên **WebNIC DNS v2 REST API** (`dns/v2/*`). Thuộc HiTechCloud WebNIC Suite (`webnic_domain` / `webnic_ssl` / `webnic_dns`).

Thiết kế theo module DNS `cloudns` của HostBill: triển khai toàn bộ bề mặt `DNSModule` cùng `ReverseDNS` và `DNSSECModule`, controller admin/user, template và User API route.

## Tổng quan

`webnic_dns` quản lý DNS **zone và record** qua WebNIC ở hai tier DNS:

- **Basic** DNS — miễn phí, đi kèm domain.
- **Premium / subscription** DNS — trả phí theo standalone-domain hoặc partner subscription; hỗ trợ DNSSEC, record template, georoute và nameserver whitelabel.

Endpoint record khác nhau theo tier; module tự chọn endpoint phù hợp cho từng zone (xem phần **DNS tier**).

### Dịch vụ WebNIC được hỗ trợ

WebNIC **DNS v2** (`dns/v2/*`) và endpoint cấp token reseller.

- LIVE base: `https://api.webnic.cc/`
- TEST/OTE base: `https://oteapi.webnic.cc/`
- Prefix base path: `dns/v2/`.
- Xác thực: `POST reseller/v2/api-user/token` → Bearer (cache bằng `HBCache` key `webnic_dns`, tự refresh và retry một lần khi 401/403).
- Envelope thành công: `code == 1000`.

## Trường kết nối / cấu hình

### Trường App (server custom) — đọc qua `getCustom()`

| Trường | Mục đích |
|-------|---------|
| `username` / `password` | Tài khoản API reseller WebNIC |
| `ote` | `1` → host TEST; giá trị khác → LIVE |
| `market` | **vn** / **global** (xem bên dưới) |
| `dns_tier` | Mặc định cấp app: `basic` / `premium` / `auto` |
| `premium_product` | `premium_ns_partner` / `premium_ns_georoute` |
| `default_ns` | Ghi đè nameserver mặc định (phân tách bằng khoảng trắng/dấu phẩy) |
| `whitelabel_admin_email` | Email admin whitelabel mặc định cho market VN |

### Product option (Products & Services → Product → Connect with Module)

| Option | Mục đích |
|--------|---------|
| `maxdomain` | Giới hạn domain (biến `domainnumber`) |
| `dns_template` | DNS template mặc định |
| `dns_tier` | Ghi đè tier theo product (`basic`/`premium`/`auto`) |
| `auto_subscribe` | Với tier `auto`, tự đăng ký premium cho zone mới |
| `ns1`–`ns4` | Nameserver |
| `soaemailclient` / `soaemaildefault` / `soattldefault` | Cài đặt SOA |
| `hide_billing` / `hide_zone_management` | Ẩn/hiện khu vực billing và quản lý zone phía client |

Chi tiết dịch vụ `option1` lưu **subscription id** premium để gia hạn/hủy đăng ký.

### DNS tier (basic / premium / auto)

- **basic** → luôn dùng basic record endpoint.
- **premium** → luôn dùng subscription record endpoint.
- **auto** → tự phát hiện: tra zone qua `subscription/standalone-domain/get-by-domain`; nếu có subscription thì xem là premium (response zone-records cũng có `sourceFrom`).

### Chọn market (VN / Global)

Credential dùng chung giữa các market; market điều khiển hành vi riêng cho VN (hiện là email admin whitelabel mặc định). DNS hiện có ít logic riêng theo VN nên lựa chọn này chủ yếu để thống nhất với các module WebNIC khác và hỗ trợ mở rộng sau này. Mặc định `vn`.

## Bản đồ endpoint → method

### Zone
| Endpoint | Method |
|----------|--------|
| `POST dns/v2/zone/{zone}/nameserver-subscription` | `addZone()`, `addZoneToNsSubscription()` |
| `GET dns/v2/zone/{zone}/nameserver-subscription` | `getZoneNsSubscription()` |
| `DELETE dns/v2/zone/{zone}` | `deleteZone()` |
| `GET dns/v2/zones` | `listZones()`, `importZones()`, `getArpaZones()`, `searchZones()` |

### Record
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/zone/{zone}/records` | `getZone()`, `getValues()` |
| `POST dns/v2/zone/{zone}/record` (basic save) | `saveSet()` |
| `POST dns/v2/zone/{zone}/subscription/record/replace` (premium) | `saveSet()` |
| `DELETE dns/v2/zone/{zone}/subscription/record` (premium) | `removeValue()` |
| `DELETE dns/v2/zone/{zone}/basic/record` (basic) | `removeValue()` |
| `DELETE dns/v2/zone/{zone}/record` (unified basic+premium) | `deleteZoneRecordUnified()` |
| `GET dns/v2/zone/record-types` | `getSupportedRecords()` |
| `GET dns/v2/zone/basic/record/nameservers` | `getBasicRecordNameservers()` |
| `GET dns/v2/zone/subscription/record/nameservers` | `getSubscriptionRecordNameservers()` |

CRUD record cấp cao (`addRecord` / `editRecord` / `deleteRecord`) tự tổng hợp record ID mờ đục (WebNIC không cấp ID) và nội bộ định tuyến basic↔premium.

### DNSSEC (subscription)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/zone/{zone}/subscription/dnssec/{info,ds,dnskey}` | `getZoneSecurity()` |
| `PUT dns/v2/zone/{zone}/subscription/dnssec/enable` | `secureZone()` |
| `PUT dns/v2/zone/{zone}/subscription/dnssec/disable` | `deleteZoneSecurity()` |

`rectifyZone()` tắt rồi bật lại DNSSEC và đồng bộ kho DNSSEC của HostBill.

### Thống kê
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/statistics` | `getZoneStatistics()` |
| `GET dns/v2/premium-subscription/statistics` | `getPremiumSubscriptionStatistics()` |

### Forwarding zone (URL + email)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/zone/{zone}/url-forwardings` | `getUrlForwardings()` |
| `POST dns/v2/zone/{zone}/url-forwarding` | `addUrlForwarding()` |
| `DELETE dns/v2/zone/{zone}/url-forwarding` | `removeUrlForwarding()` |
| `GET dns/v2/zone/{zone}/email-forwardings` | `getEmailForwardings()` |
| `POST dns/v2/zone/{zone}/email-forwarding` | `addEmailForwarding()` |
| `DELETE dns/v2/zone/{zone}/email-forwarding` | `removeEmailForwarding()` |

### Nameserver whitelabel (toàn partner)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/subscription/whitelabel/ns` | `getWhitelabelNameservers()` |
| `POST dns/v2/subscription/whitelabel/ns` | `saveWhitelabelNameservers()` |
| `DELETE dns/v2/subscription/whitelabel/ns` | `removeWhitelabelNameservers()` |

### Template record subscription (toàn partner, CRUD đầy đủ)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/zone/subscription/templates` | `listRecordTemplates()` |
| `POST dns/v2/zone/subscription/template` | `createRecordTemplate()` |
| `GET dns/v2/zone/subscription/template/{id}` | `getRecordTemplate()` |
| `PUT dns/v2/zone/subscription/template/{id}` | `updateRecordTemplate()` |
| `POST dns/v2/zone/subscription/template/{id}/add` | `addRecordToTemplate()` |
| `POST dns/v2/zone/subscription/template/{id}/remove` | `removeRecordFromTemplate()` |
| `DELETE dns/v2/zone/subscription/template/{id}` | `deleteRecordTemplate()` |

### Subscription (NS / partner / standalone-domain premium)
| Endpoint | Method |
|----------|--------|
| `POST dns/v2/subscription/partner/zone/{zone}` | `addZoneToPartnerSubscription()` |
| `DELETE dns/v2/subscription/partner/zone/{zone}` | `removeZoneFromPartnerSubscription()` |
| `GET dns/v2/subscription/standalone-domains` | `listDomainSubscriptions()` |
| `POST dns/v2/subscription/standalone-domain` | `subscribeDomain()` / `upgradeToPremium()` |
| `GET dns/v2/subscription/standalone-domain/{id}` | `getDomainSubscriptionById()` |
| `GET dns/v2/subscription/standalone-domain/get-by-domain` | `getDomainSubscriptionByDomain()`, `isPremium()` |
| `PUT dns/v2/subscription/standalone-domain/{id}/renew` | `renewDomainSubscription()` |
| `DELETE dns/v2/subscription/standalone-domain/{id}` | `unsubscribeDomain()`, `Terminate()` |
| `PUT dns/v2/subscription/standalone-domain/{id}/auto-renewal/{enable,disable}` | `enableAutoRenewal()` / `disableAutoRenewal()` / `toggleAutoRenewal()` |

## Giao diện quản trị

Admin controller `admin/class.webnic_dns_controller.php` (kế thừa `DNSTemplates_Controller`) bổ sung các tab cấu hình product bên cạnh cấu hình DNS chuẩn và tab **DNS Templates** dùng chung:

- **WebNIC Whitelabel NS** — đọc/lưu/xóa whitelabel NS và nameserver mặc định basic/subscription.
- **WebNIC Statistics** — thống kê zone partner và mức sử dụng premium.
- **WebNIC Record Templates** — CRUD template record subscription.
- **WebNIC Subscriptions** — theo zone: subscribe/upgrade/renew/auto-renew/unsubscribe premium, partner subscription và URL/email forwarding.

Mọi thao tác admin thay đổi trạng thái được gửi POST qua `handleWebnicAction()`.

### Client area + REST API

- **User controller** (`user/class.webnic_dns_controller.php`, kế thừa `DNSClient_Controller`): khu vực DNS client thông thường (danh sách zone, CRUD record) và panel URL/email forwarding cho zone thuộc quyền sở hữu của client.
- **User API** (`api/class.webnic_dns_apiroutes.php`, manifest `api/webnic_dns_apiroutes.json`): liệt kê zone; CRUD record; DNSSEC zone; liệt kê/thêm/xóa URL/email forwarding; xem chi tiết premium subscription của zone. Mọi route có `auth: true` và kiểm tra lại quyền sở hữu service.

## Cài đặt / kích hoạt

1. Chép `Hosting/webnic_dns/` vào `includes/modules/Hosting/`.
2. Chạy `chown -R www:www Hosting/webnic_dns`.
3. **Settings → Modules**, kích hoạt **WebNIC DNS** (`install()` thêm bản dịch DNSSEC cho client).
4. **Settings → Apps** → thêm app WebNIC DNS (username/password, OTE, market, DNS tier, premium product, default/whitelabel NS).
5. Tạo DNS product, chọn **Connect with Module → WebNIC DNS**, cấu hình product option (tier, nameserver, SOA, giới hạn).
6. Nếu cần REST route, kích hoạt plugin User API.

## Danh sách tính năng

- Tạo zone (subscription NS theo tier) / xóa / liệt kê / import / reverse (.arpa).
- CRUD record có định tuyến basic↔premium, ID mờ đục, tạo giá trị MX/SRV/CAA, xóa thống nhất basic+premium.
- Bật/tắt/rectify DNSSEC, đọc DS và DNSKEY.
- URL và email forwarding (admin + client).
- Nameserver whitelabel; CRUD đầy đủ subscription record template.
- Premium standalone-domain subscription: subscribe/upgrade/renew/unsubscribe, bật/tắt auto-renewal, partner subscription; thống kê zone và premium.
- Reverse DNS, khám phá loại record và cấu hình sàn TTL (tối thiểu 60 giây).

## Giới hạn đã biết

- DNSSEC, record template, whitelabel NS, georoute và thống kê premium là tính năng **premium/subscription**; zone chỉ dùng basic sẽ không áp dụng hoặc trả kết quả rỗng.
- WebNIC record không có ID native, vì vậy HostBill tổng hợp ID từ `type/host/value/ttl`; sửa record được thực hiện bằng cách xóa bản cũ rồi thêm bản mới.
- `getZoneNsSubscription()` / API per-zone "Get Domain Zone" đã deprecated và được thay bằng `searchZones()` (`GET dns/v2/zones`).
- `deleteZoneRecordUnified()` là backend helper; luồng client/admin tiêu chuẩn vẫn xóa theo tier qua `removeValue()`.

## Nhật ký thay đổi

- **1.1.2**
  - Bổ sung bản dịch tiếng Việt đầy đủ bên cạnh hướng dẫn module tiếng Anh.

- **1.1.1**
  - Giới hạn thời gian kết nối và request token/API để endpoint WebNIC không phản hồi không thể làm treo trang admin.
  - Cache lỗi kết nối trong 45 giây, trả thông báo có hướng xử lý và xóa cờ lỗi sau khi xác thực thành công.

- **1.1.0**
  - Hoàn thiện docs/license: MIT `LICENSE`, author/license headers và README.
  - Bổ sung theo WebNIC API docs: `deleteZoneRecordUnified()` (`DELETE dns/v2/zone/{zone}/record`, basic + premium).
  - Tương thích PHP 8.1–8.5; thêm bốn tab cấu hình sản phẩm WebNIC trong admin.

## License và tác giả

MIT — xem [`LICENSE`](LICENSE).

© 2026 **HiTechCloud by Pho Tue SoftWare Solutions JSC**.
