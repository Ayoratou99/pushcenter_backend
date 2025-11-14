<?php

namespace App\Repositories\Contracts;

use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;

interface MessageRepositoryInterface
{
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


