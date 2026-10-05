#!/usr/bin/env bash
set -euo pipefail

plugin_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_dir="$(git -C "$plugin_dir" rev-parse --show-toplevel)"
cd "$repo_dir"

if ! git diff --quiet || ! git diff --cached --quiet; then
	echo 'Há alterações não commitadas. Faça o commit antes de gerar o pacote.' >&2
	exit 1
fi

plugin_path='packages/wp-attribution-tracker'
version="$(git show "HEAD:$plugin_path/wp-attribution-tracker.php" | sed -n 's/^ \* Version:[[:space:]]*\([0-9][0-9.]*\).*$/\1/p' | head -n 1)"
if [[ -z "$version" ]]; then
	echo 'Não foi possível identificar a versão do plugin.' >&2
	exit 1
fi

archive="$repo_dir/wp-attribution-tracker-$version.zip"
git archive --format=zip --prefix=wp-attribution-tracker/ -o "$archive" "HEAD:$plugin_path"
echo "Pacote criado: $archive"
