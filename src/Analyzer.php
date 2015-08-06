<?php namespace Neomerx\Cors;

/**
 * Copyright 2015 info@neomerx.com (www.neomerx.com)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use \Psr\Http\Message\RequestInterface;
use \Neomerx\Cors\Contracts\AnalyzerInterface;
use \Neomerx\Cors\Contracts\Http\ParsedUrlInterface;
use \Neomerx\Cors\Contracts\AnalysisResultInterface;
use \Neomerx\Cors\Contracts\Factory\FactoryInterface;
use \Neomerx\Cors\Contracts\AnalysisStrategyInterface;
use \Neomerx\Cors\Contracts\Constants\CorsRequestHeaders;
use \Neomerx\Cors\Contracts\Constants\CorsResponseHeaders;
use \Neomerx\Cors\Contracts\Constants\SimpleRequestHeaders;
use \Neomerx\Cors\Contracts\Constants\SimpleRequestMethods;

/**
 * @package Neomerx\Cors
 */
class Analyzer implements AnalyzerInterface
{
    /** HTTP method for pre-flight request */
    const PRE_FLIGHT_METHOD = 'OPTIONS';

    /**
     * @var array
     */
    private $simpleMethods = [
        SimpleRequestMethods::GET  => true,
        SimpleRequestMethods::HEAD => true,
        SimpleRequestMethods::POST => true,
    ];

    /**
     * @var array
     */
    private $simpleHeadersExclContentType = [
        SimpleRequestHeaders::ACCEPT,
        SimpleRequestHeaders::ACCEPT_LANGUAGE,
        SimpleRequestHeaders::CONTENT_LANGUAGE,
    ];

    /**
     * @var AnalysisStrategyInterface
     */
    private $strategy;

    /**
     * @var FactoryInterface
     */
    private $factory;

    /**
     * @param AnalysisStrategyInterface $strategy
     * @param FactoryInterface          $factory
     */
    public function __construct(AnalysisStrategyInterface $strategy, FactoryInterface $factory)
    {
        $this->factory  = $factory;
        $this->strategy = $strategy;
    }

    /**
     * @inheritdoc
     *
     * @see http://www.w3.org/TR/cors/#resource-processing-model
     */
    public function analyze(RequestInterface $request)
    {
        $headers  = [];

        $serverOrigin = $this->factory->createParsedUrl($this->strategy->getServerOrigin());

        // Check of Host header is strongly encouraged by 6.3
        if ($this->isSameHost($request, $serverOrigin) === false) {
            return $this->createResult(AnalysisResultInterface::TYPE_BAD_REQUEST, $headers);
        }

        // Request handlers for non-CORS, simple CORS, actual CORS and pre-flight requests have some common part
        // 6.1.1 - 6.1.2 and 6.2.1 - 6.2.2

        // Header 'Origin' might be omitted for same-origin requests
        $requestOrigin = $this->getOrigin($request);
        if ($requestOrigin === null ||
            $this->isCrossOrigin($requestOrigin, $serverOrigin) === false ||
            $this->strategy->isRequestOriginAllowed($requestOrigin) === false
        ) {
            return $this->createResult(AnalysisResultInterface::TYPE_REQUEST_OUT_OF_CORS_SCOPE, $headers);
        }

        // Since this point handlers have their own path for
        // - simple CORS and actual CORS request (6.1.3 - 6.1.4)
        // - pre-flight request (6.2.3 - 6.2.10)

        if ($request->getMethod() === self::PRE_FLIGHT_METHOD) {
            return $this->analyzeAsPreFlight($request, $requestOrigin);
        } else {
            return $this->analyzeAsRequest($request, $requestOrigin);
        }
    }

    /**
     * Analyze request as simple CORS or/and actual CORS request (6.1.3 - 6.1.4).
     *
     * @param RequestInterface   $request
     * @param ParsedUrlInterface $requestOrigin
     *
     * @return AnalysisResultInterface
     */
    private function analyzeAsRequest(RequestInterface $request, ParsedUrlInterface $requestOrigin)
    {
        $headers = [];

        // 6.1.3
        $headers[CorsResponseHeaders::ALLOW_ORIGIN] = $requestOrigin->getOrigin();
        if ($this->strategy->isRequestCredentialsSupported($request) === true) {
            $headers[CorsResponseHeaders::ALLOW_CREDENTIALS] = CorsResponseHeaders::VALUE_ALLOW_CREDENTIALS_TRUE;
        }

        // 6.1.4
        $exposedHeaders = $this->strategy->getResponseExposedHeaders($request);
        if (empty($exposedHeaders) === false) {
            $headers[CorsResponseHeaders::EXPOSE_HEADERS] = $exposedHeaders;
        }

        return $this->createResult(AnalysisResultInterface::TYPE_ACTUAL_REQUEST, $headers);
    }

