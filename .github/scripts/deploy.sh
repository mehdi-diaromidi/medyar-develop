#!/usr/bin/env bash
# Uploads tracked wp-content files to the host over FTPS / FTP / SFTP using lftp.
# Remote files are NEVER deleted: files removed from Git are only reported.
# The last deployed commit is stored on the host in $MARKER_NAME (inside FTP_SERVER_DIR)
# so each run uploads only what changed since the previous successful deploy.
set -Eeuo pipefail

DEPLOY_ROOT="wp-content"
MARKER_NAME=".deploy-revision"
SUMMARY_FILE_LIMIT=300

fail() {
	echo "::error title=Deploy failed::$*"
	exit 1
}
trap 'echo "::error title=Deploy failed::Unexpected error on line $LINENO (exit code $?)."' ERR

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

summary() {
	if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
		printf '%s\n' "$@" >>"$GITHUB_STEP_SUMMARY"
	fi
}

count_entries() {
	tr -cd '\0' <"$1" | wc -c | tr -d ' '
}

normalize_fp() {
	tr -d ': \t\r\n' <<<"$1" | tr '[:lower:]' '[:upper:]'
}

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
[[ -n "${FTP_SERVER:-}" ]] || fail "Secret FTP_SERVER is not set (Settings > Secrets and variables > Actions)."
[[ -n "${FTP_USERNAME:-}" ]] || fail "Secret FTP_USERNAME is not set."
[[ -n "${FTP_PASSWORD:-}" ]] || fail "Secret FTP_PASSWORD is not set."
[[ "$FTP_USERNAME" != *'"'* && "$FTP_USERNAME" != *','* ]] || fail "FTP_USERNAME must not contain double quotes or commas."

HOST="$(tr -d '[:space:]' <<<"$FTP_SERVER")"
HOST="${HOST#*://}"
HOST="${HOST%%/*}"

PROTOCOL="$(tr '[:upper:]' '[:lower:]' <<<"${FTP_PROTOCOL:-ftps}")"
case "$PROTOCOL" in
	ftps | ftp) DEFAULT_PORT=21 ;;
	sftp) DEFAULT_PORT=22 ;;
	*) fail "Unsupported FTP_PROTOCOL '$PROTOCOL'. Use ftps, ftp or sftp." ;;
esac

PORT="${FTP_PORT:-$DEFAULT_PORT}"
[[ "$PORT" =~ ^[0-9]+$ ]] || fail "FTP_PORT must be a number (got '$PORT')."

