<?php

namespace Restruct\Silverstripe\Intelligent404;

/**
 * What a resolver decided for a 404, passed by reference through the `updateIntelligent404Resolution` hook.
 *
 * A resolver does ONE of two things, and the first resolver that does wins: later redirect()/respond() calls are
 * ignored (check isResolved() to skip work), and the built-in fuzzy matching is skipped:
 *  - redirect(): send the visitor elsewhere (301 by default), e.g. a page that moved;
 *  - respond(): keep the visitor on the error page, with its own content and optionally another status code,
 *    e.g. 410 Gone with a "no longer available" text and alternatives. The page still renders through the
 *    normal ErrorPage template, so themes keep working; only Content (and optionally Title) change.
 */
class Intelligent404Resolution
{
    /** @var string the requested path, URL-decoded, without leading/trailing slash or querystring */
    public string $path;

    /** @var string the requested querystring, without '?' ('' if none) */
    public string $query;

    /** @var string|null redirect target (absolute or site-relative), set by redirect() */
    public ?string $redirectTo = null;

    /** @var int status code for the redirect */
    public int $redirectCode = 301;

    /** @var string|null HTML for the error page, set by respond() */
    public ?string $content = null;

    /** @var bool replace the error page's own Content (true) or append to it (false) */
    public bool $replaceContent = false;

    /** @var string|null page title to show instead of the error page's own */
    public ?string $title = null;

    /** @var int|null status code to send instead of the error page's own (e.g. 410) */
    public ?int $statusCode = null;

    /** @var string|null who resolved it, for debugging and tests (class name or short label) */
    public ?string $resolvedBy = null;

    public function __construct(string $path, string $query = '')
    {
        $this->path = $path;
        $this->query = $query;
    }

    public function isResolved(): bool
    {
        return $this->redirectTo !== null || $this->content !== null;
    }

    /** Status codes redirect() accepts */
    public const REDIRECT_CODES = [301, 302, 303, 307, 308];

    /**
     * Send the visitor to $url: an absolute http(s) URL, or a site path starting with ONE slash (protocol-relative
     * and backslash targets are refused when acted on, as is the URL that 404'd). The original querystring is
     * NOT appended here; pass it in $url if wanted. Ignored when an earlier resolver already resolved the 404.
     *
     * @throws \InvalidArgumentException for a status code that is not a redirect (see REDIRECT_CODES), unless the
     *   404 was already resolved: a later resolver's bad call is then ignored like any other, not turned into a 500
     */
    public function redirect(string $url, int $code = 301, ?string $resolvedBy = null): static
    {
        # First resolution wins, whatever the order of redirect() and respond() calls
        if ($this->isResolved()) {
            return $this;
        }
        if (!in_array($code, self::REDIRECT_CODES, true)) {
            throw new \InvalidArgumentException("Intelligent404Resolution::redirect(): {$code} is not a redirect status");
        }
        $this->redirectTo = $url;
        $this->redirectCode = $code;
        $this->resolvedBy = $resolvedBy;
        return $this;
    }

    /**
     * Keep the visitor on the error page with this content, and optionally another status code and title.
     * Ignored when an earlier resolver already resolved the 404.
     */
    public function respond(string $html, ?int $statusCode = null, bool $replaceContent = false, ?string $title = null, ?string $resolvedBy = null): static
    {
        if ($this->isResolved()) {
            return $this;
        }
        $this->content = $html;
        $this->statusCode = $statusCode;
        $this->replaceContent = $replaceContent;
        $this->title = $title;
        $this->resolvedBy = $resolvedBy;
        return $this;
    }
}
