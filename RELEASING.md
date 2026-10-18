# Release procedure

1. Review changes and confirm the intended supported WordPress/PHP versions. Update the main plugin's Version header and VERSION constant together, plus README, readme.txt and CHANGELOG.
2. Run `php tests/test-updates.php` and lint changed PHP files. Verify the release ZIP contains only allowed plugin files and vendored runtime dependencies.
3. Run `python3 scripts/build-release.py`. This creates an installable ZIP and SHA256SUMS in ignored `dist/`, with the exact top-level folder `elementor-stylesheet-monitor/`. The filename is `elementor-stylesheet-monitor-VERSION.zip`.
4. Commit reviewed source. Tag the same commit `vVERSION`. Do not move or reuse an existing release tag or replace an existing published asset.
5. Create a **draft** GitHub release for that tag. Attach the packaged ZIP and SHA256SUMS, add release notes, and inspect the downloaded package before publishing. GitHub's automatically generated source archive is not an update package.
6. Publish as a stable release only when approved. The updater checks published stable releases and requires the packaged ZIP. Draft/prerelease updates and loose tags are ignored. Do not mark a rollback release as the latest stable release unintentionally.
7. Update one pilot site manually, verify monitoring, styles and signed collection, and observe for 24 hours. Then approve the second site. Keep automatic updates off for this controlled rollout. Capture a current backup of settings/history and retain the prior release ZIP before changes.

## Initial adoption

Sites on 0.3.0 have no GitHub update discovery. Manually install 0.4.0 once, preserving the plugin folder name and active state. It registers release discovery without overwriting site settings, recipient, collector secret or evidence. Demonstrate an offered update using the next approved stable release; do not publish a fake higher version to production just for testing.

## Rollback

Use the prior reviewed ZIP via WordPress's plugin upload replacement or WP-CLI `wp plugin install /private/path/previous.zip --force`. Confirm the installed version and active state. A rollback changes code, not stored settings/history. Inspect any data migrations before downgrading across future schema changes. To suppress discovery while investigating, set `ESM_DISABLE_UPDATE_CHECKS` to true in wp-config.php. WordPress/plugin updates may trigger Elementor cache purges; verify normal regeneration afterward.

## Publishing access

Use protected account credentials and two-factor authentication. Limit repository/release write access. No shared GitHub token is distributed to WordPress sites. Creating the repository, publishing releases and applying production updates are explicit deployment steps, not side effects of building a local ZIP.
