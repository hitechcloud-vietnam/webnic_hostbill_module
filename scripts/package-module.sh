#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 || $# -gt 3 ]]; then
    printf 'Usage: %s <module> <output-dir> [expected-version]\n' "$0" >&2
    exit 2
fi

module="$1"
output_dir="$2"
expected_version="${3:-}"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

case "$module" in
    webnic_domain)
        hostbill_type="Domain"
        version_file="$repo_root/webnic_domain/class.webnic_domain.php"
        ;;
    webnic_dns)
        hostbill_type="Hosting"
        version_file="$repo_root/webnic_dns/class.webnic_dns.php"
        ;;
    webnic_ssl)
        hostbill_type="Hosting"
        version_file="$repo_root/webnic_ssl/class.webnic_ssl.php"
        ;;
    *)
        printf 'Unknown module: %s\n' "$module" >&2
        exit 2
        ;;
esac

version="$(python3 - "$version_file" <<'PY'
import re
import sys
from pathlib import Path

source = Path(sys.argv[1]).read_text()
match = re.search(r"protected\s+\$version\s*=\s*['\"]([^'\"]+)['\"]", source)
if not match:
    raise SystemExit(f"Could not read module version from {sys.argv[1]}")
print(match.group(1))
PY
)"

if [[ -n "$expected_version" && "$version" != "$expected_version" ]]; then
    printf 'Tag version %s does not match %s version %s\n' "$expected_version" "$module" "$version" >&2
    exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
    printf 'The zip utility is required to package modules.\n' >&2
    exit 1
fi

stage_dir="$(mktemp -d)"
trap 'rm -rf "$stage_dir"' EXIT
package_path="$stage_dir/includes/modules/$hostbill_type/$module"
mkdir -p "$(dirname "$package_path")"
cp -a "$repo_root/$module" "$package_path"

mkdir -p "$repo_root/$output_dir"
archive="$repo_root/$output_dir/$module-$version.zip"
rm -f "$archive"
(cd "$stage_dir" && zip -qr "$archive" "includes/modules/$hostbill_type/$module")

printf '%s\n' "$archive"