    /**
     * Analyze request as simple CORS or/and actual CORS request (6.2.3 - 6.2.10).
     *
     * @param RequestInterface   $request
     * @param ParsedUrlInterface $requestOrigin
     *
     * @return AnalysisResultInterface
     */
    private function analyzeAsPreFlight(RequestInterface $request, ParsedUrlInterface $requestOrigin)
    {
        $headers = [];

        // 6.2.3
        $requestMethod = $request->getHeader(CorsRequestHeaders::METHOD);
        if (empty($requestMethod) === true) {
            return $this->createResult(AnalysisResultInterface::TYPE_REQUEST_OUT_OF_CORS_SCOPE, $headers);
        } else {
            $requestMethod = $requestMethod[0];
        }

        // OK now we are sure it's a pre-flight request

        /** @var string $requestMethod */

        // 6.2.4
        /** @var string[] $requestHeaders */
        $requestHeaders = $request->getHeader(CorsRequestHeaders::HEADERS);
        if (empty($requestHeaders) === false) {
            // after explode header names might have spaces in the beginnings and ends...
            $requestHeaders = explode(CorsRequestHeaders::HEADERS_SEPARATOR, $requestHeaders[0]);
            // ... so trim the spaces
            $requestHeaders = array_map(function ($headerName) {
                return trim($headerName);
            }, $requestHeaders);
        }

        // 6.2.5
        // 6.2.6
        if ($this->strategy->isRequestMethodSupported($requestMethod) === false ||
            $this->strategy->isRequestAllHeadersSupported($requestHeaders) === false) {
            return $this->createResult(AnalysisResultInterface::TYPE_PRE_FLIGHT_REQUEST, $headers);
        }

        // 6.2.7
        $headers[CorsResponseHeaders::ALLOW_ORIGIN] = $requestOrigin->getOrigin();
        if ($this->strategy->isRequestCredentialsSupported($request) === true) {
            $headers[CorsResponseHeaders::ALLOW_CREDENTIALS] = CorsResponseHeaders::VALUE_ALLOW_CREDENTIALS_TRUE;
        }

        // 6.2.8
        if ($this->strategy->isPreFlightCanBeCached($request) === true) {
            $headers[CorsResponseHeaders::MAX_AGE] = $this->strategy->getPreFlightCacheMaxAge($request);
        }

        // 6.2.9
        $isSimpleMethod = isset($this->simpleMethods[$requestMethod]);
        if ($isSimpleMethod === false || $this->strategy->isForceAddAllowedMethodsToPreFlightResponse() === true) {
            $headers[CorsResponseHeaders::ALLOW_METHODS] =
                $this->strategy->getRequestAllowedMethods($request, $requestMethod);
        }

        // 6.2.10
        // Has only 'simple' headers excluding Content-Type
        $isSimpleExclCT = empty(array_intersect($requestHeaders, $this->simpleHeadersExclContentType));
        if ($isSimpleExclCT === false || $this->strategy->isForceAddAllowedHeadersToPreFlightResponse() === true) {
            $headers[CorsResponseHeaders::ALLOW_HEADERS] =
                $this->strategy->getRequestAllowedHeaders($request, $requestHeaders);
        }

        return $this->createResult(AnalysisResultInterface::TYPE_PRE_FLIGHT_REQUEST, $headers);
    }

    /**
     * @param RequestInterface   $request
     * @param ParsedUrlInterface $serverOrigin
     *
     * @return bool
     */
    private function isSameHost(RequestInterface $request, ParsedUrlInterface $serverOrigin)
    {
        // Header 'Host' must present rfc2616 14.23

        $hostHeaderValue = $request->getHeader(CorsRequestHeaders::HOST);
        $hostUrl = empty($hostHeaderValue) === true ? null : $this->factory->createParsedUrl($hostHeaderValue[0]);

        $isSameHost =
            $hostUrl !== null &&
            $serverOrigin->isPortEqual($hostUrl) === true &&
            $serverOrigin->isHostEqual($hostUrl) === true;

        return $isSameHost;
    }

    /**
     * @param ParsedUrlInterface $requestOrigin
     * @param ParsedUrlInterface $serverOrigin
     *
     * @return bool
     *
     * @see http://tools.ietf.org/html/rfc6454#section-5
     */
    private function isSameOrigin(ParsedUrlInterface $requestOrigin, ParsedUrlInterface $serverOrigin)
    {
        $isSameOrigin =
            $requestOrigin->isHostEqual($serverOrigin) === true &&
            $requestOrigin->isPortEqual($serverOrigin) === true &&
            $requestOrigin->isSchemeEqual($serverOrigin) === true;

        return $isSameOrigin;
    }

    /**
     * @param ParsedUrlInterface $requestOrigin
     * @param ParsedUrlInterface $serverOrigin
     *
     * @return bool
     */
    private function isCrossOrigin(ParsedUrlInterface $requestOrigin, ParsedUrlInterface $serverOrigin)
    {
        return $this->isSameOrigin($requestOrigin, $serverOrigin) === false;
    }

    /**
     * @param RequestInterface $request
     *
     * @return ParsedUrlInterface|null
     */
    private function getOrigin(RequestInterface $request)
    {
        $origin = null;
        if ($request->hasHeader(CorsRequestHeaders::ORIGIN) === true) {
            $headerValue = $request->getHeader(CorsRequestHeaders::ORIGIN);
            empty($headerValue) === false ? $origin = $this->factory->createParsedUrl($headerValue[0]) : null;

        }

        return $origin;
    }

    /**
     * @param int   $type
     * @param array $headers
     *
     * @return AnalysisResultInterface
     */
    private function createResult($type, array $headers)
    {
        return $this->factory->createAnalysisResult($type, $headers);
    }

    /**
     * Create analyzer instance.
     *
     * @param AnalysisStrategyInterface $strategy
     *
     * @return AnalyzerInterface
     */
    public static function instance(AnalysisStrategyInterface $strategy)
    {
        return static::getFactory()->createAnalyzer($strategy);
    }

    /**
     * @return FactoryInterface
     */
    protected static function getFactory()
    {
        /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection */
        return new \Neomerx\Cors\Factory\Factory();
    }
}