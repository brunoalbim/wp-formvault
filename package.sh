#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$repo_dir"

if ! git diff --quiet || ! git diff --cached --quiet; then
	echo 'Há alterações não commitadas. Faça o commit antes de gerar o pacote.' >&2
	exit 1
fi

version="$(git show HEAD:wp-formvault.php | sed -n 's/^ \* Version:[[:space:]]*\([0-9][0-9.]*\).*$/\1/p' | head -n 1)"
if [[ -z "$version" ]]; then
	echo 'Não foi possível identificar a versão em wp-formvault.php.' >&2
	exit 1
fi

archive="$repo_dir/wp-formvault-$version.zip"
git archive --format=zip --prefix=wp-formvault/ -o "$archive" HEAD
echo "Pacote criado: $archive"
