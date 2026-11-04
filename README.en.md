# WebNIC Modules for HostBill

[English](README.en.md) | [Tiếng Việt](README.md)

Integrations between HostBill and WebNIC, delivered as three independent modules:

| Module | HostBill type | Version metadata | Scope |
| --- | --- | --- | --- |
| [`webnic_domain`](webnic_domain/README.md) ([Vietnamese](webnic_domain/README.vi.md)) | Domain registrar | `1.2026-11-04.1` | Register and manage domains through WebNIC REST v2. |
| [`webnic_dns`](webnic_dns/README.md) ([Vietnamese](webnic_dns/README.vi.md)) | Hosting / DNS | `1.1.2` | Basic and premium DNS, DNSSEC, forwarding, subscriptions, and client API. |
| [`webnic_ssl`](webnic_ssl/README.md) ([Vietnamese](webnic_ssl/README.vi.md)) | Hosting / SSL | `1.1.2` | Order and manage certificates, DCV validation, CSR, and downloads. |

Modules are installed independently; installing one does not install the others. See the [installation guide](docs/INSTALLATION.md) before deployment.

## Documentation

- [CI/CD, packaging, and module releases](docs/RELEASING.md) | [Tiếng Việt](docs/RELEASING.vi.md)
- [Installation and shared configuration](docs/INSTALLATION.md) | [Tiếng Việt](docs/INSTALLATION.vi.md)
- [Production operations and troubleshooting](docs/OPERATIONS.md) | [Tiếng Việt](docs/OPERATIONS.vi.md)
- [Support policy and ticket checklist](SUPPORT.md) | [Tiếng Việt](SUPPORT.vi.md)
- [Brand and attribution](BRAND.md) | [Tiếng Việt](BRAND.vi.md)
- [Vulnerability reporting](SECURITY.md) | [Tiếng Việt](SECURITY.vi.md)
- [Contributing and validation](CONTRIBUTING.md) | [Tiếng Việt](CONTRIBUTING.vi.md)
- [MIT License](LICENSE)

## Requirements and notes

- A compatible HostBill module type and an authorized WebNIC reseller/API account are required.
- The minimum HostBill version, overall PHP version, and complete compatibility matrix are not specified in this repository; verify them in staging before production deployment.
- Use WebNIC OTE for testing and the live WebNIC API for production. Start with OTE.
- DNS and SSL API routes must be enabled in HostBill's User API system. Domain does not currently declare a separate API route manifest.
- HostBill, WebNIC APIs/services, and third-party marks are not covered by this repository's MIT license.

## Support

When reporting a problem, follow the [support policy](SUPPORT.md). Never send passwords, bearer tokens, private keys, identifying CSR data, or customer personal data.
