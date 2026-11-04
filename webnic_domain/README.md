# WebNIC Domain (`webnic_domain`)

[English](README.md) | [Tiếng Việt](README.vi.md)

HostBill domain **registrar** module for [WebNIC](https://www.webnic.cc/), built
from scratch against the **WebNIC REST v2 API**. Part of the HiTechCloud WebNIC
Suite (`webnic_domain` / `webnic_ssl` / `webnic_dns`).

The module is fully self-contained under `Domain/webnic_domain/` and does not
reference or depend on any other WebNIC module at runtime.

---

## Overview

`webnic_domain` registers, transfers, renews, restores and manages domains
through WebNIC, implementing the HostBill `DomainModule` contract plus
`Observer`, `DomainLookupInterface`, `DomainPremiumInterface` and
`DomainPriceImport`. It covers the full WebNIC domain lifecycle: availability &
premium lookup, price import, contacts & registry contacts, nameservers & glue
hosts, EPP/auth codes, registry status/lock, WHOIS privacy, DNSSEC, registrant
accounts, transfer-away approvals and bulk operations.

### WebNIC service covered

WebNIC **Domain** (`domain/v2/*`) plus the reseller endpoints
(`reseller/v2/token`, `reseller/v2/balance`).

* LIVE base: `https://api.webnic.cc/`
* TEST/OTE base: `https://oteapi.webnic.cc/`
* Auth: `POST reseller/v2/api-user/token {username,password}` → Bearer token
  (cached in `HBCache`, transparently refreshed + retried once on 401/403).
* Success envelope: top-level `code == 1000`, payload in `data`; errors in
  `message` / `error.message`.

---

## Connect / configuration fields

Rendered in **Settings → Apps** (the registrar connection):

| Field | Type | Purpose |
|-------|------|---------|
| `Username` | input | WebNIC reseller API username |
| `Password` | password | WebNIC reseller API password |
| `OTE Environment` | check | Ticked → use the TEST/OTE host; unticked → LIVE (`$live = OTE != 1`) |
| `Market` | select | **Vietnam (VN)** or **Global** — see below |
| `registrant_user_id` | input | WebNIC registrant account id used on register/transfer |

### Market selector (VN / Global)

The `Market` selector gates all Vietnam-specific behaviour. It defaults to **VN**
when never saved.

* **Vietnam (VN):** enforces Vietnamese registrant-name rules, sensitive-keyword
  screening and EKYC matching; enables proxy/local-presence handling for
  proxy-eligible TLDs; converts imported TLD pricing to **VND**; skips `.vn`
  TLDs on price import (handled by the local VNNIC registrar).
* **Global:** standard international flow — no registrant-name/EKYC enforcement,
  source-currency pricing kept as-is, and `.vn` imported like any other TLD.

---

## Endpoint → method map

Every WebNIC endpoint the module calls (paths relative to the environment base):

### Auth / account
| Endpoint | Method |
|----------|--------|
| `POST reseller/v2/api-user/token` | `access_token()` |
| `GET reseller/v2/balance` | `testConnection()`, balance widget |

### Availability / pricing
| Endpoint | Method |
|----------|--------|
| `GET domain/v2/query` | `checkAvailability()`, `lookupDomain()` |
| `GET domain/v2/exts` | `getDomainPrices()` |
| `POST domain/v2/exts/pricing` | `getDomainPrices()` |
| `GET domain/v2/smart-query` | `smartQuery()` |
| `GET domain/v2/ext-rules` | `getExtensionRules()` |

### Register / transfer / renew / delete / restore
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/register` | `Register()` / `ManualRegister()` |
| `POST domain/v2/transfer-in` | `Transfer()` |
| `POST domain/v2/renew` | `Renew()` |
| `DELETE domain/v2/delete` | `Delete()` |
| `POST domain/v2/restore` | `RestoreDomain()` / `Restore()` |
| `POST domain/v2/reseller-transfer` | `submitResellerTransfer()` |

### Info / sync / status / lock / suspend
| Endpoint | Method |
|----------|--------|
| `GET domain/v2/info` | `_domain_details()`, `synchInfo()`, `getDomainInfo()`, `TransferAction()` |
| `PUT domain/v2/status` | `Lock()`, `Unlock()`, `updateStatus()` |
| `POST domain/v2/suspend` | `domainSuspend()` |
| `GET domain/v2/suspendStatus` | `getDomainSuspendStatus()` |
| `GET domain/v2/query-transfer-type` | `queryTransferType()` |
| `GET domain/v2/transfer-in/status` (+ `/{id}`) | `getTransferInStatus()`, `getTransferInStatusById()` |

### EPP / verification / certificate
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/auth-info/send` | `getEppCode()` |
| `POST domain/v2/auth-info/reset` | `ChangeEpp()` |
| `POST domain/v2/resend-verification-email` | `SendVerify()` |
| `GET domain/v2/download/certificate` | `downloadCertificateApi()` |

### Nameservers / glue hosts
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

### Contacts
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

### Registrant accounts
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

### Bulk operations
| Endpoint | Method |
|----------|--------|
| `POST domain/v2/bulk/register` | `bulkRegister()` |
| `POST domain/v2/bulk/renew` | `bulkRenew()` |
| `GET domain/v2/bulk/report` | `getBulkReportOverview()` |
| `POST domain/v2/bulk/report/{taskId}/details/list` | `getBulkReportDetails()` |

### New backend helpers

The following methods bind additional WebNIC endpoints for trusted HostBill
code or operator tooling. They are module methods, not new customer User API
routes or automatic admin-panel actions:

* `submitResellerTransfer()` submits a WebNIC **reseller-to-reseller** domain
  handover. This is distinct from an external registrar transfer-in and from
  the transfer-away approval/status operations above.
* `getTransferInStatus()` / `getTransferInStatusById()` read registrar
  transfer-in status by domain name or provider record ID.
* `domainSuspend()` applies registry-level suspension (`clientHold`), while
  `Suspended()` changes nameservers to the local hold nameservers. They are
  separate controls with different customer impact.
* `toggleProxyService()` subscribes or unsubscribes the WebNIC proxy service;
  this is distinct from the WHOIS privacy toggle. Confirm TLD eligibility,
  provider pricing, and the intended subscription state before changing it.
* `checkHost()` checks nameserver/glue-host availability for an extension.

These bindings call WebNIC directly; they do not add authorization, approval,
or billing workflows to HostBill by themselves. Restrict any caller that uses
them and verify provider-side state before retrying a timed-out mutation.

---

## Admin UI

The admin controller (`admin/class.webnic_domain_controller.php`) renders:

**In-domain panels** (on the domain edit page):
* Domain details / actions (SyncContact, ChangeEpp, Lock, Unlock, Hold/RemoveHold, SendVerify, Certificate, Restore)
* Domain status flags
* Domain contacts
* Transfer status (with transfer-type)
* DNSSEC (view/update/delete DS records)
* Universal WHOIS lookup
* Transfer-away (registrar + reseller, approve/reject)
* Certificate PDF download (multi-language)

**Standalone admin tools** (Extras / admin main menu):
* Registrant accounts — create / search / reassign
* Host-linked domains — list domains bound to a nameserver
* Bulk operations — bulk register, bulk renew, batch report lookup, bulk contact replace

**Dashboard widget** (`widgets/webnic_domain_balance/`): shows the WebNIC
reseller account balance (token-checked submit only; never on plain render).

---

## Installation / activation

1. Copy the `Domain/webnic_domain/` directory into
   `includes/modules/Domain/`.
2. Ensure ownership is `www:www` (`chown -R www:www Domain/webnic_domain`).
3. The module ships a `sql.sql` creating the shared `hb_domain_contacts` table
   (local contact storage) — apply it if not already present.
4. In HostBill: **Settings → Modules → Domain Registrars**, activate
   **WebNIC Domain**.
5. **Settings → Apps** → add a WebNIC app with Username / Password / Market and
   (optionally) OTE Environment, then **Test Connection** (reports the account
   balance on success).
6. Import TLD pricing via the registrar's price-import, and attach TLDs.

---

## Feature list

* Availability & premium-domain lookup; TLD price import (VND for VN, source
  currency for Global).
* Register (standard/premium, pending-order aware), manual register (skips
  registrant-name validation), transfer-in, renew (with expiry-date idempotency
  guard), delete, restore (redemption).
* IDN support (language code via extended attribute).
* Contacts: create + registry contact, query, modify + modify-at-registry,
  delete, bulk replace; Vietnamese accent normalisation on outbound payloads.
* Nameservers & glue hosts: get/update NS, register/modify/delete glue hosts,
  host availability & host-linked domains.
* EPP/auth code send & reset; registry lock/unlock & status flags; soft suspend
  (hold nameservers) + registry suspend; WHOIS privacy & proxy privacy toggles.
* Registry DNSSEC (support/get/update/delete DS).
* Registrant accounts (create/search/reassign); transfer-away approvals
  (registrar + reseller); bulk register/renew/report + bulk contact replace.
* Extended attributes per TLD (`.us` nexus, `.cn` org/individual types, HK
  bundled domain, HK/MY date of birth, IDN language code, etc.).
* Vietnamese registrant validation: sensitive-keyword screening
  (`libs/keywords.json`), registrant-name heuristics, EKYC matching.

---

## Known limitations

* **Document upload** for identity-verification TLDs
  (`POST domain/v2/upload-document/{id}`) is **not** wired: it needs a multipart
  request, which is inconsistent with this module's JSON `request()` client.
  Upload verification documents through the WebNIC reseller console.
* EKYC matching depends on the optional `Other\domain_documents` module; when
  absent, EKYC enforcement is skipped.
* WHOIS/RDDS is being phased out by registries; `getUniversalWhois()` is
  best-effort.
* VN price import uses a fixed VND conversion factor (26,000) rounded to the
  nearest 1,000; review before relying on it for billing.

---

## Changelog

* **1.2026-11-04.1**
  * Added a complete Vietnamese translation alongside the English module guide.

* **1.2026-11-03.1**
  * Set 10-second connection and 30-second request timeouts for WebNIC API
    calls so unreachable endpoints cannot indefinitely block admin pages.

* **1.2026-11-02.2**
  * Docs/license pass: MIT `LICENSE`, author/license headers, this README.
  * Gap-fill against WebNIC apidocs: `submitResellerTransfer()`,
    `getTransferInStatus()` / `…ById()`, `domainSuspend()` /
    `getDomainSuspendStatus()`, `toggleProxyService()`, `checkHost()`,
    `smartQuery()`, `getExtensionRules()`.
  * Added the WebNIC account-balance dashboard widget.

---

## License & author

MIT — see [`LICENSE`](LICENSE).

© 2026 **HiTechCloud by Pho Tue SoftWare Solutions JSC**.
