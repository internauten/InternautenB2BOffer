#!/usr/bin/env bash
set -euo pipefail

MODULE_FILE="internautenb2boffer/internautenb2boffer.php"
DRY_RUN=false

usage() {
  echo "Usage: $0 [--dry-run|-n]"
}

run_git() {
  env \
    -u GIT_ASKPASS \
    -u SSH_ASKPASS \
    -u VSCODE_GIT_ASKPASS_NODE \
    -u VSCODE_GIT_ASKPASS_MAIN \
    -u VSCODE_GIT_ASKPASS_EXTRA_ARGS \
    -u VSCODE_GIT_IPC_HANDLE \
    GIT_TERMINAL_PROMPT=1 \
    git "$@"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run|-n)
      DRY_RUN=true
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "Error: Unknown argument: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ ! -f "$MODULE_FILE" ]]; then
  echo "Error: Module file not found: $MODULE_FILE" >&2
  exit 1
fi

version=$(sed -n "s/.*\$this->version = '\([^']\+\)'.*/\1/p" "$MODULE_FILE" | head -n 1)

if [[ -z "$version" ]]; then
  echo "Error: Could not extract module version from $MODULE_FILE" >&2
  exit 1
fi

tag="v$version"

if [[ "$DRY_RUN" == "true" ]]; then
  echo "Dry-run: would create and push tag $tag"

  if run_git rev-parse "$tag" >/dev/null 2>&1; then
    echo "Dry-run: note -> tag already exists locally: $tag"
  fi

  if run_git ls-remote --tags origin "refs/tags/$tag" | grep -q "$tag"; then
    echo "Dry-run: note -> tag already exists on origin: $tag"
  fi

  echo "Dry-run: git tag -a $tag -m \"Release $tag\""
  echo "Dry-run: git push origin $tag"
  exit 0
fi

if run_git rev-parse "$tag" >/dev/null 2>&1; then
  echo "Error: Tag already exists locally: $tag" >&2
  exit 1
fi

if run_git ls-remote --tags origin "refs/tags/$tag" | grep -q "$tag"; then
  echo "Error: Tag already exists on origin: $tag" >&2
  exit 1
fi

run_git tag -a "$tag" -m "Release $tag"
run_git push origin "$tag"

echo "Created and pushed tag: $tag"
