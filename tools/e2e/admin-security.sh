#!/bin/sh
# End-to-end security checks for the admin side, against a LOCAL test site only.
# Usage: admin-security.sh <base-url> <admin-user> <admin-pass> <editor-user> <editor-pass> <wp-run> <php-snippet-dir>
# Exits non-zero on the first failed check.
set -eu
BASE="$1"; AU="$2"; AP="$3"; EU="$4"; EP="$5"; WPRUN="$6"; SNIP="$7"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
PASS=0
ok()   { PASS=$((PASS+1)); echo "ok   $1"; }
fail() { echo "FAIL $1"; exit 1; }
has()  { case "$1" in *"$2"*) return 0;; esac; return 1; }

login() { # jar user pass
	curl -s -c "$1" -b "$1" "$BASE/wp-login.php" -o /dev/null
	curl -s -c "$1" -b "$1" -o /dev/null "$BASE/wp-login.php" --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&testcookie=1"
}
nonce_for() { # jar page action → nonce from that page's form
	curl -s -b "$1" "$BASE/wp-admin/admin.php?page=$2" \
	| tr '\n' ' ' | sed -n "s/.*name=\"hm_action\" value=\"$3\"><input type=\"hidden\" id=\"_wpnonce\" name=\"_wpnonce\" value=\"\([0-9a-f]*\)\".*/\1/p"
}
post() { # jar fields... → prints "status location"
	jar="$1"; shift
	curl -s -b "$jar" -o "$TMP/body" -w "%{http_code} %{redirect_url}" "$BASE/wp-admin/admin-post.php" "$@"
}

"$WPRUN" "$SNIP/cleanup.php" >/dev/null   # Start with no key, so the Connect step is on the page.
A="$TMP/admin.jar"; E="$TMP/editor.jar"
login "$A" "$AU" "$AP"; login "$E" "$EU" "$EP"

r=$(curl -s -b "$A" -o /dev/null -w "%{http_code}" "$BASE/wp-admin/admin-post.php?action=hostelmate_hbook&hm_action=connect")
[ "$r" = "405" ] && ok "GET is refused (405)" || fail "GET gave $r"

r=$(post "$A" -d "action=hostelmate_hbook&hm_action=connect&api_key=xxxxxxxxxxxxxxxxxxxxxxxxxxxx")
case "$r" in 403*) ok "missing nonce is refused (403)";; *) fail "missing nonce gave $r";; esac

r=$(post "$A" -d "action=hostelmate_hbook&hm_action=connect&_wpnonce=0123456789&api_key=xxxxxxxxxxxxxxxxxxxxxxxxxxxx")
case "$r" in 403*) ok "wrong nonce is refused (403)";; *) fail "wrong nonce gave $r";; esac

N=$(nonce_for "$A" hostelmate-hbook connect); [ -n "$N" ] || fail "no connect nonce on the Overview page"
r=$(post "$A" -d "action=hostelmate_hbook&hm_action=settings_everything&_wpnonce=$N")
case "$r" in 400*) ok "unknown action is refused (400)";; *) fail "unknown action gave $r";; esac

r=$(post "$A" -d "action=hostelmate_hbook&hm_action=remove_key&_wpnonce=$N&confirm=1")
case "$r" in 403*) ok "a nonce for another action is refused (403)";; *) fail "cross-action nonce gave $r";; esac

r=$(curl -s -b "$E" -o /dev/null -w "%{http_code}" "$BASE/wp-admin/admin.php?page=hostelmate-hbook")
[ "$r" = "403" ] && ok "editor can't open the page (403)" || fail "editor page gave $r"
r=$(post "$E" -d "action=hostelmate_hbook&hm_action=connect&_wpnonce=$N&api_key=xxxxxxxxxxxxxxxxxxxxxxxxxxxx")
case "$r" in 403*) ok "editor can't run actions, even with an admin's nonce (403)";; *) fail "editor action gave $r";; esac

r=$(post "$A" -d "action=hostelmate_hbook&hm_action=connect&_wpnonce=$N" --data-urlencode "api_key=short")
case "$r" in 302*hm_notice=connect_bad_key*) ok "malformed key: refused before any call";; *) fail "bad key gave $r";; esac

r=$(post "$A" -d "action=hostelmate_hbook&hm_action=connect&_wpnonce=$N" --data-urlencode "api_key=hmk_fake_$(date +%s)_0123456789abcdef")
case "$r" in 302*hm_notice=connect_refused*) ok "key HostelMate refuses (401): not saved";; *) fail "refused key gave $r";; esac
"$WPRUN" "$SNIP/key-absent.php" | grep -q "^key absent$" && ok "no key was stored" || fail "a refused key was stored"

body=$(curl -s -b "$A" "$BASE/wp-admin/admin.php?page=hostelmate-hbook&hm_notice=%3Cscript%3Ealert(1)%3C/script%3E,connected")
has "$body" "<script>alert(1)" && fail "notice code injected HTML" || ok "unknown notice codes are ignored"
has "$body" "Connected to HostelMate." && ok "known notice code is shown" || fail "known notice missing"

"$WPRUN" "$SNIP/evil-property.php" >/dev/null
body=$(curl -s -b "$A" "$BASE/wp-admin/admin.php?page=hostelmate-hbook")
has "$body" "<img src=x onerror" && fail "property name printed as HTML" || ok "property name with HTML is escaped"
has "$body" "&lt;img src=x onerror" && ok "escaped text is visible" || fail "escaped text missing"
"$WPRUN" "$SNIP/cleanup.php" >/dev/null

echo "all $PASS checks passed"
