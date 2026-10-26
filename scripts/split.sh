#!/usr/bin/env bash
#
# Publish this monorepo to its read-only package repositories.
#
#   scripts/split.sh <remote-base> <branch> [tag]
#
#   remote-base  where the package repositories live, for example
#                https://x-access-token:TOKEN@github.com/xpsr-php, or a local directory of bare
#                repositories for testing. Each repository is <remote-base>/<name>.git.
#   branch       the branch to update in every package repository, for example master.
#   tag          optional release tag such as v0.1.0. With a tag, only tags are pushed and
#                branches are left alone: the tagged commit may be older than the branch tip.
#                The skeleton's release commit (with ^X.Y constraints, see export-skeleton.php)
#                is built on top of <branch> and reachable only through the tag.
#
# Everything is taken from the committed HEAD, never from the working tree.
#
#   packages/framework         -> framework.git          (git subtree split: history preserved)
#   packages/router-fastroute  -> router-fastroute.git
#   packages/router-sunrise    -> router-sunrise.git
#   scripts/export-skeleton.php output -> skeleton.git   (one sync commit per change)
#
# Re-running with the same HEAD changes nothing: subtree splits are deterministic, and the
# skeleton is committed only when its exported files differ.
set -euo pipefail

if [[ $# -lt 2 || $# -gt 3 ]]; then
    echo "Usage: scripts/split.sh <remote-base> <branch> [tag]" >&2
    exit 2
fi
base=${1%/}
branch=$2
tag=${3:-}
if [[ -n "$tag" && ! "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "The tag must look like v1.2.3." >&2
    exit 2
fi

root=$(git rev-parse --show-toplevel)
cd "$root"
head=$(git rev-parse HEAD)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# push <git directory> <repository name> <commit>
push() {
    local directory=$1 repository=$2 commit=$3
    if [[ -n "$tag" ]]; then
        git -C "$directory" push --quiet "$base/$repository.git" "$commit:refs/tags/$tag"
        echo "$repository: tag $tag -> ${commit:0:12}"
    else
        git -C "$directory" push --quiet "$base/$repository.git" "$commit:refs/heads/$branch"
        echo "$repository: $branch -> ${commit:0:12}"
    fi
}

for package in framework router-fastroute router-sunrise; do
    commit=$(git subtree split --quiet --prefix="packages/$package" "$head")
    push "$root" "$package" "$commit"
done

# The skeleton is generated, so it is exported from the committed tree and committed on top
# of the skeleton repository's existing history.
mkdir "$work/source"
git archive "$head" | tar -x -C "$work/source"
release=()
if [[ -n "$tag" ]]; then
    release=("--release=${tag#v}")
fi
php "$work/source/scripts/export-skeleton.php" "$work/export" "${release[@]}" > /dev/null

git init --quiet "$work/skeleton"
cd "$work/skeleton"
git config user.name "$(git -C "$root" log -1 --format=%an "$head")"
git config user.email "$(git -C "$root" log -1 --format=%ae "$head")"
if git fetch --quiet "$base/skeleton.git" "refs/heads/$branch" 2> /dev/null; then
    git checkout --quiet -b "$branch" FETCH_HEAD
else
    git checkout --quiet --orphan "$branch"
fi
git rm -r --quiet --force --ignore-unmatch . > /dev/null
cp -a "$work/export/." .
git add --all
if git diff --cached --quiet && git rev-parse --verify --quiet HEAD > /dev/null; then
    echo "skeleton: unchanged"
else
    git commit --quiet -m "Sync skeleton from monorepo ${head:0:12}"
fi
cd "$root"
push "$work/skeleton" skeleton "$(git -C "$work/skeleton" rev-parse HEAD)"
