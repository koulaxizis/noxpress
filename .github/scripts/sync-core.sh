#!/usr/bin/env bash
# Copies Noxpress Core (Bible §16) from Revenue Splitter, the reference
# implementation, to every other suite plugin. Run it after any change in
# plugins/revenue-splitter/includes/noxpress-core/ (and bump the version
# key in loader.php). The release workflow fails when the copies differ.
set -euo pipefail
cd "$(dirname "$0")/../.."
src=plugins/revenue-splitter/includes/noxpress-core
for slug in store-pulse smart-formatter theme-patcher shop-filters; do
  rm -rf "plugins/$slug/includes/noxpress-core"
  cp -R "$src" "plugins/$slug/includes/noxpress-core"
  echo "synced $slug"
done
