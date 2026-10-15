#!/usr/bin/env bash
set -uo pipefail
# Quét cú pháp PHP trong thư mục và trả mã thoát theo kết quả.
if [[ $# -ne 1 ]]; then echo 'Usage: scan-source.sh DIRECTORY' >&2; exit 2; fi
root="$1"
if [[ ! -d "$root" || ! -r "$root" ]]; then
  echo "ERROR: directory is missing or unreadable: $root" >&2
  exit 2
fi
if ! command -v php >/dev/null 2>&1; then
  echo 'ERROR: php is required' >&2
  exit 2
fi
if ! command -v find >/dev/null 2>&1; then
  echo 'ERROR: find is required' >&2
  exit 2
fi
list="$(mktemp)" || exit 2
trap 'rm -f -- "$list"' EXIT
if ! find "$root" -type f -name '*.php' -print0 > "$list"; then exit 2; fi
total=0; passed=0; failed=0
while IFS= read -r -d '' file; do
  total=$((total + 1))
  if php -l "$file"; then
    passed=$((passed + 1))
    printf 'PASS %s\n' "$file"
  else
    failed=$((failed + 1))
    printf 'FAIL %s\n' "$file" >&2
  fi
done < "$list"
printf 'TOTAL=%s PASS=%s FAIL=%s\n' "$total" "$passed" "$failed"
if [[ "$total" -eq 0 ]]; then exit 2; fi
if [[ "$failed" -gt 0 ]]; then exit 1; fi
exit 0
