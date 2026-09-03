<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;

/**
 * Multi-rule entity: a plain page, an AMP variant that additionally requires `hasAmp`, and the homepage.
 * `isPublished()` is a getter over the `$published` column (different name), exercising UrlRule::fieldCandidates().
 */
#[ORM\Entity]
#[ORM\Table(name: 'multi_posts')]
#[IndexNowDefaults(when: 'isPublished')]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp')]
#[IndexNow(urls: ['/'])]
class MultiPost
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(unique: true)]
        public string $slug,
        #[ORM\Column]
        public bool $published = true,
        #[ORM\Column]
        public bool $amp = false,
    ) {}

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function hasAmp(): bool
    {
        return $this->amp;
    }
}
