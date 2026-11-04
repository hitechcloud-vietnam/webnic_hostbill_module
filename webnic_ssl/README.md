# WebNIC SSL (`webnic_ssl`)

HostBill **SSL provisioning** module for [WebNIC](https://www.webnic.cc/), built
against the **WebNIC REST v2 API** (`ssl/v2/*`). Part of the HiTechCloud WebNIC
Suite (`webnic_domain` / `webnic_ssl` / `webnic_dns`).

It implements the HostBill `SSLModule` contract: product discovery, CSR
ordering, domain-control validation (DNS / HTTP file / approver email), reissue,
renewal, status synchronisation, organization validation (OV/EV) and certificate
download.

---

## Overview

`webnic_ssl` places and manages SSL certificate **orders** through WebNIC. It
discovers products and ordering rules, creates administrator/technical contacts
and organizations as needed, submits orders from a stored CSR + DCV choice,
drives domain-control validation, and tracks order/cert status back into
HostBill.

### WebNIC service covered

WebNIC **SSL** (`ssl/v2/*`) plus the reseller token endpoint.

* LIVE host: `https://api.webnic.cc`
* TEST/OTE host: `https://oteapi.webnic.cc`
* Auth: `POST /reseller/v2/api-user/token` → Bearer token (cached in `HBCache`
  per base-url + user, refreshed + retried once on a 401).
* Success envelope: `code == 1000`, payload in `data`; the transport returns the
  `data` object directly and throws on any non-1000 / HTTP ≥ 400 response.

---

## Connect / configuration fields

### Connection (server/app) fields

| Field | Required | Purpose |
|-------|----------|---------|
| Username (`CONNECTION_FIELD_USERNAME`) | yes | **API Username** |
| Password (`CONNECTION_FIELD_PASSWORD`) | yes | **API Secret** |
| Checkbox (`CONNECTION_FIELD_CHECKBOX`) | — | **OTE Environment** (ticked → TEST host) |

### Product-configuration options (HostBill product page)

| Option | Type | Purpose |
|--------|------|---------|
| `product_key` | loadable (`listProducts`) | WebNIC SSL product |
| `market` | select | **Vietnam (VN)** / **Global** |
| `DCV` | loadablemultiple (`listDCVMethod`) | Allowed DCV methods (Email / DNS / HTTP) |
| `SAN_LIMIT` | input | Maximum SAN entries (further clamped by product rules) |
| `special_instruction` | textarea | Free text forwarded to WebNIC on order placement |
| `san` | form | Enables the SAN entry form |

### Market selector (VN / Global)

Credentials are shared across markets (one WebNIC API user); the selector only
gates market-specific behaviour. Today that is the **default country code**:
VN-market contacts/organizations/CSRs default to `VN` when no country is
supplied; Global applies no default. The product-filter and (future) VN pricing
hooks are present but currently pass through the full catalogue. Defaults to VN.

---

## Endpoint → method map

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
| `PUT /ssl/v2/orders/{id}/auth` | `changeAuthType()` (DCV method change) |
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

---

## Admin UI

**Admin service panel** — `admin/class.webnic_ssl_controller.php` +
`admin/myadminarea.tpl`. The HostBill `SSLModule` contract supports a module
rendering its own admin service page (the same mechanism `thesslstore` uses):
`accountdetails()` assigns `custom_template`, so the panel appears under the
service in the admin area. It shows order status, order id, product, DCV method,
common name and SANs; the stored CSR (with decoded details); and, while the
order is validating, the live DNS / HTTP-file / email approver details. Actions
(wired to existing module methods) via `?cmd=webnic_ssl&action=<x>&id=<id>`:

| Action | Method |
|--------|--------|
| `sync` | `getSynchInfo()` |
| `renew` | `Renewal()` |
| `reissue` (optional new CSR) | `Reissue()` |
| `changedcv` | `CertDCVMethodChange()` |
| `resend` | `CertDCVEmailResend()` |
| `download` | `CertDownload()` → PEM bundle |

> **Which contract?** The HostBill `SSLModule` **does** support a module-rendered
> admin service page (`accountdetails()` → `custom_template`), so this module
> adds a full admin controller + template rather than a dashboard widget.

### Client REST API (User API)

`api/class.webnic_ssl_apiroutes.php` (manifest
`api/webnic_ssl_apiroutes.json`) exposes a safe self-service surface; every
route is `auth: true` **and** re-checks service ownership:

* `GET  /service/@id/webnic_ssl/status` — sync + return cert status
* `GET  /service/@id/webnic_ssl/dcv-methods` — supported DCV methods
* `GET  /service/@id/webnic_ssl/certificate` — issued PEM chain
* `GET  /service/@id/webnic_ssl/auth-info` — per-method DCV details
* `GET  /service/@id/webnic_ssl/dcv-status` — current DCV status
* `POST /service/@id/webnic_ssl/change-dcv-method` — switch DCV method
* `POST /service/@id/webnic_ssl/resend-approver-email` — resend approver email
* `GET  /service/@id/webnic_ssl/csr` — decode stored CSR
* `POST /service/@id/webnic_ssl/generate-csr` — generate CSR + key

No cancel/terminate is exposed over the API.

---

## Installation / activation

1. Copy `Hosting/webnic_ssl/` into `includes/modules/Hosting/`.
2. `chown -R www:www Hosting/webnic_ssl`.
3. **Settings → Modules**, activate **WebNIC SSL**.
4. **Settings → Apps** → add a WebNIC SSL app (API Username / Secret, OTE
   checkbox), **Test Connection** (forces a product listing).
5. Create an SSL product, **Connect with Module → WebNIC SSL**, pick the
   `product_key`, allowed DCV methods, SAN limit and market.
6. (User API) activate the User API plugin if the REST routes are wanted.

---

## Feature list

* Product discovery with ordering rules (wildcard/SAN support, SAN limit, cert
  type) → `CertOptions()`.
* Order placement from CSR + DCV choice; OV/EV organization create + validation.
* DCV: DNS (TXT), HTTP (file), email approver — selection, change, resend, and
  per-domain detail formatting for HostBill.
* Reissue (new CSR + SAN), renewal (new order id tracked), terminate/cancel.
* Status synchronisation with vendor→HostBill status mapping and
  issued-certificate notification; `next_due` from cert expiry.
* Certificate download (PEM/DER/PKCS7/PKCS12/NGINX/JKS; PEM parsed, zip-aware).
* Server-side CSR generate / decode.
* Order search, product info & real-time pricing, SSL contact read/delete,
  organization contact update.

---

## Known limitations

* `GET /ssl/v2/products/list/price` is **deprecated** by WebNIC in favour of
  `/products/list`; it remains available via `listProductsWithPrice()` as an
  alternate selection source only.
* `searchOrders()`, `getProductInfo()`, `getProductPricing()`,
  `getSslContactInfo()`, `deleteSslContact()` and `updateOrganizationContact()`
  are backend helpers (not yet surfaced in the admin panel UI).
* OV/EV ordering requires complete organization + administrator contact data;
  missing fields raise a descriptive validation exception before the API call.

---

## Changelog

* **1.1.1**
  * Bound connection and request durations and cache transport failures for 45
    seconds, with an actionable WebNIC connectivity error.
  * Preserve the saved product selection when the product catalogue cannot be
    fetched.

* **1.1.0**
  * Docs/license pass: MIT `LICENSE`, author/license headers, this README.
  * Added the admin service management UI (controller + `myadminarea.tpl`).
  * Gap-fill against WebNIC apidocs: `searchOrders()`, `getProductInfo()`,
    `getProductPricing()`, `getSslContactInfo()`, `deleteSslContact()`,
    `updateOrganizationContact()`.

---

## License & author

MIT — see [`LICENSE`](LICENSE).

© 2026 **HiTechCloud by Pho Tue SoftWare Solutions JSC**.
