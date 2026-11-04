# Contributing

[English](CONTRIBUTING.md) | [Tiếng Việt](CONTRIBUTING.vi.md)

## Scope

Keep changes focused on the relevant module or documentation. Domain, DNS, and SSL are separate HostBill modules; avoid introducing runtime dependencies between them unless the change explicitly requires it.

## Before submitting

1. Describe the affected module and the behavior being changed.
2. Test against WebNIC OTE wherever the API workflow permits; do not run destructive tests against live customer services.
3. Run PHP syntax checks on changed PHP files with `php -l` and validate changed JSON manifests with `python -m json.tool <file>`. Documentation-only changes do not require PHP checks.
4. Review the diff for credentials, customer data, generated artifacts, and unrelated changes.
5. Update the relevant module documentation when configuration, behavior, API routes, or operational requirements change.

## Pull requests and issues

Include reproduction steps, expected and actual behavior, the module version, and relevant sanitized logs. State which OTE/live environment was used. Never attach credentials, bearer tokens, customer contact data, or certificate private keys.

Contributions to repository software and documentation are subject to the MIT license in [`LICENSE`](LICENSE). Third-party platform terms and marks remain governed by their respective owners.