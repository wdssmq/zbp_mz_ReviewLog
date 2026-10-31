#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

source "$SCRIPT_DIR/.env"

if [[ -z "${ABOUT_JS_URL:-}" ]]; then
  echo "ABOUT_JS_URL is not set in ci/.env" >&2
  exit 1
fi

if [[ -n "${APP_ROOT:-}" ]]; then
  if [[ "$APP_ROOT" = /* ]]; then
    APP_DIR="$APP_ROOT"
  elif [[ "$APP_ROOT" =~ ^[A-Za-z]:[/\\] ]]; then
    APP_DIR="${APP_ROOT//\\//}"
  else
    APP_DIR="$ROOT_DIR/$APP_ROOT"
  fi

  if [[ ! -d "$APP_DIR" ]]; then
    echo "Configured APP_ROOT does not exist: $APP_DIR" >&2
    exit 1
  fi
else
  MANIFESTS=()
  while IFS= read -r manifest; do
    MANIFESTS+=("$manifest")
  done < <(
    find "$ROOT_DIR" -maxdepth 3 -type f \
      \( -name plugin.xml -o -name theme.xml \) \
      -not -path "$ROOT_DIR/.git/*" \
      -not -path "$ROOT_DIR/vendor/*" \
      -print | sort
  )

  if [[ "${#MANIFESTS[@]}" -eq 0 ]]; then
    echo "Could not find plugin.xml or theme.xml under $ROOT_DIR; set APP_ROOT in ci/.env" >&2
    exit 1
  fi

  APP_DIR=""
  CANDIDATE_DIRS=()
  MATCHING_DIR=""

  for manifest in "${MANIFESTS[@]}"; do
    manifest_dir="$(dirname "$manifest")"
    manifest_id="$(sed -nE 's/^[[:space:]]*<id>([^<]+)<\/id>[[:space:]]*$/\1/p' "$manifest" | head -n 1)"

    if [[ -z "$manifest_id" ]]; then
      echo "Could not parse <id> from $manifest" >&2
      exit 1
    fi

    if [[ "$manifest_dir" == "$ROOT_DIR" ]]; then
      APP_DIR="$manifest_dir"
      break
    fi

    is_known_dir=false
    for candidate in "${CANDIDATE_DIRS[@]}"; do
      if [[ "$candidate" == "$manifest_dir" ]]; then
        is_known_dir=true
        break
      fi
    done
    if [[ "$is_known_dir" == false ]]; then
      CANDIDATE_DIRS+=("$manifest_dir")
    fi

    if [[ "$(basename "$manifest_dir")" == "$manifest_id" ]]; then
      if [[ -n "$MATCHING_DIR" && "$MATCHING_DIR" != "$manifest_dir" ]]; then
        echo "Multiple app directories match their manifest IDs; set APP_ROOT in ci/.env" >&2
        exit 1
      fi
      MATCHING_DIR="$manifest_dir"
    fi
  done

  if [[ -z "$APP_DIR" ]]; then
    if [[ -n "$MATCHING_DIR" ]]; then
      APP_DIR="$MATCHING_DIR"
    elif [[ "${#CANDIDATE_DIRS[@]}" -eq 1 ]]; then
      APP_DIR="${CANDIDATE_DIRS[0]}"
    else
      echo "Could not determine a unique app directory; set APP_ROOT in ci/.env" >&2
      exit 1
    fi
  fi

  APP_ID=""
  for manifest in "${MANIFESTS[@]}"; do
    if [[ "$(dirname "$manifest")" == "$APP_DIR" ]]; then
      APP_ID="$(sed -nE 's/^[[:space:]]*<id>([^<]+)<\/id>[[:space:]]*$/\1/p' "$manifest" | head -n 1)"
      break
    fi
  done

  echo "Detected app ID: $APP_ID"
fi

DEST="$APP_DIR/about/lib-about.js"
mkdir -p "$(dirname "$DEST")"
TMP_FILE="$(mktemp)"
trap 'rm -f "$TMP_FILE"' EXIT

curl --fail --location --silent --show-error "$ABOUT_JS_URL" --output "$TMP_FILE"

if ! cmp --silent "$TMP_FILE" "$DEST"; then
  mv "$TMP_FILE" "$DEST"
fi

ABOUT_PHP="$APP_DIR/about.php"
ABOUT_PHP_TEMPLATE="$SCRIPT_DIR/tpl/about.php"

if [[ ! -f "$ABOUT_PHP_TEMPLATE" ]]; then
  echo "about.php template not found: $ABOUT_PHP_TEMPLATE" >&2
  exit 1
fi

if ! cmp --silent "$ABOUT_PHP_TEMPLATE" "$ABOUT_PHP"; then
  cp "$ABOUT_PHP_TEMPLATE" "$ABOUT_PHP"
fi
