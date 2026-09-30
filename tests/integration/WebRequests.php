<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Craft;
use craft\web\Request;

/**
 * Runs part of a test as a web request, and posts to Craft's own controller actions as a browser
 * would.
 *
 * Craft refuses a session to a request without a user agent and an address
 * (`requireUserAgentAndIpForSession`), and the CLI has neither, so both are set for the duration.
 */
trait WebRequests
{
    private function asWebRequest(Closure $callback, bool $cp = false): mixed
    {
        /** @var Request $request */
        $request = Craft::$app->getRequest();
        $console = $request->getIsConsoleRequest();
        $request->setIsConsoleRequest(false);
        $request->setIsCpRequest($cp);
        $request->getHeaders()->set('User-Agent', 'AuditorTest/1.0');
        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';

        try {
            return $callback();
        } finally {
            $request->getHeaders()->remove('User-Agent');
            if ($address === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $address;
            }
            $request->setIsCpRequest(null);
            $request->setIsConsoleRequest($console);
        }
    }

    /**
     * Posts a JSON request to a Craft action, with CSRF validation off. Call it inside
     * {@see asWebRequest()}.
     *
     * @param array<string, mixed> $params
     */
    private function post(string $route, array $params): mixed
    {
        /** @var Request $request */
        $request = Craft::$app->getRequest();
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $csrf = $request->enableCsrfValidation;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request->enableCsrfValidation = false;
        $request->getHeaders()->set('Accept', 'application/json');
        $request->setBodyParams($params);

        try {
            return Craft::$app->runAction($route);
        } finally {
            $request->setBodyParams([]);
            $request->getHeaders()->remove('Accept');
            $request->enableCsrfValidation = $csrf;
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }
    }
}
