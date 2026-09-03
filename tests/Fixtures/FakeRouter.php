<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use IndexNowKit\Url\RouteUrlResolverInterface;

final class FakeRouter implements RouteUrlResolverInterface
{
    public function generate(string $route, array $params, array|string $locales): iterable
    {
        return match ($route) {
            'post_show' => ['https://www.example.com/posts/' . (string) $params['slug']],
            default => ['https://www.example.com/' . $route],
        };
    }
}
