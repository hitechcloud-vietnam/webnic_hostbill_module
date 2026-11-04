# Production Operations Runbook

[English](OPERATIONS.md) | [Tiếng Việt](OPERATIONS.vi.md)

This runbook covers routine operations for WebNIC Domain, DNS, and SSL modules installed in HostBill. Provider-side state in WebNIC is authoritative for completed orders and remote DNS/domain/certificate operations; HostBill state can be delayed or incomplete.

## Production readiness

Before enabling customer orders, confirm all of the following:

- [ ] Correct module type and installation path; module visible and connected in HostBill.
- [ ] WebNIC OTE connection test succeeds with the intended test account.
- [ ] Product settings have been reviewed by the service owner; market, DNS tier, product key, allowed DCV method, and limits are intentional.
- [ ] Representative OTE workflows pass for each enabled service and product.
- [ ] User API routes are enabled only when needed and client/service authorization is verified with both an allowed and a denied test account.
- [ ] Live credentials and live/OTE selection have been independently verified before production enablement.
- [ ] Database and module backups exist, restore access is controlled, and recovery has been rehearsed.
- [ ] Admin, API, database, cache, and log access is limited to named operational roles.
- [ ] Production debug logging and log retention have been reviewed, especially the DNS token/API log behavior in [`SECURITY.md`](../SECURITY.md).
- [ ] Operators know how to contact WebNIC, HostBill, the hosting provider, and the repository maintainers.

## Environment and connection checks

1. Confirm the app connection username and secret are the intended WebNIC API credentials. Never copy credential values into the run log.
2. Confirm **OTE Environment** is enabled in staging and disabled only for an intentional live operation.
3. Confirm outbound HTTPS and DNS resolution for the selected API host: `oteapi.webnic.cc` or `api.webnic.cc`.
4. Use the module's HostBill connection test. Record timestamp, environment, result, and sanitized provider error code.
5. If authentication fails, validate account status and permissions with WebNIC before repeatedly resetting credentials or changing module state.

## Module-specific smoke tests

### Domain

- Run a lookup on a test domain/TLD and confirm market and pricing behavior.
- Verify a test registration or transfer using a valid synthetic registrant and provider-approved test conditions.
- Check nameserver/contact synchronization and status refresh.
- Test DNSSEC, lock/hold, renewal, restore, or other destructive/stateful features only when the OTE account and test domain support them.
- Test registrar transfer-in status reads and reseller-transfer submission separately. Submit only an approved OTE test handover; a reseller handover is not the same workflow as transfer-away approval.
- Distinguish registry suspension (`domainSuspend()`) from hold nameservers (`Suspended()`) and verify the provider's resulting status before restoring service.
- Before using `toggleProxyService()`, confirm TLD eligibility, current provider subscription/pricing, and whether the intended action is subscribe or unsubscribe. Do not treat it as the separate WHOIS privacy switch.
- Check glue-host availability with the intended extension before registering a nameserver host.
- Confirm whether the `hb_domain_contacts` table exists before applying the install SQL; preserve existing table contents.

### DNS

- Create or import a test zone and confirm the resulting zone belongs to the expected basic or premium tier.
- Add, read, update, and remove a test record; verify values and TTL against WebNIC.
- `deleteZoneRecordUnified()` is an internal helper that removes all values matching a zone/type/name pair. It is not the ordinary client/admin delete path; use a disposable zone and verify the exact match before testing it.
- Test DNSSEC and forwarding only on a test zone. Confirm premium subscription and auto-renewal state before changing it.
- Check user API route registration and verify an owning client can access its service while a different client is denied.

### SSL

- Select a test product and supported DCV method; verify CSR, contacts, SAN rules, and organization requirements.
- Complete a test DNS, HTTP/file, or email validation workflow as appropriate.
- Verify status synchronization and download only after issuance.
- Keep backend helpers such as reseller-wide order search and provider contact/organization changes in trusted operator workflows; verify their scope and shared-data impact before production use.
- Treat renewal, reissue, and termination as separate lifecycle operations; verify WebNIC order state before repeating an operation after a timeout.

## Routine operations

- Review failed module actions, HostBill API errors, and provider-side notifications using restricted log access.
- Track changes to HostBill/PHP, module files, product settings, credentials, and network policy so failures can be correlated with deployments.
- Periodically verify backups and recovery access. Protect database exports and logs at the same sensitivity as production customer data.
- Review service access and API credentials when staff, integrations, or responsibilities change.
- Do not enable broad debug logging as a standing monitoring solution. Use a short approved diagnostic window and remove/redact collected files afterward.

The repository does not define built-in metrics, alert thresholds, log paths for every HostBill installation, retention periods, or a monitoring integration. Configure these through the HostBill and infrastructure facilities available in the specific deployment.

## Safe handling of failures

For a timeout, HTTP 5xx, or lost response after a mutating action:

1. Do not immediately repeat the action.
2. Record the time, module, action, service/order reference, environment, and sanitized error.
3. Query HostBill and WebNIC to determine whether the remote operation completed.
4. If provider state is unclear, contact WebNIC before retrying or changing local records.
5. Reconcile HostBill and provider state using the documented module workflow; preserve evidence and notify affected operators/customers as required.

This is especially important for domain registration/transfer/renewal/restore/deletion, registry suspension, proxy subscription changes, DNS record deletion by type/name and DNS subscription changes, and SSL order/reissue/renewal/termination or contact/organization updates.

## Upgrade and rollback procedure

1. Review release notes/diff and identify changed modules, API manifests, database expectations, and product settings.
2. Back up files and database; record the current module versions and relevant non-secret settings.
3. Install and exercise the changed module in staging with OTE.
4. Schedule production changes, notify operators, and use the smallest possible deployment scope.
5. Verify connection and one controlled, non-destructive operation after deployment.
6. If rollback is needed, restore the previous files and follow the database recovery plan. Do not restore an old database over new customer activity without an explicit recovery decision.
7. Check WebNIC state after rollback. Remote operations are not undone by reverting module files.

## Incident handoff

For a production incident, use [`SUPPORT.md`](../SUPPORT.md) for issue details and [`SECURITY.md`](../SECURITY.md) for secret exposure or suspected vulnerabilities. Do not attach raw debug logs: DNS debug output may contain bearer-token responses and request/response data. Include only sanitized references and preserve the original evidence in a restricted incident store.