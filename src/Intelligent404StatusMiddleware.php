<?php

namespace Restruct\Silverstripe\Intelligent404;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Middleware\HTTPMiddleware;

/**
 * Applies the status code a resolver asked for (Intelligent404Resolution::respond(), e.g. 410 Gone).
 *
 * Why a middleware: ErrorPageController::handleRequest() sets the response status to the error page's own
 * ErrorCode AFTER rendering, so a status set while the page renders is always overwritten with 404. The
 * extension leaves a marker header instead, and this middleware, which runs after the controller, swaps the
 * status and removes the marker. Without a marker it does nothing.
 */
class Intelligent404StatusMiddleware implements HTTPMiddleware
{
    public const STATUS_HEADER = 'X-Intelligent404-Status';

    public function process(HTTPRequest $request, callable $delegate)
    {
        $response = $delegate($request);

        $status = $response ? (int) $response->getHeader(self::STATUS_HEADER) : 0;
        if ($status) {
            $response->removeHeader(self::STATUS_HEADER);
            # Only ever turn an error page into another 4xx: never make a 404 look like a success or a redirect
            if ($status >= 400 && $status < 500 && $response->getStatusCode() === 404) {
                $response->setStatusCode($status);
            }
        }

        return $response;
    }
}
