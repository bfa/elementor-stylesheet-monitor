# Security

Do not publish signing keys, database exports, raw traces, customer data or private site configuration in an issue or pull request. For a suspected vulnerability, use GitHub private vulnerability reporting if enabled; otherwise request a private reporting channel from the repository owner without posting exploit details or secrets publicly.

Updates trust this repository's release maintainers and GitHub's HTTPS delivery. Release checks are not independent cryptographic code signing. Published SHA256 checksums support manual artifact verification; they do not protect against a compromised maintainer account that can replace both files. Limit release permissions, use two-factor authentication, retain prior artifacts and review changes before a stable release.

Only users with manage_options can administer this monitor. Remote collection is disabled until explicitly configured with a per-site signing key. No unauthenticated repair or remote code-execution route is provided. Monitoring is not an accessibility audit, uptime guarantee or general security scanner.
