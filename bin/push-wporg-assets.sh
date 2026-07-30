#!/usr/bin/env bash
# Laedt die Listing-Assets (Icons/Banner/Screenshots) ins WordPress.org-SVN.
#
# Assets liegen im SVN unter /assets/ — NICHT unter /trunk/assets/. Sie sind vom
# Plugin-Release entkoppelt: ein Asset-Update braucht KEINEN neuen Release-Tag und
# ist wenige Minuten spaeter im Verzeichnis sichtbar.
#
# Zugang: SVN-Username = WordPress.org-Benutzername.
#   Passwort = das im WP.org-Profil erzeugte APP-PASSWORT, nicht das Website-Passwort
#   (Website-Passwoerter funktionieren seit Oktober 2024 nicht mehr fuer SVN).
set -euo pipefail

SLUG="steadysync-for-square"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/.wordpress-org"
WORK="${1:-/tmp/wporg-$SLUG}"

[[ -d "$SRC" ]] || { echo "Fehlt: $SRC" >&2; exit 1; }
command -v svn >/dev/null || { echo "svn nicht installiert (brew install svn)" >&2; exit 1; }

# Nur /assets auschecken — depth immediates am Root spart trunk/ und tags/ komplett.
if [[ ! -d "$WORK/.svn" ]]; then
  svn checkout --depth immediates "https://plugins.svn.wordpress.org/$SLUG" "$WORK"
fi
svn update --set-depth infinity "$WORK/assets"

for f in icon-128x128.png icon-256x256.png banner-772x250.png banner-1544x500.png \
         screenshot-1.png screenshot-2.png screenshot-3.png; do
  [[ -f "$SRC/$f" ]] && cp "$SRC/$f" "$WORK/assets/$f"
done

cd "$WORK/assets"
svn add --force . >/dev/null
svn status | awk '/^!/ {print $2}' | xargs -r svn rm

echo "--- Aenderungen ---"; svn status; echo
read -r -p "Committen? [j/N] " a
[[ "$a" == "j" ]] || { echo "Abgebrochen."; exit 0; }

svn commit -m "Update listing assets: amber brand refresh (icons + banners)"
echo "OK — im Plugin-Verzeichnis meist innerhalb weniger Minuten sichtbar."
