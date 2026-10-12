<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Transformer;

use function count;
use function is_int;
use function is_string;
use function strlen;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Memoizes the router's output for the image route, per image and set of query keys.
 *
 * A srcset asks the router for dozens of URLs of one image that only differ by
 * query values (width, format, signature). The router is asked once with
 * placeholder values; the next URLs substitute the real values into that output.
 * Only values the router would leave unencoded (RFC 3986 unreserved characters)
 * are substituted, anything else goes through the router. Templates are dropped
 * whenever the request context changes (base URL, host, worker mode requests).
 *
 * @internal
 */
final class UrlTemplates
{
    private const MAX_TEMPLATES = 64;

    /** @var array<string, non-empty-list<string>|false> URL pieces around the query values */
    private array $templates = [];

    /** @var list<mixed> */
    private array $contextSnapshot = [];

    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

    /**
     * @param array<string, string> $routeParameters Path variables of the route
     * @param array<string, scalar> $query           Query parameters, in their URL order
     */
    public function generate(string $route, array $routeParameters, array $query): string
    {
        foreach ($query as $value) {
            // Only ints and strings print the same through a string cast as through http_build_query() (not
            // false, nor every float)
            if ((!is_int($value) && !is_string($value)) || rawurlencode((string) $value) !== (string) $value) {
                return $this->router->generate($route, [...$routeParameters, ...$query], UrlGeneratorInterface::ABSOLUTE_PATH);
            }
        }

        $context = $this->router->getContext();
        $snapshot = [$context->getBaseUrl(), $context->getPathInfo(), $context->getScheme(), $context->getHost(), $context->getHttpPort(), $context->getHttpsPort(), $context->getParameters()];
        if ($snapshot !== $this->contextSnapshot || count($this->templates) >= self::MAX_TEMPLATES) {
            $this->templates = [];
            $this->contextSnapshot = $snapshot;
        }

        $key = $route . "\0" . implode("\0", $routeParameters) . "\0" . implode("\0", array_keys($query));
        $template = $this->templates[$key] ??= $this->template($route, $routeParameters, array_keys($query));
        if (false === $template) {
            return $this->router->generate($route, [...$routeParameters, ...$query], UrlGeneratorInterface::ABSOLUTE_PATH);
        }

        $url = $template[0];
        $i = 0;
        foreach ($query as $value) {
            $url .= $value . $template[++$i];
        }

        return $url;
    }

    /**
     * @param array<string, string> $routeParameters
     * @param list<string>          $keys
     *
     * @return non-empty-list<string>|false
     */
    private function template(string $route, array $routeParameters, array $keys): array|false
    {
        $placeholders = [];
        foreach ($keys as $i => $key) {
            $placeholders[$key] = $this->placeholder($i);
        }

        $url = $this->router->generate($route, [...$routeParameters, ...$placeholders], UrlGeneratorInterface::ABSOLUTE_PATH);

        // Each placeholder must stand exactly once for its value, in query order, or the template is unusable
        $pieces = [];
        $offset = 0;
        foreach ($placeholders as $placeholder) {
            $position = strpos($url, $placeholder, $offset);
            if (false === $position || 1 !== substr_count($url, $placeholder)) {
                return false;
            }
            $pieces[] = substr($url, $offset, $position - $offset);
            $offset = $position + strlen($placeholder);
        }
        $pieces[] = substr($url, $offset);

        return $pieces;
    }

    private function placeholder(int $i): string
    {
        return 'PicassoPh' . $i . 'Z';
    }
}
