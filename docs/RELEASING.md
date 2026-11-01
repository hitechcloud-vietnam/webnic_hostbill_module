# CI, Packages, and Releases

## Package layout

Each module is built and published independently. The ZIP contains the module under its HostBill-relative destination, with no extra archive wrapper:

| ZIP asset | First-level module path inside ZIP | Extract at |
| --- | --- | --- |
| `webnic_domain-<version>.zip` | `includes/modules/Domain/webnic_domain/` | HostBill installation root |
| `webnic_dns-<version>.zip` | `includes/modules/Hosting/webnic_dns/` | HostBill installation root |
| `webnic_ssl-<version>.zip` | `includes/modules/Hosting/webnic_ssl/` | HostBill installation root |

For example, extracting the Domain archive at the HostBill root places the files under `includes/modules/Domain/webnic_domain/`. Extract DNS and SSL archives at the same root; they land under their respective `Hosting/` paths. The archive includes the module's own README and MIT license.

## Continuous integration

`.github/workflows/ci.yml` runs for pull requests and pushes to `main` (and can be started manually). It:

- checks PHP syntax for all three modules using PHP 8.3;
- parses JSON files/manifests;
- builds all three ZIPs and verifies their entries use only the correct HostBill module path;
- uploads the ZIPs as the `webnic-hostbill-module-zips` workflow artifact, retained for 30 days.

The workflow performs static validation and packaging; it does not connect to HostBill or WebNIC and is not an end-to-end integration test.

## Publishing a module

The release workflow listens for one module-specific tag:

```text
webnic_domain-v<exact module version>
webnic_dns-v<exact module version>
webnic_ssl-v<exact module version>
```

Examples for the current source metadata:

```text
webnic_domain-v1.2026-11-02.2
webnic_dns-v1.1.0
webnic_ssl-v1.1.0
```

Before tagging, update the selected module's `$version` in its main PHP class, review the module README/changelog and license, and merge the change to the default branch. Create and push a tag that exactly matches that version:

```sh
git tag webnic_dns-v1.1.1
git push origin webnic_dns-v1.1.1
```

Replace the example with the new module and version. The tag must point at the reviewed commit. The workflow verifies the tag's module and version against the source, runs PHP and JSON checks, builds and validates one module ZIP, then creates a GitHub Release with that ZIP attached. A DNS release does not publish new Domain or SSL assets.

Do not reuse or move a published tag. Publish a new version instead. The workflow requires the repository's `GITHUB_TOKEN` to have release-write permission; the workflow declares `contents: write`. Repository/organization Actions policy must allow the referenced GitHub Actions and GitHub CLI.

## Download and install

Download the desired module asset from its GitHub Release, back up the current module and database, then extract the ZIP at the HostBill installation root. Confirm the resulting path matches the table above before enabling/upgrading the module. Follow [installation and upgrade guidance](INSTALLATION.md), then test the module using WebNIC OTE before production operations.

## Local packaging

The same packager used by CI can be run from the repository root:

```sh
./scripts/package-module.sh webnic_domain dist
./scripts/package-module.sh webnic_dns dist
./scripts/package-module.sh webnic_ssl dist
```

It reads the version from the module class and writes `dist/<module>-<version>.zip`. An optional third argument enforces an expected version, as the release workflow does. Inspect an archive with `unzip -l dist/<module>-<version>.zip` before distributing it manually.