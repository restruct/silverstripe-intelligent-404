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

### Only what the visitor may see

A 404 never lists or redirects to a record the current visitor may not view: every match is checked
with `canView()` for the logged-in member (or for an anonymous visitor). A login-protected page is
therefore suggested to the members who can open it, and to nobody else. A match that fails the check
does not count towards the single-match redirect either, so it cannot win over a public soundalike.

Pages hidden from search (**Show in search?** unticked, `ShowInSearch = 0`) are left out as well, by
default. This applies to every record that has a `ShowInSearch` field, also when only a subclass of
the configured class declares it.

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

Records hidden from search are not matched (see above). Set `exclude_hidden_from_search: false` to
match them again; `canView()` is still checked.
A `data_objects` entry whose own `filter` selects `ShowInSearch: 0` matches nothing while this is on,
because both filters apply; set `exclude_hidden_from_search: false` for such a setup.

```yml
Restruct\Silverstripe\Intelligent404\Intelligent404:
  allow_in_dev_mode: true             # allow this to work in dev mode (default false)
  redirect_on_single_match: false     # do not auto-redirect if one exact match is found (default true)
  exclude_hidden_from_search: false   # also match records with ShowInSearch = 0 (default true)
  redirect_on_normalised_match: true  # 301 to the page at the URLSegmentFilter-cleaned path (default false)
```

`redirect_on_normalised_match` runs every segment of the requested path through `URLSegmentFilter`
and redirects (301, querystring kept) to the live page at exactly the resulting path, if the visitor
may view it. It catches URLs that only differ from a real page by what the filter changes: case,
underscores, or a curly apostrophe in an old segment (`drie-lasdiploma’s` to `drie-lasdiplomas`).
Silverstripe cannot redirect those itself, because it looks pages up by the percent-encoded segment.
It is independent of `redirect_on_single_match` and runs before the fuzzy matching.

## Resolvers: your own 404 decisions

Before the fuzzy matching, the module asks resolvers what to do with the 404. A resolver is an
extension on `ErrorPageController` implementing `updateIntelligent404Resolution()`. It gets one
`Intelligent404Resolution` object (the request's decoded `path`, without slashes, and its `query`) and
either redirects or answers with its own content. The first resolver that does either wins; check
`isResolved()` and return if another one got there first. A resolved 404 skips the fuzzy matching.

```php
use Restruct\Silverstripe\Intelligent404\Intelligent404Resolution;
use SilverStripe\Core\Extension;

class RetiredProductResolver extends Extension
{
    public function updateIntelligent404Resolution(Intelligent404Resolution $resolution)
    {
        if ($resolution->isResolved() || !preg_match('#^products/(.+)$#', $resolution->path, $m)) {
            return;
        }
        $product = Product::get()->filter('OldSlug', $m[1])->first();
        if ($product && $product->Successor()->exists()) {
            $resolution->redirect($product->Successor()->Link());            // 301 by default
        } elseif ($product) {
            $resolution->respond(
                $product->renderWith('RetiredProductNotice'),                // HTML for the page
                410,                                                         // status (null keeps 404)
                true,                                                        // replace the page's Content
                'No longer available'                                        // page title (null keeps it)
            );
        }
    }
}
```

```yml
SilverStripe\ErrorPage\ErrorPageController:
  extensions:
    - RetiredProductResolver
```

A response renders through your normal ErrorPage template, so themes keep working; only `$Content`
(and `$Title`, if given) change, and `$Intelligent404Options` holds the resolver's HTML. A redirect
back to the requested URL is refused (loop guard).

**The status code.** `ErrorPageController` sets its own error code after rendering, so a status set
while the page renders would always end up as 404. The module leaves an `X-Intelligent404-Status`
marker header instead, and `Intelligent404StatusMiddleware` (registered on `Director`) swaps the
status and removes the marker. It only turns a 404 into another 4xx.

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

**The class needs a `canView()` that lets your visitors see it.** Only records the visitor may view
are matched, and `DataObject::canView()` on its own allows administrators only. A class without its
own `canView()` is therefore never suggested to the public. For a public catalogue:

```php
public function canView($member = null)
{
    return true;
}
```

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
its test namespace (`Restruct\Silverstripe\Intelligent404\Tests\` ->
`vendor/restruct/silverstripe-intelligent-404/tests/`) to the host's `autoload-dev`, and copy this
module's `phpunit.xml.dist` to the host root as `phpunit.xml` (it is a template: its paths are relative
to the host root, so it does not work in place). Then:

```bash
# Silverstripe 5 (PHPUnit 9) - the path must come before flush=1
vendor/bin/phpunit vendor/restruct/silverstripe-intelligent-404/tests flush=1 --fail-on-empty-test-suite

# Silverstripe 6 (PHPUnit 11) - flush through the env var: a flush=1 argument makes PHPUnit
# abort (Test file "flush=1" not found)
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-intelligent-404/tests --fail-on-empty-test-suite
```

CI runs the same suite against Silverstripe 5 and 6 on every push; see `.github/workflows/ci.yml`.
