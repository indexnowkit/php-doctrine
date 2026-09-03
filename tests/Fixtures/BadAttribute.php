<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

/** Attribute without route or resolver: its constructor throws when read. The flush must survive. */
#[ORM\Entity]
#[ORM\Table(name: 'bad_attribute')]
#[IndexNow(events: ['created'])]
class BadAttribute
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $name) {}
}