SERVER_DIR="${FTP_SERVER_DIR:-/}"
[[ "$SERVER_DIR" == /* ]] || SERVER_DIR="/$SERVER_DIR"
SERVER_DIR="${SERVER_DIR%/}"
[[ -n "$SERVER_DIR" ]] || SERVER_DIR="/"
[[ "$SERVER_DIR" != *'"'* ]] || fail "FTP_SERVER_DIR must not contain double quotes."

PROTECT_DATA="$(tr '[:upper:]' '[:lower:]' <<<"${FTP_TLS_PROTECT_DATA:-yes}")"
[[ "$PROTECT_DATA" == "yes" || "$PROTECT_DATA" == "no" ]] || fail "FTP_TLS_PROTECT_DATA must be yes or no."

# Anything other than an explicit "false" is treated as a dry run.
if [[ "${DRY_RUN:-true}" == "false" ]]; then
	DRY_RUN=false
else
	DRY_RUN=true
fi

LFTP_SETTINGS=(
	"set cmd:fail-exit yes"
	"set net:timeout 30"
	"set net:max-retries 3"
	"set net:reconnect-interval-base 5"
	"set net:reconnect-interval-max 30"
	"set xfer:clobber yes"
	"set xfer:use-temp-file yes"
)

case "$PROTOCOL" in
	ftps)
		URL="ftp://$HOST"
		LFTP_SETTINGS+=(
			"set ftp:passive-mode yes"
			"set ftp:ssl-allow yes"
			"set ftp:ssl-force yes"
			"set ftp:ssl-protect-data $PROTECT_DATA"
			"set ftp:ssl-protect-list $PROTECT_DATA"
		)
		if [[ -n "${FTP_TLS_FINGERPRINT:-}" ]]; then
			cert_fp="$(timeout 30 openssl s_client -starttls ftp -connect "$HOST:$PORT" -servername "$HOST" </dev/null 2>/dev/null |
				openssl x509 -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2)" || true
			[[ -n "$cert_fp" ]] || fail "Could not read the FTPS certificate from $HOST:$PORT (server unreachable or AUTH TLS not supported)."
			if [[ "$(normalize_fp "$cert_fp")" != "$(normalize_fp "$FTP_TLS_FINGERPRINT")" ]]; then
				fail "FTPS certificate fingerprint mismatch. Expected $FTP_TLS_FINGERPRINT but the server presented $cert_fp. If the host renewed its certificate, verify the new fingerprint and update the FTP_TLS_FINGERPRINT variable; otherwise treat this as a possible interception."
			fi
			echo "FTPS certificate matches pinned SHA-256 fingerprint."
			LFTP_SETTINGS+=("set ssl:verify-certificate no")
		else
			LFTP_SETTINGS+=("set ssl:verify-certificate yes")
		fi
		;;
	ftp)
		echo "::warning title=Insecure protocol::FTP_PROTOCOL=ftp sends the password unencrypted. Prefer ftps."
		URL="ftp://$HOST"
		LFTP_SETTINGS+=("set ftp:passive-mode yes" "set ftp:ssl-allow no")
		;;
	sftp)
		[[ -n "${SFTP_KNOWN_HOSTS:-}" ]] || fail "SFTP requires the SFTP_KNOWN_HOSTS variable (output of: ssh-keyscan -p $PORT $HOST)."
		mkdir -p "$HOME/.ssh"
		printf '%s\n' "$SFTP_KNOWN_HOSTS" >>"$HOME/.ssh/known_hosts"
		chmod 600 "$HOME/.ssh/known_hosts"
		URL="sftp://$HOST"
		LFTP_SETTINGS+=("set sftp:auto-confirm no")
		;;
esac

export LFTP_PASSWORD="$FTP_PASSWORD"

lftp_run() {
	local script
	script="$(mktemp -p "$WORK" lftp.XXXXXX)"
	{
		printf '%s\n' "${LFTP_SETTINGS[@]}"
		printf 'open -u "%s" --env-password -p %s "%s"\n' "$FTP_USERNAME" "$PORT" "$URL"
		printf '%s\n' "$@"
	} >"$script"
	lftp -f "$script"
}

cd "$(git rev-parse --show-toplevel)"
HEAD_REV="$(git rev-parse HEAD)"

echo "Protocol:    $PROTOCOL (port $PORT)"
echo "Remote dir:  $SERVER_DIR"
echo "Commit:      $HEAD_REV"
echo "Dry run:     $DRY_RUN"

# ---------------------------------------------------------------------------
# Connection check + destination sanity check
# ---------------------------------------------------------------------------
echo "::group::Connection check"
if ! listing="$(lftp_run "cd \"$SERVER_DIR\"" "cls -1")"; then
	echo "::endgroup::"
	fail "Could not connect, log in, or open '$SERVER_DIR' on $HOST:$PORT. Check FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_PROTOCOL, FTP_PORT and FTP_SERVER_DIR (see lftp output above)."
fi
echo "$listing"
echo "::endgroup::"

grep -Eq '(^|/)wp-content/?$' <<<"$listing" ||
	fail "'$SERVER_DIR' has no wp-content directory, so it does not look like the WordPress root. Fix the FTP_SERVER_DIR variable."

# ---------------------------------------------------------------------------
# Decide what to upload
# ---------------------------------------------------------------------------
prev_rev="$(lftp_run "cd \"$SERVER_DIR\"" "cat $MARKER_NAME" 2>/dev/null | tr -d '[:space:]')" || prev_rev=""

if [[ "$prev_rev" =~ ^[0-9a-f]{40}$ ]] && git cat-file -e "${prev_rev}^{commit}" 2>/dev/null; then
	MODE="incremental"
	echo "Last deployed commit on host: $prev_rev"
	git diff --name-only --no-renames -z --diff-filter=ACMT "$prev_rev" "$HEAD_REV" -- "$DEPLOY_ROOT" >"$WORK/upload.raw"
	git diff --name-only --no-renames -z --diff-filter=D "$prev_rev" "$HEAD_REV" -- "$DEPLOY_ROOT" >"$WORK/deleted.lst"
else
	MODE="full"
	if [[ -n "$prev_rev" ]]; then
		echo "::warning title=Full upload::Host revision '$prev_rev' is not in this repository's history. Uploading all tracked files."
	else
		echo "No $MARKER_NAME on host yet: uploading all tracked files (nothing on the host is deleted)."
	fi
	git ls-files -z -- "$DEPLOY_ROOT" >"$WORK/upload.raw"
	: >"$WORK/deleted.lst"
fi

grep -zvE '(^|/)\.git(ignore|attributes|keep)$' "$WORK/upload.raw" >"$WORK/upload.lst" || true

upload_count="$(count_entries "$WORK/upload.lst")"
deleted_count="$(count_entries "$WORK/deleted.lst")"

echo "Mode: $MODE | files to upload: $upload_count | removed from Git (kept on host): $deleted_count"

echo "::group::Files to upload ($upload_count)"
tr '\0' '\n' <"$WORK/upload.lst"
echo "::endgroup::"

if [[ "$deleted_count" -gt 0 ]]; then
	echo "::warning title=Files removed from Git::$deleted_count file(s) were deleted in Git but are NOT deleted on the host. Remove them manually if needed (listed in the log and job summary)."
	echo "::group::Removed from Git, left on host ($deleted_count)"
	tr '\0' '\n' <"$WORK/deleted.lst"
	echo "::endgroup::"
fi

if [[ "$DRY_RUN" == "true" ]]; then
	summary "## Deploy (DRY RUN — nothing uploaded)"
else
	summary "## Deploy"
fi
summary "" \
	"| | |" "|---|---|" \
	"| Commit | \`$HEAD_REV\` |" \
	"| Mode | $MODE |" \
	"| Protocol | $PROTOCOL :$PORT |" \
	"| Remote dir | \`$SERVER_DIR\` |" \
	"| Files to upload | $upload_count |" \
	"| Removed from Git (kept on host) | $deleted_count |" ""

if [[ "$upload_count" -gt 0 ]]; then
	summary "<details><summary>Files to upload</summary>" "" '```'
	tr '\0' '\n' <"$WORK/upload.lst" | head -n "$SUMMARY_FILE_LIMIT" | while IFS= read -r line; do summary "$line"; done
	[[ "$upload_count" -le "$SUMMARY_FILE_LIMIT" ]] || summary "... and $((upload_count - SUMMARY_FILE_LIMIT)) more (see job log)"
	summary '```' "</details>" ""
fi
if [[ "$deleted_count" -gt 0 ]]; then
	summary "<details><summary>Removed from Git, left on host</summary>" "" '```'
	tr '\0' '\n' <"$WORK/deleted.lst" | while IFS= read -r line; do summary "$line"; done
	summary '```' "</details>" ""
fi

# ---------------------------------------------------------------------------
# Stage + upload (mirror without --delete never removes remote files)
# ---------------------------------------------------------------------------
if [[ "$upload_count" -gt 0 ]]; then
	STAGE="$WORK/stage"
	mkdir -p "$STAGE"
	xargs -0 -r cp --parents -t "$STAGE" -- <"$WORK/upload.lst"

	mirror_cmd="mirror --reverse --transfer-all --no-perms --parallel=4 --verbose"
	if [[ "$DRY_RUN" == "true" ]]; then
		mirror_cmd+=" --dry-run"
	fi

	echo "::group::lftp mirror$([[ "$DRY_RUN" == "true" ]] && echo ' (dry run)')"
	if ! lftp_run "$mirror_cmd \"$STAGE\" \"$SERVER_DIR\""; then
		echo "::endgroup::"
		fail "Upload failed (see lftp output above). The host revision marker was NOT updated, so the next run retries these files."
	fi
	echo "::endgroup::"
fi

if [[ "$DRY_RUN" == "true" ]]; then
	echo "DRY RUN finished: nothing was uploaded and $MARKER_NAME was not changed."
	echo "To deploy for real, set the repository variable DEPLOY_DRY_RUN=false or run the workflow manually with 'Dry run' unchecked."
	exit 0
fi

if [[ "$prev_rev" != "$HEAD_REV" ]]; then
	printf '%s\n' "$HEAD_REV" >"$WORK/$MARKER_NAME"
	lftp_run "cd \"$SERVER_DIR\"" "put \"$WORK/$MARKER_NAME\" -o \"$MARKER_NAME\"" ||
		fail "Files were uploaded but $MARKER_NAME could not be written; the next run will re-upload."
fi

echo "Deploy finished: $upload_count file(s) uploaded, host now at $HEAD_REV."
summary "**Result:** $upload_count file(s) uploaded; host marked at \`$HEAD_REV\`."
