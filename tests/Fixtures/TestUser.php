<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class TestUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
