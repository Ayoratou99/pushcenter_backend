<?php

namespace App\Repositories\Contracts;

use App\Models\Template;
use Illuminate\Database\Eloquent\Collection;

use Illuminate\Database\Eloquent\Builder;

interface TemplateRepositoryInterface
{
    /**
     * Fresh query builder, so callers can compose their own filters.
     */
    public function newQuery(): Builder;

    public function all(?int $perPage = null);
    
    public function find(int $id): ?Template;
    
    public function create(array $data): Template;
    
    public function update(int $id, array $data): ?Template;
    
    public function delete(int $id): bool;
    
    public function getByBusiness(int $businessId, ?int $perPage = null);
    
    public function getByType(string $type, ?int $perPage = null);
    
    public function getByStatus(string $status, ?int $perPage = null);
    
    public function getByCategory(string $category, ?int $perPage = null);
    
    public function search(string $search, ?int $perPage = null);
    
    public function activate(int $id): ?Template;
    
    public function deactivate(int $id): ?Template;
}

