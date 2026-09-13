<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Tests\Fixtures;

use Codenzia\FilamentWorkflow\Concerns\HasWorkflows;
use Illuminate\Database\Eloquent\Model;

class WatchedModel extends Model
{
    use HasWorkflows;

    protected $table = 'test_models';

    protected $guarded = [];
}
