<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class() extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('query_runs', function (Blueprint $collection): void {
            $collection->unique('slug');
            $collection->index('user_id');
            $collection->index('created_at');
            $collection->index(['locale', 'rating', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_runs');
    }
};
