#!/usr/bin/env bash
set -euo pipefail

# Validate before emitting either output; safe paths cannot inject CSV/query tokens.
if ! outputs=$(jq -ers '
  if length == 1 then .[0] else error("Expected one policy object") end
  | if type == "object"
       and .username == "orsnaro"
       and (.excluded_repositories | type == "array" and length > 0)
       and (.excluded_repositories | all(.[];
         type == "string"
         and test("^orsnaro/[A-Za-z0-9_.-]+\\z")
         and . != "orsnaro/."
         and . != "orsnaro/.."
       ))
       and (.excluded_repositories | map(ascii_downcase) | length == (unique | length))
    then
      "repositories=" + (.excluded_repositories | join(",")),
      "repository_names=" + (.excluded_repositories | map(split("/")[1]) | join(","))
    else error("Invalid profile policy")
    end
' .github/profile-stats.json); then
  echo "::error::Invalid profile contribution policy." >&2
  exit 1
fi

printf '%s\n' "$outputs" >> "${GITHUB_OUTPUT:?}"
