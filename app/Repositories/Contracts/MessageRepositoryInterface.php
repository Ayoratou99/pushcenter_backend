<?php

namespace App\Repositories\Contracts;

use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;

use Illuminate\Database\Eloquent\Builder;

interface MessageRepositoryInterface
{
    /**
     * Fresh query builder, so callers can compose their own filters.
     */
    public function newQuery(): Builder;

    public function all(?int $perPage = null);
    public function find(int $id): ?Message;
    public function create(array $data): Message;
    public function update(int $id, array $data): ?Message;
    public function delete(int $id): bool;
    public function getByBusiness(int $businessId, ?int $perPage = null);
    public function getByChannel(string $channel, ?int $perPage = null);
    public function getByStatus(string $status, ?int $perPage = null);
    public function getPending(): Collection;
}


