<?php

namespace Ahinest\LaravelPermissionManager\Tests\Fixtures;

use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class RecordingAuditStore implements TransactionalAuditStore
{
    public bool $fail = false;

    public ?Connection $overrideConnection = null;

    public function connection(): Connection
    {
        return $this->overrideConnection ?? DB::connection();
    }

    public function tables(): array
    {
        return ['test_authority_audits'];
    }

    public function record(AuthorityAudit $event, Connection $connection): void
    {
        if ($connection !== $this->connection() || $connection->transactionLevel() < 1) {
            throw new \LogicException('Wrong audit transaction.');
        }
        $connection->table('test_authority_audits')->insert(['payload' => json_encode($event, JSON_THROW_ON_ERROR)]);
        if ($this->fail) {
            throw new \RuntimeException('audit fixture failure');
        }
    }
}
