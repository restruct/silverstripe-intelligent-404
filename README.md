# Intelligent 404 Redirector for SilverStripe

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

### Forked here because the [original module](https://github.com/axllent/silverstripe-intelligent-404) is no longer actively maintained.

An extension to add additional functionality to the existing 404 ErrorPage.
If a 404 page is called, it tries to guess the intended page by matching up the
last segment of the url to all SiteTree pages. It also uses soundex to match similar
sounding pages to find other alternatives.

Other DataObjects (eg: products) can be added too provided they contain a `Link()` function

## Requirements

* Silverstripe 5 or 6, with `silverstripe/cms` and `silverstripe/errorpage`
* PHP 8.1 or newer (Silverstripe 6 itself requires 8.3)

## Installation

```
composer require restruct/silverstripe-intelligent-404
```

The extension is applied to `SilverStripe\ErrorPage\ErrorPageController` automatically. Run a
`dev/build` (Silverstripe 5) or `sake db:build` (Silverstripe 6) with a flush afterwards.

## Version compatibility

| Branch | Module version | Silverstripe | PHP |
|--------|----------------|--------------|-----|
| `main` | `4.x` (from `4.1.0`) | `^5 \|\| ^6` | `^8.1` |
| (tags only) | `4.0.0` | `^6` | as Silverstripe 6 (`^8.3`) |
| (tags only) | `3.0.1`, `3.0.2` | `^4 \|\| ^5` | as the Silverstripe version |
| (tags only) | `3.0.0` | `^5` | as Silverstripe 5 (`^8.1`) |

Silverstripe 4 reached end of life in April 2025 and is no longer supported or tested here. Projects
still on it should stay on the `3.0.x` tags, which remain available. Silverstripe 3 and 4 projects
using the original `axllent/silverstripe-intelligent-404` package are unaffected by this fork.

`main` is the only maintained line: it supports every Silverstripe version this module still
targets, so there is no separate maintenance branch.

**`composer.json` is the source of truth** for exact constraints; this table is a quick reference.

## How it works
If a 404 error is detected (**note:** does not work in `dev` mode by default):

1. It will search SiteTree for all matching URLSegments, as well as any that sound the same
(using PHP's [soundex()](http://php.net/manual/en/function.soundex.php)).
2. If **1 exact** match is found, a 301 redirect is sent.
3. Else if **no exact** match is found, and **1 similar** page is found, a 301 redirect is sent
to the similar page.
4. Else if more than 1 exact or similar page is found, a regular 404 page is shown and the list of
possible options is shown (ie: "Were you looking for one of the following pages?") directly beneath it.
5. Else a regular 404 page is shown.

Only the last segment of the requested URL is matched, and a trailing `.asp`, `.aspx`, `.htm`,
`.html`, `.php`, `.php3` or `.php4` extension is ignored, so `/old-site/about-us.php` finds a page
with the URL segment `about-us` anywhere in the site tree. Only the `404` ErrorPage is affected;
other error pages are left alone.

# Configuration

By default Intelligent-404 will just try match 404 pages with *all pages* in your SiteTree, except for pages with the classnames:

- `SilverStripe\ErrorPage\ErrorPage`
- `SilverStripe\CMS\Model\RedirectorPage`
- `SilverStripe\CMS\Model\VirtualPage`

This means that any page types you add should automatically be included, and can optionally be excluded as well.

## Generic options
By default Intelligent-404 will not work in dev mode (to help you spot issues). You can turn this on by setting `allow_in_dev_mode: true`.

By default the module will also redirect if either 1 exact match or one potential match is found (eg: the page has been recreated elsewhere).
You can change this by setting `redirect_on_single_match: false`, in which case the single match is listed as an option instead.

```yml
Restruct\Silverstripe\Intelligent404\Intelligent404:
  allow_in_dev_mode: true           # allow this to work in dev mode (default false)
  redirect_on_single_match: false   # do not auto-redirect if one exact match is found (default true)
```

## Adding other DataObjects

It is possible to add other DataObjects to the Intelligent-404 matching (such as a product database), provided that the DataObject has a `Link()` function. To add your own DataObjects, create a yml config (eg: `app/_config/intelligent404.yml`) with the following syntax:

```yml
Restruct\Silverstripe\Intelligent404\Intelligent404:
  data_objects:
    App\Model\Product:          # fully qualified class name
      group: Products           # optional group to include the results list into (default 'Pages')
      filter:                   # optionally filter which results to include (see below)
        Stock:GreaterThan: 0
      exclude:                  # optional filter out results (see below)
        Expired: 1
```

The URL segment matched is the last segment of the object's `Link()`.

## Notes:

### - Class names

Use the fully qualified class name, without a leading backslash (`App\Model\Product`). A leading
backslash (`\App\Model\Product`), which earlier versions of this README asked for, is still
accepted: from `4.1.0` it is stripped before the class is looked up. Before that, such an entry was
silently skipped unless the class happened to be loaded already.

### Group (`group:`)

By default, all results will get passed on to your template as results of a `$Pages` ArrayList. You can optionally split your
suggestion results (for instance for different ordering or templating) by adding a `group: <groupname>`
as in the example above. Then in your template you would do a `<% loop $Products %>` in addition to your `$Pages`.

If you add your own grouping, please be sure to create your own `templates/Intelligent404Options.ss` to allow for those:
the bundled template renders `$Pages` only.

### Filter results (`filter:`)

You are able to filter the database field matches, for instance (in the example) products that have a `Stock` greater than `0`.

Please refer to the [Searchfilters documentation](https://docs.silverstripe.org/en/6/developer_guides/model/searchfilters/) for more information.

### Exclude results (`exclude:`)

You can also exclude database field matches, for instance (in the example) products that have a `Expired` of `1`.

## Template variables

When options are shown (step 4 above), the extension sets these on the error page controller:

| Variable | Contents |
|----------|----------|
| `$Content` | the error page's content with the rendered options appended |
| `$ContentWithout404Options` | the error page's content as entered in the CMS, without the options |
| `$Intelligent404Options` | the rendered options on their own (`templates/Intelligent404Options.ss`) |
| `$SearchQuery` | the requested segment, sanitised for display or a search form: only letters, digits, `-`, `_` and `.` are kept, and dashes and underscores become spaces |

To place the options yourself, render `$ContentWithout404Options` and `$Intelligent404Options`
instead of `$Content` in your ErrorPage template. Override `Intelligent404Options.ss` in your theme
to change their markup; the heading is translatable (`Intelligent404.OptionsHeader`).

## Running the tests

The module cannot be tested on its own: it needs a host Silverstripe project with `silverstripe/recipe-cms`
and `silverstripe/recipe-testing`. Require it there through a Composer **path repository with
`symlink: true`** - `/tests` is `export-ignore`, so a dist or mirrored install contains no tests - add
its test namespace (`Restruct\Silverstripe\Intelligent404\Tests\` →
`vendor/restruct/silverstripe-intelligent-404/tests/`) to the host's `autoload-dev`, and copy this
module's `phpunit.xml.dist` to the host root as `phpunit.xml` (it is a template: its paths are relative
to the host root, so it does not work in place). Then:

```bash
# Silverstripe 5 (PHPUnit 9) - the path must come before flush=1
vendor/bin/phpunit vendor/restruct/silverstripe-intelligent-404/tests flush=1

# Silverstripe 6 (PHPUnit 11) - a flush=1 argument is ignored, use the env var
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-intelligent-404/tests
```

CI runs the same suite against Silverstripe 5 and 6 on every push; see `.github/workflows/ci.yml`.
