# Installation and Shared Configuration

## Before installing

1. Confirm that the target HostBill installation supports the module type: Domain for `webnic_domain`, Hosting/DNS for `webnic_dns`, and Hosting/SSL for `webnic_ssl`.
2. Confirm compatibility with the actual HostBill and PHP versions in a staging environment. This repository does not specify a minimum HostBill version or a complete PHP compatibility matrix.
3. Obtain a WebNIC reseller/API account and access to the OTE environment. Confirm API permissions and product availability with WebNIC.
4. Back up the HostBill database and the existing module directories.

## Copy module files

Copy each selected module directory, preserving its internal structure, into the matching HostBill module directory:

| Repository directory | HostBill destination |
| --- | --- |
| `webnic_domain/` | `includes/modules/Domain/webnic_domain/` |
| `webnic_dns/` | `includes/modules/Hosting/webnic_dns/` |
| `webnic_ssl/` | `includes/modules/Hosting/webnic_ssl/` |

Install only the modules you plan to use. The DNS and SSL API manifests declare Hosting module paths. Confirm the paths against the target HostBill release and its module installation instructions.

## Register and configure

1. Open HostBill module/app management and discover the installed module. Add the WebNIC reseller/API username and password to its connection settings.
2. Enable **OTE Environment** for initial testing. The modules use `oteapi.webnic.cc` in OTE and `api.webnic.cc` for live requests.
3. Test the connection and verify representative workflows using test domains, certificates, and DNS zones.
4. Configure the product-level settings in the matching module guide, then connect a test HostBill product to the module.
5. Enable the DNS/SSL User API manifests in HostBill's User API system only if those routes are needed. Keep authentication and client/service authorization enabled.
6. After successful OTE testing, configure live credentials deliberately and verify a non-destructive live operation before serving orders.

For a detailed pre-production checklist and safe retry guidance, see [Production Operations](OPERATIONS.md).

## Domain contact table

The domain module includes [`webnic_domain/sql.sql`](../webnic_domain/sql.sql), which creates the local `hb_domain_contacts` table with `CREATE TABLE IF NOT EXISTS`. Review the SQL and back up the database before applying it to the HostBill database if the table is not already present. The table stores domain contact and identity information; restrict database and backup access accordingly. Do not drop or replace an existing table during an upgrade.

## Upgrade and rollback

- Back up module files and the database before replacing files.
- Review release changes and rerun OTE workflows that cover the affected module.
- Preserve existing HostBill configuration and domain contact data.
- For rollback, restore the previous module files and database backup using the site's normal recovery procedure. Do not assume a code rollback reverses remote WebNIC orders or configuration changes.

## Uninstallation

Disable products and API routes, then follow HostBill's module removal procedure. Removing module files does not necessarily cancel remote WebNIC services or orders. The domain contact table may contain data used by other integrations; do not drop it without confirming ownership, dependencies, retention obligations, and a verified backup.

## Support and security

Use [SUPPORT.md](../SUPPORT.md) to choose the right support channel and prepare a sanitized report. For suspected credential disclosure or a vulnerability, follow [SECURITY.md](../SECURITY.md) and do not open a public issue. Keep all logs, database backups, and configuration exports access-controlled; they may contain customer data or secrets.