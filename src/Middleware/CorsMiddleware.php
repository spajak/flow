<?php

namespace Flow\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use InvalidArgumentException;

/**
 * Cross-origin resource sharing.
 *
 * Behaviour:
 *   - Requests without an `Origin` header pass through unchanged
 *     (same-origin or non-browser clients).
 *   - Preflight requests (OPTIONS + `Access-Control-Request-Method`) are
 *     answered directly with 204 + the configured CORS headers when the
 *     origin is in the allowlist; otherwise 204 without CORS headers, which
 *     causes the browser to reject the actual request that follows.
 *   - Other cross-origin requests are forwarded to the next handler and
 *     the response is decorated with `Access-Control-Allow-*` headers.
 *
 * Install at (or near) the top of the broker so preflight never reaches
 * routing and every cross-origin response gets decorated regardless of
 * which downstream handler produced it.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private readonly array $allowedOrigins;
    private readonly bool $allowAnyOrigin;
    private readonly string $allowedMethodsHeader;
    private readonly string $allowedHeadersHeader;

    /**
     * @param list<string> $allowedOrigins Exact origins (e.g. `https://wcn.pl`)
     *     or `['*']` to allow any. `['*']` combined with `$allowCredentials`
     *     is rejected — that combination lets any site issue credentialed
     *     cross-origin requests, which is a security misconfiguration.
     * @param list<string> $allowedMethods Methods advertised in preflight
     *     responses.
     * @param list<string> $allowedHeaders Request headers advertised in
     *     preflight responses.
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        array $allowedOrigins,
        array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'],
        private readonly bool $allowCredentials = false,
        private readonly int $maxAge = 86400,
    ) {
        if ($allowedOrigins === []) {
            throw new InvalidArgumentException(
                'CorsMiddleware requires at least one allowed origin (or ["*"]).'
            );
        }
        $this->allowAnyOrigin = $allowedOrigins === ['*'];
        if ($this->allowAnyOrigin && $allowCredentials) {
            throw new InvalidArgumentException(
                'CorsMiddleware: ["*"] origins with $allowCredentials=true is a '
                . 'security misconfiguration; pick an explicit allowlist or drop credentials.'
            );
        }
        $this->allowedOrigins = $allowedOrigins;
        $this->allowedMethodsHeader = implode(', ', $allowedMethods);
        $this->allowedHeadersHeader = implode(', ', $allowedHeaders);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('origin');
        if ($origin === '') {
            return $handler->handle($request);
        }

        if ($this->isPreflight($request)) {
            return $this->preflight($origin);
        }

        return $this->decorate($handler->handle($request), $origin);
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }

    private function preflight(string $origin): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(204);

        if (!$this->isOriginAllowed($origin)) {
            return $response;
        }

        return $this->withCorsHeaders(
            $response
                ->withHeader('access-control-allow-methods', $this->allowedMethodsHeader)
                ->withHeader('access-control-allow-headers', $this->allowedHeadersHeader)
                ->withHeader('access-control-max-age', (string) $this->maxAge),
            $origin
        );
    }

    private function decorate(ResponseInterface $response, string $origin): ResponseInterface
    {
        if (!$this->isOriginAllowed($origin)) {
            return $response;
        }
        return $this->withCorsHeaders($response, $origin);
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $allowOrigin = ($this->allowAnyOrigin && !$this->allowCredentials) ? '*' : $origin;

        $response = $response
            ->withHeader('access-control-allow-origin', $allowOrigin)
            ->withHeader('vary', 'Origin');

        if ($this->allowCredentials) {
            $response = $response->withHeader('access-control-allow-credentials', 'true');
        }

        return $response;
    }

    private function isOriginAllowed(string $origin): bool
    {
        return $this->allowAnyOrigin || in_array($origin, $this->allowedOrigins, true);
    }
}
