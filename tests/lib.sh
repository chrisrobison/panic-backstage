# Shared helpers for backstage sanity tests. Source this from each test:
#
#   . "$(dirname "$0")/lib.sh"
#
# Provides:
#   $ROOT_DIR        repo root (parent of tests/)
#   $BASE_URL        site root, e.g. https://panicbooking.com/backstage
#   $API_URL         $BASE_URL/api
#   $STATE_FILE      path to the shared kv state file
#   $MAIL_DIR        $ROOT_DIR/storage/mail (magic-link .eml files land here)
#
#   state_set KEY VAL          persist a value for later test scripts
#   fail "msg"                 print to stderr and exit 1
#   require_cmd cmd ...        bail if a command is missing
#   http_get  PATH             curl GET → echoes HTTP status; body in $RESP_BODY
#   http_post PATH JSON        curl POST with JSON body
#   json_get  KEY < json       extract a (possibly nested with dots) value
#   assert_status WANT GOT     fail if codes differ, dumping the response body
#
# Tests run with `set -euo pipefail` so unhandled failures abort the script,
# which the runner then surfaces.

set -euo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$TESTS_DIR")"
STATE_FILE="$TESTS_DIR/.state"
MAIL_DIR="$ROOT_DIR/storage/mail"

# Load .env (best-effort; missing file is fine if TEST_BASE_URL is supplied).
if [ -f "$ROOT_DIR/.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$ROOT_DIR/.env"
    set +a
fi

# Load prior test state (tokens, picked email, …) if present.
if [ -f "$STATE_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    . "$STATE_FILE"
    set +a
fi

BASE_URL="${TEST_BASE_URL:-${APP_URL:-http://localhost}}"
BASE_URL="${BASE_URL%/}"
API_URL="$BASE_URL/api"

# Single response-body scratch file shared across calls in one script.
RESP_BODY="$(mktemp -t backstage-resp.XXXXXX)"
trap 'rm -f "$RESP_BODY"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

require_cmd() {
    for c in "$@"; do
        command -v "$c" >/dev/null 2>&1 || fail "required command not found: $c"
    done
}

state_set() {
    local key="$1" val="$2"
    mkdir -p "$(dirname "$STATE_FILE")"
    touch "$STATE_FILE"
    # Drop any existing entry, then append.
    grep -v "^${key}=" "$STATE_FILE" > "$STATE_FILE.tmp" 2>/dev/null || true
    mv "$STATE_FILE.tmp" "$STATE_FILE"
    printf '%s=%s\n' "$key" "$val" >> "$STATE_FILE"
}

# Common curl options: silent, follow redirects off (we want raw codes),
# fail soft so we can inspect 4xx/5xx bodies ourselves.
_curl() {
    curl -sS -o "$RESP_BODY" -w '%{http_code}' --max-time 20 "$@"
}

_auth_header() {
    if [ -n "${ACCESS_TOKEN:-}" ]; then
        printf 'Authorization: Bearer %s' "$ACCESS_TOKEN"
    fi
}

http_get() {
    local path="$1"
    local auth
    auth="$(_auth_header)"
    if [ -n "$auth" ]; then
        _curl -H "$auth" "$BASE_URL$path"
    else
        _curl "$BASE_URL$path"
    fi
}

http_post() {
    local path="$1" json="${2:-}"
    local auth
    auth="$(_auth_header)"
    if [ -n "$auth" ]; then
        _curl -X POST -H "Content-Type: application/json" -H "$auth" \
            --data "$json" "$API_URL$path"
    else
        _curl -X POST -H "Content-Type: application/json" \
            --data "$json" "$API_URL$path"
    fi
}

# Extract a value from a JSON document on stdin. Supports dotted paths
# (e.g. "user.email"). Echoes the raw scalar, or compact JSON for arrays/objects.
# Exit 1 if the key is missing.
json_get() {
    php -r '
        $in   = stream_get_contents(STDIN);
        $data = json_decode($in, true);
        if (!is_array($data)) { fwrite(STDERR, "not json\n"); exit(1); }
        $cur = $data;
        foreach (explode(".", $argv[1]) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) exit(1);
            $cur = $cur[$k];
        }
        if (is_array($cur))      echo json_encode($cur);
        elseif (is_bool($cur))   echo $cur ? "true" : "false";
        elseif ($cur === null)   echo "null";
        else                     echo (string) $cur;
    ' "$1"
}

assert_status() {
    local want="$1" got="$2"
    if [ "$want" != "$got" ]; then
        printf 'expected HTTP %s, got %s\n' "$want" "$got" >&2
        printf '%s\n' 'response body:' >&2
        sed -n '1,40p' "$RESP_BODY" >&2 || true
        exit 1
    fi
}

# Sanitised mailbox filename for an email address, matching Mailer::writeToFile.
mail_filename_for() {
    printf '%s' "$1" | sed 's/[^a-zA-Z0-9@._-]/_/g'
}
