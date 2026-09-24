<?php

namespace Restruct\Silverstripe\Intelligent404;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Model\List\ArrayList; # Silverstripe 6
use SilverStripe\ORM\ArrayList as LegacyArrayList; # Silverstripe 5 (moved to SilverStripe\Model\List in 6)
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ErrorPage\ErrorPageController;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;

/**
 * SilverStripe Intelligent 404
 * ============================
 *
 * Extension to add additional functionality to the existing 404 ErrorPage.
 * It tries to guess the intended page by matching up the last segment of
 * the url to all SiteTree pages (and optionally other DataObjects).
 * It also uses soundex to match similar sounding page links to find alternatives.
 */

class Intelligent404
    extends Extension
{
    /**
     * @config
     * allow this to work in dev mode
     */
    private static $allow_in_dev_mode = false;

    /**
     * @config
     * auto-redirect if only one exact match is found
     */
    private static $redirect_on_single_match = true;

    /**
     * @config
     * leave out records that are hidden from search (ShowInSearch = 0, e.g. pages unticked for
     * "Show in search?"), so a 404 does not advertise them. Only applies to classes that have a
     * ShowInSearch field; set to false to match such records again.
     */
    private static $exclude_hidden_from_search = true;

    /**
     * @config
     * auto-redirect if only one exact match is found
     */
    private static $data_objects = [
        SiteTree::class => [ # '\\SilverStripe\\CMS\\Model\\SiteTree'
            'group' => 'Pages',
            'filter' => [],
            'exclude' => [
                'ClassName' => [
                    ErrorPage::class, # 'SilverStripe\\CMS\\Model\\ErrorPage'
                    RedirectorPage::class, # 'SilverStripe\\CMS\\Model\\RedirectorPage'
                    VirtualPage::class, # 'SilverStripe\\CMS\\Model\\VirtualPage'
                ]
            ]
        ]
    ];

    public function onAfterInit() // NOTE: should become protected, probably in some next major FW version
    {
        $error_code = $this->getOwner()->failover->ErrorCode ?: 404;
        if ($error_code != 404) {
            return; // we only deal with 404
        }

        // Make sure the SiteTree's 404 page isn't being called
        // (via `/dev/build`) to generate `assets/error-404.html`
        $request = !(empty($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : false;
        if ( $request && $error_page = ErrorPage::get()->filter('ErrorCode', $error_code)->first() ) {
            if ($error_page->Link() == $request) {
                return;
            }
        }

        if ( !Director::isDev() || Config::inst()->get(self::class, 'allow_in_dev_mode') ) {
            # Use the guarded $request from above: REQUEST_URI is absent when the error page controller
            # runs outside a web request (CLI), and reading it unguarded raised an "Undefined array key" warning
//            $extract = preg_match('/^([a-z0-9\.\_\-\/]+)/i', (string) $_SERVER['REQUEST_URI'], $rawString);
            $extract = preg_match('/^([a-z0-9\.\_\-\/]+)/i', (string) $request, $rawString);

            if ($extract) {
                $uri = preg_replace('/\.(aspx?|html?|php[34]?)$/i', '', $rawString[0]); // skip known page extensions
                $parts = preg_split('/\//', (string) $uri, -1, PREG_SPLIT_NO_EMPTY);
                $page_key = array_pop($parts);
                $sounds_like = soundex((string) $page_key);
                $exact_matches = [];
                $possible_matches = [];
                $results_list = [];

                # Read through the seam, which normalises the configured class names (see getConfiguredClasses())
//                $data_objects = Config::inst()->get(self::class, 'data_objects');
//                if (!$data_objects || !is_array($data_objects)) {
//                    return;
//                }
                $data_objects = self::getConfiguredClasses();
                if (!$data_objects) {
                    return;
                }

                # ArrayList lives in SilverStripe\Model\List on Silverstripe 6 and in SilverStripe\ORM on 5;
                # class_exists() autoloads, which is what we want for a core framework class
                $list_class = class_exists(ArrayList::class) ? ArrayList::class : LegacyArrayList::class;

                foreach ($data_objects as $class => $config) {
                    # Accept `\Product` as well as `Product`: ClassInfo::exists() only recognises the
                    # leading-backslash form once the class happens to be loaded already.
                    # The keys arrive normalised from getConfiguredClasses(), where the call now lives
                    # so a test can reach it (in a test run every class is loaded, which hides the bug here)
//                    $class = self::normaliseClassName((string) $class);

                    if (
                        !ClassInfo::exists($class) ||
                        !method_exists($class, 'Link')
                    ) {
                        continue; // invalid class (does not exist)
                    }

                    $group = !empty($config['group']) ? $config['group'] : 'Pages';

                    if (empty($results_list[$group])) {
//                        $results_list[$group] = ArrayList::create();
                        $results_list[$group] = $list_class::create();
                    }

                    $results = $class::get(); // all results

                    if (!empty($config['filter'])) {
                        $results = $results->filter($config['filter']); // filter
                    }

                    if (!empty($config['exclude'])) {
                        $results = $results->exclude($config['exclude']); // exclude
                    }

                    # Records hidden from search are not to be advertised on a 404 either (config, default on).
                    # Filtered in SQL, and only where the class actually has the field (SiteTree does, a custom
                    # DataObject usually does not)
                    if (
                        Config::inst()->get(self::class, 'exclude_hidden_from_search') &&
                        DataObject::getSchema()->fieldSpec($class, 'ShowInSearch')
                    ) {
                        $results = $results->filter('ShowInSearch', true);
                    }

                    foreach ($results as $result) {
                        # The SQL filter above only sees fields of the configured class and its parents. A
                        # SUBCLASS record that declares ShowInSearch itself is checked here, per record
                        if (
                            Config::inst()->get(self::class, 'exclude_hidden_from_search') &&
                            $result->hasField('ShowInSearch') &&
                            !$result->ShowInSearch
                        ) {
                            continue;
                        }

                        $link = $result->Link();

                        $rel_link = Director::makeRelative($link);

                        if (!$rel_link) {
                            continue; // no link or `/`
                        }

                        $url_parts = preg_split('/\//', $rel_link, -1, PREG_SPLIT_NO_EMPTY);

                        $url_segment = end($url_parts);

                        # SECURITY: never list or redirect to a record the current visitor may not view, or a
                        # 404 would leak the titles and URLs of login-protected pages. Checked only for a
                        # (possible) match, as canView() can be expensive and most records match nothing.
                        # A skipped record does not count towards the single-match redirect either.
                        $is_match = $url_segment == $page_key || $sounds_like == soundex((string) $url_segment);
                        if (!$is_match || !$result->canView()) {
                            continue;
                        }

                        if ($url_segment == $page_key) {
                            $results_list[$group]->push($result);
                            $exact_matches[$link] = $link;
                        } elseif ($sounds_like == soundex((string) $url_segment)) {
                            $results_list[$group]->push($result);
                            $possible_matches[$link] = $link;
                        }
                    }
                }

                $exact_count = count($exact_matches);
                $possible_count = count($possible_matches);

                $redirect_on_single_match = Config::inst()->get(self::class, 'redirect_on_single_match');

                if ($exact_count == 1 && $redirect_on_single_match) {
                    $this->RedirectToPage(array_shift($exact_matches));
                } elseif ($exact_count == 0 && $possible_count == 1 && $redirect_on_single_match) {
                    $this->RedirectToPage(array_shift($possible_matches));
                } elseif ($exact_count > 0 || $possible_count > 0) {
                    $this->getOwner()->ContentWithout404Options = DBHTMLVarchar::create()->setValue($this->getOwner()->Content); // keep copy without 404options
                    $this->getOwner()->Intelligent404Options = $this->getOwner()->customise($results_list)->renderWith('Intelligent404Options');
                    $this->getOwner()->Content .= $this->getOwner()->Intelligent404Options; // add to $Content

                    // Provide sanitized search query for templates
                    $replacements = [
                        '/[^A-Za-z0-9\-_.]+/u'  => '', // keep only alphanumeric + dashes/underscores/dots
                        '/[_-]+/u' => ' ', // underscores and dashes to spaces
                    ];
                    $this->getOwner()->SearchQuery = preg_replace(array_keys($replacements), array_values($replacements), (string) $page_key);
                }
            }
        }
    }

    /**
     * The configured `data_objects`, keyed by class name without a leading backslash.
     *
     * This is the one place onAfterInit() reads that config from, so the normalisation is testable
     * without a 404 request. An empty array (also for a missing or non-array config) means "nothing
     * to match against". If a project configures the same class both as `\Product` and as `Product`,
     * the two keys collapse into one and the entry merged in LAST wins; matching the class twice
     * would only list every hit twice.
     *
     * @return array<string, array> class name => its `group`/`filter`/`exclude` config
     */
    public static function getConfiguredClasses(): array
    {
        $data_objects = Config::inst()->get(self::class, 'data_objects');
        if (!$data_objects || !is_array($data_objects)) {
            return [];
        }

        $classes = [];
        foreach ($data_objects as $class => $config) {
            $classes[self::normaliseClassName((string) $class)] = $config;
        }

        return $classes;
    }

    /**
     * Strip a leading backslash from a configured class name.
     *
     * Earlier versions of the README told projects to key `data_objects` as `\Product`. PHP itself
     * accepts that form, but ClassInfo::exists() falls back on the class manifest, which does not,
     * so such an entry was silently skipped unless the class had already been loaded.
     *
     * @param string $class
     * @return string
     */
    public static function normaliseClassName($class)
    {
        return ltrim((string) $class, '\\');
    }

    /*
     * Internal redirect function
     * @param string
     * @return 301 response / redirect
     */
    private function RedirectToPage($url) # : never (never-returns (die/throw method) return type, limits to PHP8.1+)
    {
        $response = HTTPResponse::create();
        $response->redirect($url, 301);
        throw new HTTPResponse_Exception($response);
    }
}
