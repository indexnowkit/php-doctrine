<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use IndexNowKit\Url\RouteUrlResolverInterface;

final class FakeRouter implements RouteUrlResolverInterface
{
    public function locales(array|string $locales): array
    {
        return [null];
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        $slugValue = $params['slug'] ?? '';
        $slug = \is_scalar($slugValue) ? (string) $slugValue : '';

        return match ($route) {
            'post_show' => 'https://www.example.com/posts/' . $slug,
            'post_amp' => 'https://www.example.com/amp/' . $slug,
            'category_show' => 'https://www.example.com/categories/' . $slug,
            default => 'https://www.example.com/' . $route,
        };
    }
}
