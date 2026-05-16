<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use MongoDB\Laravel\Connection;
use Override;

abstract class TestCase extends BaseTestCase
{
    /**
     * Neither RefreshDatabase nor DatabaseTruncation are compatible with MongoDB,
     * so we drop all collections manually before each test.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropAllMongoCollections();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            static::markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    private function dropAllMongoCollections(): void
    {
        $connection = DB::connection('mongodb');
        assert($connection instanceof Connection);
        $database = $connection->getMongoDB();

        foreach ($database->listCollections() as $collection) {
            $database->dropCollection($collection->getName());
        }
    }
}
