# Changelog

## 4.1.0 (unreleased)

Silverstripe 5 and 6 from one line, a test suite, CI, and two fixes. No breaking changes against
`4.0.0`.

### Changed

- **Supports Silverstripe `^5 || ^6`** (was `^6` in `4.0.0`). Silverstripe 4 is end of life and is
  no longer supported; projects on it should stay on `3.0.x`.
- `composer.json` now declares what the module has always used: `silverstripe/cms` (SiteTree,
  RedirectorPage, VirtualPage) and `silverstripe/errorpage` (the extension is applied to
  `ErrorPageController`), plus `php: ^8.1`.

### Fixed

- **Silverstripe 5: every matched 404 fataled.** The unreleased `main` after `4.0.0` declared
  framework `^4 || ^5 || ^6` while importing `SilverStripe\Model\List\ArrayList`, which exists on
  Silverstripe 6 only, so on 5 a 404 reaching the matching code became a 500
  (`Class "SilverStripe\Model\List\ArrayList" not found`). No tagged release was affected: `3.0.x`
  used the Silverstripe 5 class and `4.0.0` required Silverstripe 6. The class is now chosen at
  runtime.
- **`data_objects` entries with a leading backslash were skipped silently.** The README told
  projects to write `\Product`; `ClassInfo::exists()` only recognises that form once the class has
  already been loaded, so the entry usually matched nothing. Configured class names are now
  normalised, and the README no longer asks for the backslash. If the same class is configured both
  with and without the backslash, the two entries collapse into one and the later one wins.
- An `Undefined array key "REQUEST_URI"` warning when the error page controller runs outside a web
  request.

### Added

- A behavioural test suite (22 tests) and GitHub Actions CI across Silverstripe 5 and 6.
  - The declared default of `allow_in_dev_mode` (`false`) is checked on the class itself, so a
    config override elsewhere in the suite cannot hide a changed default.
  - Stripping a `.php` ending is checked against a page that sounds like the target, so a
    soundex fallback cannot pass for an exact match.
- `Intelligent404::getConfiguredClasses()`: the `data_objects` config with normalised class names,
  as the extension reads it.
- README: requirements, a version compatibility table, the template variables the module sets
  (`$ContentWithout404Options`, `$Intelligent404Options`, `$SearchQuery`), and how to run the tests.
- `funding` in `composer.json`.

### Upgrading

- From `4.0.0`: nothing to do.
- From `3.0.x` on Silverstripe 5: change the constraint to `^4.1`. If you configured `data_objects`,
  those entries may start matching now (see the leading-backslash fix above); check that is what
  you want. On Silverstripe 4, stay on `^3.0`.

## 4.0.0

Silverstripe 6 support (`silverstripe/framework: ^6`).

## 3.0.2

Restores "not active in dev mode" as the default, and provides `$SearchQuery` to templates.

## 3.0.1

Silverstripe 4 or 5 (`silverstripe/framework: ^4.0 || ^5.0`). Note that this release shipped
with `allow_in_dev_mode: true` as the default; `3.0.2` reverted that.

## 3.0.0

First release of the `restruct/silverstripe-intelligent-404` fork, for Silverstripe 5. Namespace
`Restruct\Silverstripe\Intelligent404`.
