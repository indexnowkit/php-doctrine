<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

/**
 * A post that resubmits its category's page (`via`) and whose tags (ManyToMany) trigger an update
 * even though the collection change is not part of the owner's own change set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'categorized_posts')]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(via: 'category')]
class CategorizedPost
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    public ?Category $category = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'categorized_post_tags')]
    public Collection $tags;

    #[ORM\Column]
    public int $views = 0;

    public function __construct(#[ORM\Column(unique: true)] public string $slug)
    {
        $this->tags = new ArrayCollection();
    }
}
