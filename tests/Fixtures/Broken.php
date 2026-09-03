<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

/** Attribute points to a property that does not exist: resolver must fail without breaking the flush. */
#[ORM\Entity]
#[ORM\Table(name: 'broken')]
#[IndexNow(route: 'broken_show', params: ['x' => 'missingProperty'])]
class Broken
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $name) {}
}
