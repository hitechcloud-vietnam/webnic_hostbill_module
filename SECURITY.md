# Security Policy

This document covers the WebNIC Domain, DNS, and SSL modules in this repository. It provides deployment guidance, not a security certification or a guarantee that every HostBill installation is secure.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting feature for this repository if it is enabled. If it is unavailable, contact the maintainers through the repository owner organization and request a private reporting channel before sharing technical details. Do not create a public issue or disclose an unpatched vulnerability publicly.

Include the affected module and version, HostBill/PHP versions, impact, prerequisites, a concise reproduction, and any proposed mitigation. Use synthetic data and a test environment. Never include a live password, bearer token, customer record, private key, unredacted CSR, or production API response.

There is no published response-time commitment, supported-branch policy, or formal disclosure timeline. The maintainers and reporter should coordinate validation, remediation, and disclosure privately. This policy does not promise a fix for unsupported environments or third-party products.

## Security-critical implementation note

When HostBill debug logging is active, the current DNS module sends the token endpoint response to `HBDebug::debug`; a successful response contains an `access_token`. It also logs API response and request-parameter data. The domain module logs some registration parameters and API responses. Those records can contain credentials, contact information, or other sensitive values.

Treat HostBill debug output as secret-bearing. Keep verbose/debug logging off in production where operationally possible, restrict access to logs, minimize retention, and review/redact logs before sharing them. If a bearer token or credential may have been captured or disclosed, treat it as compromised: rotate the WebNIC API credential through the provider's supported process, invalidate or allow cached tokens to expire as appropriate, and review related access. This documentation records the current behavior; it does not change or remediate the code.

## Data inventory and handling

| Data | Where it is handled | Minimum handling rule |
| --- | --- | --- |
| WebNIC username/password and bearer token | HostBill connection settings, module requests, HostBill token cache; debug output may expose token/API material as described above | Restrict admin/cache/log access. Never put values in source control, tickets, screenshots, or shell history. Rotate after suspected exposure. |
| Domain registrant/contact and identity fields | HostBill service data and `hb_domain_contacts` | Restrict database, backup, export, and admin access. Collect only what the registration requires and follow applicable retention/deletion rules. |
| DNS zone names, records, forwarding targets, subscription state | HostBill service data, WebNIC API, and potentially debug output | Treat zone contents and target addresses as customer configuration. Restrict API and log access. |
| CSR, DCV instructions/status, certificate order/contact data | SSL service details, WebNIC API, and potentially debug output | Treat as confidential customer/order information. Redact from support bundles. A CSR does not contain the private key, but it can identify a customer/domain and must still be handled carefully. |
| Certificate and private key | Certificate response may be returned by the SSL API; private keys are managed by the customer/HostBill workflow and should not be sent to support | Protect issued certificate access. Never collect or transmit the corresponding private key in support requests. |
| HostBill IDs, API URLs, timestamps, error messages | HostBill/API logs and support reports | Review for personal or operationally sensitive details; share the minimum necessary. |

The operator is responsible for data-controller/processor obligations, access reviews, retention, deletion, backups, and incident notification applicable to their deployment.

## Deployment baseline

### Credentials and host security

- Use a dedicated WebNIC reseller/API account with only the permissions required by the deployed modules and products. Avoid sharing one credential across unrelated environments where provider policy permits separate credentials.
- Keep OTE and live credentials distinct. Confirm the selected environment before every production change; a test credential should not silently become a live credential.
- Enter secrets only through HostBill's protected connection configuration. Do not commit credentials, `.env` files, exports, request captures, or production configuration to this repository.
- Restrict HostBill administrator access with unique accounts, strong authentication supported by the deployment, least privilege, and periodic review.
- Restrict filesystem, database, cache, backup, and log access to authorized operators. Encrypt backups and protect their keys separately.
- Keep HostBill, PHP, operating system, TLS libraries, and dependencies patched according to their vendors' support guidance. The repository does not declare a complete compatibility or patch-support matrix.
- Permit outbound HTTPS only to required WebNIC hosts and required HostBill services where network policy allows. Do not disable TLS certificate verification to work around connectivity problems.

### User API and authorization

DNS and SSL expose User API manifests; the Domain module does not declare its own route manifest. The DNS and SSL handlers mark routes as authenticated and additionally check that the authenticated client owns the HostBill service identified by `@id`. Keep both HostBill authentication and service/client authorization enabled. A service ID is an identifier, not an authorization secret.

Use separate API credentials per integration where possible, grant only the needed scopes/permissions, protect them as secrets, rotate them after staff or integration changes, and monitor failed/abnormal access. Avoid publishing API routes through unauthenticated proxies, caches, or browser code that exposes credentials.

### Logs and diagnostics

- Avoid enabling verbose/debug logging in production except for a time-limited, approved investigation.
- Restrict log readers and log exports; set retention according to operational and legal requirements.
- Before sending logs, remove `Authorization` headers, bearer/access tokens, passwords, request bodies, contact details, domain-owner identifiers, CSR contents, certificate payloads, and customer-specific forwarding targets.
- Do not assume a log line is safe because it contains an error message or is generated by HostBill. Apply the DNS/domain caveat above.
- Do not paste raw API requests or responses into public issues. Prefer a sanitized error code, route name, timestamp/time zone, and reproducible OTE steps.

## Production and change controls

- Run install, upgrade, and recovery rehearsals in staging with OTE before production changes.
- Back up module files and the HostBill database, including the domain-contact table, and verify that a restore can be performed.
- Review each release diff and test the affected workflow; code rollback cannot reverse a completed domain, DNS, or certificate operation at WebNIC.
- Require a second review for high-impact changes where the operator's process supports it: registrar transfers, domain deletion/restore, nameserver or DNSSEC changes, subscription cancellation, and SSL reissue/termination.
- Record who approved and performed production changes without copying customer secrets into the change record.

## Incident response checklist

1. Contain: disable the affected integration/API credential or restrict access using HostBill/provider controls. Avoid destructive cleanup before preserving evidence.
2. Preserve: record the incident time window, affected module/service IDs, relevant sanitized log references, and actions taken. Store evidence with restricted access.
3. Rotate: replace exposed WebNIC credentials through the supported provider process; review active sessions/tokens and caches using HostBill/provider procedures.
4. Assess: identify which services, customers, records, contacts, certificates, and logs may be affected. Verify remote state directly with WebNIC before retrying state-changing operations.
5. Recover: restore the least-privilege configuration, test in OTE where possible, and then validate production with a controlled operation.
6. Notify: follow the organization's incident, contractual, and legal notification requirements. Coordinate a product vulnerability report privately as described above.
7. Learn: document root cause and follow-up actions. Do not publish customer data or exploit details while the issue remains unpatched.

## Third-party boundaries

HostBill, WebNIC APIs/services, the operating system, PHP, and dependencies have their own security policies and terms. Report issues in those products to their respective maintainers. MIT licensing of this repository does not provide a security warranty for those systems or for a deployment built on them.