<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\Conformance\CoreConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;

/**
 * The core conformance scenarios (C01–C20) against the facade the Doctrine wiring is built around: the protocol layer
 * (batching, dedup, status mapping, normalization) of the graph `DoctrineTestCase` assembles, as the three framework
 * adapters run it against theirs.
 */
final class CoreConformanceTest extends CoreConformanceTestCase
{
    private DoctrineHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new DoctrineHarness();
    }

    protected function kit(): IndexNowKit
    {
        return $this->harness->indexNow;
    }

    protected function transport(): FakeTransport
    {
        return $this->harness->transport;
    }
}
