# Bounded retry for flaky Composer / packagist fetches. Sourced by
# .github/workflows/ci.yml (phpunit and mcp jobs).
retry() {
  rc=0
  for n in 1 2 3; do
    "$@" && return 0
    rc=$?
    if [ "$n" -lt 3 ]; then
      echo "::warning::attempt $n of 3 failed (exit $rc): $* - retrying"
      sleep "$((15 * n))"
    fi
  done
  return "$rc"
}
