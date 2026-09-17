<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use Illuminate\Database\Eloquent\Collection;

use Illuminate\Database\Eloquent\Builder;

interface BusinessRepositoryInterface
{
    /**
     * Fresh query builder, so callers can compose their own filters.
     */
    public function newQuery(): Builder;

    public function all(?int $perPage = null);
    
    public function find(int $id): ?Business;
    
    public function create(array $data): Business;
    
    public function update(int $id, array $data): ?Business;
    
    public function delete(int $id): bool;
    
    public function getByStatus(string $status, ?int $perPage = null);
    
    public function search(string $search, ?int $perPage = null);
    
    public function getStats(int $id): ?array;
}
