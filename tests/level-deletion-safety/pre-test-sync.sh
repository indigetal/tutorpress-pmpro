#!/usr/bin/env bash
set -euo pipefail
set -f
src_root="$(cd /Users/brandonmeyer/Dev/plugins/tutorpress-pmpro && pwd -P)"
dst_root="$(cd /Users/brandonmeyer/DevKinsta/public/tutorpress/wp-content/plugins/tutorpress-pmpro && pwd -P)"
under() { case "$2" in "$1"|"$1"/*) return 0 ;; *) return 1 ;; esac; }
[[ $# -gt 0 ]] || { echo "usage: $0 <repo-relative-file>..." >&2; exit 1; }
for rel in "$@"; do
  src="$(cd "$src_root" && realpath "$rel")" && under "$src_root" "$src" && [[ -f "$src" ]] || { echo "source refused: $rel" >&2; exit 1; }
  acc="$dst_root"; IFS=/
  for part in $(dirname "$rel"); do
    [[ "$part" == "." || -z "$part" ]] && continue
    next="$acc/$part"
    if [[ -e "$next" || -L "$next" ]]; then acc="$(realpath "$next")"; else mkdir "$next"; acc="$(realpath "$next")"; fi
    under "$dst_root" "$acc" || { echo "destination escaped: $rel" >&2; exit 1; }
  done
  dest="$acc/$(basename "$rel")"
  { [[ ! -e "$dest" && ! -L "$dest" ]] || under "$dst_root" "$(realpath "$dest")"; } && cp "$src" "$dest" && dest="$(realpath "$dest")" && under "$dst_root" "$dest" || { echo "destination escaped: $rel" >&2; exit 1; }
  s="$(shasum -a 256 "$src")"; d="$(shasum -a 256 "$dest")"
  [[ "${s%% *}" == "${d%% *}" ]] || { echo "checksum mismatch: $rel" >&2; exit 1; }
  echo "$rel ${s%% *} ${d%% *}"
done
