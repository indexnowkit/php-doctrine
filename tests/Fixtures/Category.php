<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
#[IndexNow(route: 'category_show', params: ['slug' => 'slug'])]
class Category
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column(unique: true)] public string $slug) {}
}
