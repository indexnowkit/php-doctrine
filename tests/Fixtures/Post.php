<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

#[ORM\Entity]
#[ORM\Table(name: 'posts')]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'published', fields: ['slug', 'title', 'published'])]
class Post
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(unique: true)]
        public string $slug,
        #[ORM\Column]
        public string $title = 'title',
        #[ORM\Column]
        public bool $published = true,
        #[ORM\Column]
        public int $views = 0,
    ) {}
}
