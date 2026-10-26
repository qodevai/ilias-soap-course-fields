# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Security
- `setCourseUserFieldValues` rejects values that `ilUtil::stripSlashes()` would change (HTML tags). The
  participants table prints stored values without escaping.

### Changed
- An unexpected error inside a method is logged in the ILIAS log and the caller gets "Internal error", no
  longer the raw exception text with SQL.

### Fixed
- A failed write restores each field on its own and names any field it could not restore. The edit-history
  save is part of the same step, so a history failure no longer leaves changed values behind a fault.

## [0.1.0] - 2026-10-26

### Added
- SOAP methods `getCourseUserFields`, `getCourseUserFieldValues` and `setCourseUserFieldValues`
  (namespace `urn:CourseUserFields`) as an ILIAS 10 `SoapHook` plugin.
- Unit tests (PHPUnit), an end-to-end script against a scratch ILIAS 10.11 and a Docker stack to run it.
- PHP-CS-Fixer (PSR-12), `.editorconfig` and CI on PHP 8.2 and 8.3.
- GPL-3.0-only licence.
