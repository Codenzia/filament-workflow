<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TestModel extends Model
{
    protected $table = 'test_models';

    protected $guarded = [];

    public ?int $project_id = null;
}
