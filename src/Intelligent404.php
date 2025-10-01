<?php

namespace Restruct\Silverstripe\Intelligent404;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ErrorPage\ErrorPageController;
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
            $extract = preg_match('/^([a-z0-9\.\_\-\/]+)/i', (string) $_SERVER['REQUEST_URI'], $rawString);

            if ($extract) {
                $uri = preg_replace('/\.(aspx?|html?|php[34]?)$/i', '', $rawString[0]); // skip known page extensions
                $parts = preg_split('/\//', (string) $uri, -1, PREG_SPLIT_NO_EMPTY);
                $page_key = array_pop($parts);
                $sounds_like = soundex((string) $page_key);
                $exact_matches = [];
                $possible_matches = [];
                $results_list = [];

                $data_objects = Config::inst()->get(self::class, 'data_objects');
                if (!$data_objects || !is_array($data_objects)) {
                    return;
                }

                foreach ($data_objects as $class => $config) {
                    if (
                        !ClassInfo::exists($class) ||
                        !method_exists($class, 'Link')
                    ) {
                        continue; // invalid class (does not exist)
                    }

                    $group = !empty($config['group']) ? $config['group'] : 'Pages';

                    if (empty($results_list[$group])) {
                        $results_list[$group] = ArrayList::create();
                    }

                    $results = $class::get(); // all results

                    if (!empty($config['filter'])) {
                        $results = $results->filter($config['filter']); // filter
                    }

                    if (!empty($config['exclude'])) {
                        $results = $results->exclude($config['exclude']); // exclude
                    }

                    foreach ($results as $result) {
                        $link = $result->Link();

                        $rel_link = Director::makeRelative($link);

                        if (!$rel_link) {
                            continue; // no link or `/`
                        }

                        $url_parts = preg_split('/\//', $rel_link, -1, PREG_SPLIT_NO_EMPTY);

                        $url_segment = end($url_parts);

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
