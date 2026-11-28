# Releasing

1. Make sure CI is green on `main`.
2. Move the "Unreleased" notes in `CHANGELOG.md` under a version heading, e.g. `## 0.1.0 - 2026-12-01`,
   and merge that.
3. Tag `main` and push the tag:

   ```bash
   git tag -a v0.1.0 -m "v0.1.0"
   git push origin v0.1.0
   ```

   CI runs again on the tag.
4. Create a GitHub release from the tag with the changelog notes.

## Packagist (once)

1. Sign in to [packagist.org](https://packagist.org) with GitHub.
2. Submit `https://github.com/abhrajyoti-01/curl-cffi-php`. The package name,
   `abhrajyoti-01/curl-cffi-php`, comes from `composer.json`.
3. Packagist sets up the GitHub hook itself when you sign in with GitHub, so every new tag shows
   up as a version within a minute. If it does not, check "Settings > Connected accounts" on
   Packagist and use its "Update" button on the package page.
