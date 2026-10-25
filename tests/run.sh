#!/usr/bin/env bash
# Run every test file. No dependencies beyond the php binary.
set -u
cd "$(dirname "$0")"
status=0
for file in test-*.php; do
	printf '\n\033[1m=== %s ===\033[0m\n' "$file"
	php "$file" || status=1
done
printf '\n'
if [ "$status" -eq 0 ]; then
	printf '\033[32mAll test files passed.\033[0m\n'
else
	printf '\033[31mSome tests failed.\033[0m\n'
fi
exit "$status"
