# WebNIC DNS (`webnic_dns`)

HostBill **DNS** module for [WebNIC](https://www.webnic.cc/), built on the
**WebNIC DNS v2 REST API** (`dns/v2/*`). Part of the HiTechCloud WebNIC Suite
(`webnic_domain` / `webnic_ssl` / `webnic_dns`).

Modelled on the HostBill `cloudns` DNS module: it implements the full
`DNSModule` surface plus `ReverseDNS` and `DNSSECModule`, with admin + user
controllers, templates and User API routes.

---

## Overview

`webnic_dns` manages DNS **zones and records** through WebNIC, across both DNS
tiers:

* **Basic** DNS — free, bundled with a domain.
* **Premium / subscription** DNS — paid standalone-domain or partner
  subscription, with DNSSEC, record templates, georoute and whitelabel
  nameservers.

The record endpoints differ by tier; the module routes to the correct one per
zone (see **DNS tier** below).

### WebNIC service covered

WebNIC **DNS v2** (`dns/v2/*`) plus the reseller token endpoint.

* LIVE base: `https://api.webnic.cc/`
* TEST/OTE base: `https://oteapi.webnic.cc/`
* Base path prefix: `dns/v2/`.
* Auth: `POST reseller/v2/api-user/token` → Bearer (cached in `HBCache` key
  `webnic_dns`, refreshed + retried once on 401/403).
* Success envelope: `code == 1000`.

---

## Connect / configuration fields

### App (server custom) fields — read via `getCustom()`

| Field | Purpose |
|-------|---------|
| `username` / `password` | WebNIC reseller API user |
| `ote` | `1` → TEST host; otherwise LIVE |
| `market` | **vn** / **global** (see below) |
| `dns_tier` | `basic` / `premium` / `auto` (app-level default) |
| `premium_product` | `premium_ns_partner` / `premium_ns_georoute` |
| `default_ns` | Override default nameservers (whitespace/comma separated) |
| `whitelabel_admin_email` | VN-market default whitelabel admin email |

### Product options (Products & Services → Product → Connect with Module)

| Option | Purpose |
|--------|---------|
| `maxdomain` | Domain limit (`domainnumber` variable) |
| `dns_template` | Default DNS template |
| `dns_tier` | Per-product tier override (`basic`/`premium`/`auto`) |
| `auto_subscribe` | In `auto` tier, subscribe new zones to premium |
| `ns1`–`ns4` | Nameservers |
| `soaemailclient` / `soaemaildefault` / `soattldefault` | SOA settings |
| `hide_billing` / `hide_zone_management` | Client-area visibility toggles |

Per-service detail `option1` stores the premium **subscription id** (for
renew/unsubscribe).

### DNS tier (basic / premium / auto)

* **basic** → always use the basic record endpoints.
* **premium** → always use the subscription record endpoints.
* **auto** → detect: look the zone up via
  `subscription/standalone-domain/get-by-domain` and treat it as premium when a
  subscription exists (zone-records also report `sourceFrom`).

### Market selector (VN / Global)

Credentials are shared across markets; the market gates VN-specific behaviour
(currently the whitelabel admin-email default). DNS has little VN-specific logic
today, so the switch is largely forward-looking but keeps the WebNIC suite
consistent. Defaults to **vn**.

---

## Endpoint → method map

### Zones
| Endpoint | Method |
|----------|--------|
| `POST dns/v2/zone/{zone}/nameserver-subscription` | `addZone()`, `addZoneToNsSubscription()` |
| `GET dns/v2/zone/{zone}/nameserver-subscription` | `getZoneNsSubscription()` |
| `DELETE dns/v2/zone/{zone}` | `deleteZone()` |
| `GET dns/v2/zones` | `listZones()`, `importZones()`, `getArpaZones()`, `searchZones()` |

### Records
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

High-level record CRUD (`addRecord` / `editRecord` / `deleteRecord`) synthesises
opaque record ids (WebNIC records have none) and routes basic↔premium internally.

### DNSSEC (subscription)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/zone/{zone}/subscription/dnssec/{info,ds,dnskey}` | `getZoneSecurity()` |
| `PUT dns/v2/zone/{zone}/subscription/dnssec/enable` | `secureZone()` |
| `PUT dns/v2/zone/{zone}/subscription/dnssec/disable` | `deleteZoneSecurity()` |

`rectifyZone()` disables + re-enables and syncs HostBill's DNSSEC store.

### Statistics
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/statistics` | `getZoneStatistics()` |
| `GET dns/v2/premium-subscription/statistics` | `getPremiumSubscriptionStatistics()` |

### Zone forwarding (URL + email)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/zone/{zone}/url-forwardings` | `getUrlForwardings()` |
| `POST dns/v2/zone/{zone}/url-forwarding` | `addUrlForwarding()` |
| `DELETE dns/v2/zone/{zone}/url-forwarding` | `removeUrlForwarding()` |
| `GET dns/v2/zone/{zone}/email-forwardings` | `getEmailForwardings()` |
| `POST dns/v2/zone/{zone}/email-forwarding` | `addEmailForwarding()` |
| `DELETE dns/v2/zone/{zone}/email-forwarding` | `removeEmailForwarding()` |

### Whitelabel nameservers (partner-wide)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/subscription/whitelabel/ns` | `getWhitelabelNameservers()` |
| `POST dns/v2/subscription/whitelabel/ns` | `saveWhitelabelNameservers()` |
| `DELETE dns/v2/subscription/whitelabel/ns` | `removeWhitelabelNameservers()` |

### Subscription record templates (partner-wide, full CRUD)
| Endpoint | Method |
|----------|--------|
| `GET dns/v2/zone/subscription/templates` | `listRecordTemplates()` |
| `POST dns/v2/zone/subscription/template` | `createRecordTemplate()` |
| `GET dns/v2/zone/subscription/template/{id}` | `getRecordTemplate()` |
| `PUT dns/v2/zone/subscription/template/{id}` | `updateRecordTemplate()` |
| `POST dns/v2/zone/subscription/template/{id}/add` | `addRecordToTemplate()` |
| `POST dns/v2/zone/subscription/template/{id}/remove` | `removeRecordFromTemplate()` |
| `DELETE dns/v2/zone/subscription/template/{id}` | `deleteRecordTemplate()` |

### Subscriptions (NS / partner / standalone-domain premium)
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

---

## Admin UI

Admin controller `admin/class.webnic_dns_controller.php` (extends
`DNSTemplates_Controller`) adds these product-config tabs on top of the standard
DNS product config and the shared **DNS Templates** tab:

* **WebNIC Whitelabel NS** — whitelabel NS get/save/remove + basic/subscription default nameservers.
* **WebNIC Statistics** — partner zone statistics + premium usage.
* **WebNIC Record Templates** — subscription record template CRUD.
* **WebNIC Subscriptions** — per-zone premium subscribe/upgrade/renew/auto-renew/unsubscribe, partner subscription, URL/email forwarding.

All mutating admin actions dispatch through `handleWebnicAction()` on POST.

### Client area + REST API

* **User controller** (`user/class.webnic_dns_controller.php`, extends
  `DNSClient_Controller`): generic DNS client area (zone list, record CRUD) plus
  a client URL/email **forwarding** panel for zones they own.
* **User API** (`api/class.webnic_dns_apiroutes.php`, manifest
  `api/webnic_dns_apiroutes.json`): list zones; record CRUD; zone DNSSEC;
  URL/email forwarding list/add/remove; zone premium-subscription detail. Every
  route is `auth: true` and re-checks service ownership.

---

## Installation / activation

1. Copy `Hosting/webnic_dns/` into `includes/modules/Hosting/`.
2. `chown -R www:www Hosting/webnic_dns`.
3. **Settings → Modules**, activate **WebNIC DNS** (runs `install()` → adds
   DNSSEC client translations).
4. **Settings → Apps** → add a WebNIC DNS app (username/password, OTE, market,
   DNS tier, premium product, default/whitelabel NS).
5. Create a DNS product, **Connect with Module → WebNIC DNS**, set the product
   options (tier, nameservers, SOA, limits).
6. (User API) activate the User API plugin if the REST routes are wanted.

---

## Feature list

* Zone create (tiered NS subscription) / delete / list / import / reverse (.arpa).
* Record CRUD with basic↔premium routing, opaque ids, MX/SRV/CAA value building,
  unified basic+premium delete.
* DNSSEC enable/disable/rectify with DS + DNSKEY read.
* URL and email forwarding (admin + client).
* Whitelabel nameservers; subscription record templates (full CRUD).
* Premium standalone-domain subscriptions: subscribe/upgrade/renew/unsubscribe,
  auto-renewal toggles, partner subscription; zone & premium statistics.
* Reverse DNS and record-type discovery; configurable TTL flooring (min 60s).

---

## Known limitations

* DNSSEC, record templates, whitelabel NS, georoute and premium statistics are
  **premium/subscription** features; on basic-only zones they return empty / are
  not applicable.
* WebNIC records carry no native id, so HostBill ids are synthesised from
  `type/host/value/ttl`; editing a record is implemented as remove-old +
  add-new.
* `getZoneNsSubscription()` / the deprecated per-zone "Get Domain Zone" are
  superseded by `searchZones()` (`GET dns/v2/zones`).
* `deleteZoneRecordUnified()` is a backend helper; the standard client/admin
  flows still use the tier-specific delete via `removeValue()`.

---

## Changelog

* **1.1.1**
  * Bound connection and request durations for token and API calls to prevent
    unavailable WebNIC endpoints from blocking admin pages.
  * Cache transport failures for 45 seconds, return an actionable error, and
    clear the failure marker after successful authentication.

* **1.1.0**
  * Docs/license pass: MIT `LICENSE`, author/license headers, this README.
  * Gap-fill against WebNIC apidocs: `deleteZoneRecordUnified()`
    (`DELETE dns/v2/zone/{zone}/record`, basic + premium).
  * PHP 8.1–8.5 compatibility pass; four WebNIC admin product-config tabs.

---

## License & author

MIT — see [`LICENSE`](LICENSE).

© 2026 **HiTechCloud by Pho Tue SoftWare Solutions JSC**.
