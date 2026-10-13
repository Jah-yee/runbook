# Changelog

All notable changes to Runbook are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project follows [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-10-13

### Fixed

- Completing a run and the run list now apply the section flow, so required
  steps in a section whose condition is false no longer block completion.
- `appinfo/info.xml` now validates against the official App Store schema
  (element order); CI validates it against the schema.
- Duplicating a template now copies the section dependencies and conditions,
  remapped to the copied sections and steps.
- Actions on a run refresh the page in place instead of replacing it with a
  loading spinner, so unsaved input in other steps, scroll position and focus
  are kept. After a failed action the page shows the current run state.
- Step counts use proper plural forms ("1 step", "3 steps").
- The run role is shown translated instead of the raw identifier.

### Security

- User and group search now goes through the Nextcloud sharee search and
  follows the instance's sharing and user enumeration restrictions. Before,
  any user could list every account and group. Administrators still search
  all accounts. When group sharing is disabled, non-administrators no longer
  find groups. Saving ACL entries and assignments still accepts any existing
  account; that is not restricted yet.
- Notifications are withdrawn when their run is deleted or the recipient can
  no longer view it, so run titles no longer stay visible after access ends.
- Duplicating a template now respects the template creation policy.

### Changed

- The localization pipeline (`build/l10n.php`, `build/validate-json.php`)
  supports Nextcloud plural entries.
- Background jobs are declared in `appinfo/info.xml` instead of being
  re-registered on every request. Existing installations keep their already
  registered jobs; upgrading to this version registers them from
  `appinfo/info.xml`.
- `appinfo/signature.json` is no longer tracked in the repository. Release
  packages are signed at release time from the exact packaged files, so a
  stale signature can no longer ship with a development build.

### Compatibility

- Nextcloud 33–35.
- PHP 8.2–8.5.
- No database migration.

## [1.0.0] - 2026-09-29

First public release of Runbook by More-IT.

### Added

- Structured runbook templates with sections, ordered steps and typed responses.
- Independent procedure runs with snapshots, assignments, deadlines and progress.
- User and group access control for templates and runs.
- Comments, mentions, activity history and Dashboard work integration.
- Native Nextcloud notifications and Unified Search integration.
- Evidence attachments with MIME and size validation.
- Nextcloud Files-backed evidence destinations with reconciliation and guarded
  cleanup behaviour.
- Import/export of reusable template documents.
- Administration settings for creation, execution, evidence and integration
  policies.
- Portuguese and English translations.

### Compatibility

- Nextcloud 33.
- PHP 8.2–8.5.
- MySQL/MariaDB, PostgreSQL and SQLite.

This source release is prepared for public collaboration. Real-instance
acceptance and App Store approval remain release-process gates.
