<?php

namespace Ahinest\LaravelPermissionManager\Tests\Fixtures;

class StaffUser extends User
{
    protected $table = 'users';

    protected $guard_name = 'staff';
}
