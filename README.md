[![Project Management](https://img.shields.io/badge/project-management-blue.svg)](https://waffle.io/neomerx/cors-psr7)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/neomerx/cors-psr7/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/neomerx/cors-psr7/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/neomerx/cors-psr7/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/neomerx/cors-psr7/?branch=master)
[![Build Status](https://travis-ci.org/neomerx/cors-psr7.svg?branch=master)](https://travis-ci.org/neomerx/cors-psr7)
[![HHVM](https://img.shields.io/hhvm/neomerx/cors-psr7.svg)](https://travis-ci.org/neomerx/cors-psr7)
[![License](https://img.shields.io/packagist/l/neomerx/cors-psr7.svg)](https://packagist.org/packages/neomerx/cors-psr7)

## Description

This package has framework agnostic [Cross-Origin Resource Sharing](www.w3.org/TR/cors/) (CORS) implementation. It is complaint with [PSR-7](http://www.php-fig.org/psr/psr-7/) HTTP message interfaces.

Why this package?

- Implements the latest [CORS](www.w3.org/TR/cors/).
- Works with [PSR-7](http://www.php-fig.org/psr/psr-7/) interfaces.
- Flexible, modular and extensible solution.
- High code quality. **100%** test coverage.
- Free software license [Apache 2.0](LICENSE).

## Sample usage

The package is designed to be used as a middleware. Typical usage

```php
use \Neomerx\Cors\Analyzer;
use \Psr\Http\Message\RequestInterface;
use \Neomerx\Cors\Contracts\AnalysisResultInterface;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param RequestInterface $request
     * @param Closure          $next
     *
     * @return mixed
     */
    public function handle(RequestInterface $request, Closure $next)
    {
        $cors = Analyzer::instance($this->getCorsSettings())->analyze($request);
        
        switch ($cors->getRequestType()) {
            case AnalysisResultInterface::TYPE_BAD_REQUEST:
                // return 400 HTTP error
                return ...;

            case AnalysisResultInterface::TYPE_PRE_FLIGHT_REQUEST:
                $corsHeaders = $cors->getResponseHeaders();
                // return 200 HTTP with $corsHeaders
                return ...;

            case AnalysisResultInterface::TYPE_REQUEST_OUT_OF_CORS_SCOPE:
                // call next middleware handler
                return $next($request);
            
            default:
                // actual CORS request
                $response    = $next($request);
                $corsHeaders = $cors->getResponseHeaders();
                
                // add CORS headers to Response $response
                ...
                return $response;
        }
    }
}
```

## Install

```
composer require neomerx/cors-psr7
```

## Testing

```
composer test
```

## Questions?

Do not hesitate to contact us on [![Gitter](https://badges.gitter.im/Join%20Chat.svg)](https://gitter.im/neomerx/json-api) or post an [issue](https://github.com/neomerx/cors-psr7/issues).

## Contributing

If you have spotted any compliance issues with the [CORS Recommendation](http://www.w3.org/TR/cors/) please post an [issue](https://github.com/neomerx/cors-psr7/issues). Pull requests for documentation and code improvements (PSR-2, tests) are welcome.

Current tasks are managed with [Waffle.io](https://waffle.io/neomerx/cors-psr7).

## Versioning

This package is using [Semantic Versioning](http://semver.org/).

## License

Apache License (Version 2.0). Please see [License File](LICENSE) for more information.
