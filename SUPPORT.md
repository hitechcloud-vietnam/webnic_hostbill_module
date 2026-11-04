# Support Policy

[English](SUPPORT.md) | [Tiếng Việt](SUPPORT.vi.md)

This page describes how to request help for the WebNIC modules in this repository. It does not create a service-level agreement or a guaranteed response time.

## Support channels

| Request | Channel |
| --- | --- |
| Bug report, documentation correction, or feature request | Open a GitHub issue in [`hitechcloud-vietnam/webnic_hostbill_module`](https://github.com/hitechcloud-vietnam/webnic_hostbill_module/issues). Search existing issues first and keep one issue focused on one problem. |
| Suspected vulnerability or credential exposure | Follow [`SECURITY.md`](SECURITY.md); use a private reporting channel, never a public issue. |
| WebNIC account, API permission, TLD/product eligibility, price, order state, or provider-side outage | Contact WebNIC through the account's authorized support channel. Include the provider reference and timestamp, not credentials. |
| HostBill core behavior, licensing, updates, or platform API contract | Contact HostBill support. Include a minimal sanitized reproduction and HostBill version. |
| Server/network, PHP, database, backup, firewall, or reverse-proxy issue | Contact the hosting/platform operator responsible for that environment. |

The repository does not publish paid support terms, support hours, response targets, an escalation phone number, or a release support window. Do not assume a public issue is monitored continuously.

## What to include

Use this checklist in a bug report:

1. Module: Domain, DNS, or SSL; module version shown in HostBill or source metadata.
2. Environment: HostBill version, PHP version, operating system if relevant, OTE or live, and whether the problem affects staging or production.
3. Workflow: relevant product/app settings with secret values removed, the action performed, exact steps, expected result, actual result, and whether it is reproducible.
4. Scope and impact: affected service/order count, start time with time zone, last known successful operation, customer impact category, and any safe workaround.
5. Evidence: sanitized HostBill/module error, WebNIC error code/message, route/method (not authorization header), and a request/correlation ID if WebNIC provides one.
6. Recent changes: module update, HostBill/PHP update, credential rotation, product setting, DNS/SSL/domain change, or network change.

Use synthetic domains, contact data, CSR material, and service IDs in public reports whenever possible. A screenshot is optional; crop and redact it before upload.

## Never attach

- Passwords, API secrets, bearer/access tokens, cookies, session IDs, private keys, or database credentials.
- Unredacted request/response bodies, authorization headers, `hb_domain_contacts` exports, or customer contact/identity data.
- Customer CSR content, certificate bundles, DCV email addresses, private zone contents, or unrestricted production log archives.
- Database dumps, complete HostBill configuration exports, or backups.

DNS debug output can contain the token response and API request/response data. Review [`SECURITY.md`](SECURITY.md) before collecting or sharing any debug log. If a secret was already posted, remove/restrict the report if possible and rotate the exposed credential immediately; deleting a post may not remove copies or notifications.

## Triage and diagnosis

Start with the least risky checks:

1. Confirm the intended HostBill app is connected to the product/service and the OTE/live switch is correct.
2. Test the connection and verify WebNIC account permissions and product/TLD availability through the provider.
3. Reproduce in OTE using a non-customer test service and the smallest workflow that demonstrates the issue.
4. Compare the HostBill state with WebNIC's authoritative order/domain/zone state before retrying a create, transfer, delete, renewal, reissue, or subscription action.
5. If the failure remains, open an issue with the checklist above and a sanitized error trace.

Do not repeatedly retry a mutating operation to clear an ambiguous timeout. First inspect remote state; duplicate registration, reseller transfer, renewal, registry suspension, proxy subscription, DNS deletion, certificate, or shared contact/organization changes may have external consequences.

## Triage categories

- **Configuration:** wrong environment, credentials, module assignment, product option, market, DNS tier, or unsupported product.
- **Provider:** WebNIC API error, account permission, TLD/SSL product rule, provider-side state, or outage.
- **Platform:** HostBill module contract, User API registration, PHP/runtime, database, or platform upgrade.
- **Integration defect:** behavior differs from the module contract under supported settings and a repeatable OTE case.
- **Security/privacy:** secret exposure, authorization concern, or sensitive-data handling; report privately through [`SECURITY.md`](SECURITY.md).

Maintainers may request a sanitized reproduction or additional details. Do not post customer-specific artifacts publicly to speed up diagnosis.

## Closing a report

When a workaround or fix is confirmed, note the tested module version and environment. Verify the affected operation in OTE and then use the operator's change-control process for production. A code fix does not automatically repair remote provider state or reverse completed transactions.