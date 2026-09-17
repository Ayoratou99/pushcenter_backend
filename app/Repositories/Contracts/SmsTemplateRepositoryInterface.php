<?php

namespace App\Repositories\Contracts;

use App\Models\SmsTemplate;

use Illuminate\Database\Eloquent\Builder;

interface SmsTemplateRepositoryInterface
{
    /**
     * Fresh query builder, so callers can compose their own filters.
     */
    public function newQuery(): Builder;

    public function all(?int $perPage = null);
    
    public function find(int $id): ?SmsTemplate;
    
    public function create(array $data): SmsTemplate;
    
    public function update(int $id, array $data): ?SmsTemplate;
    
    public function delete(int $id): bool;
    
    public function getByBusiness(int $businessId, ?int $perPage = null);
    
    public function getByStatus(string $status, ?int $perPage = null);
    
    public function getByCategory(string $category, ?int $perPage = null);
    
    public function getActive(?int $perPage = null);
    
    public function search(string $search, ?int $perPage = null);
    
    public function activate(int $id): ?SmsTemplate;
    
    public function deactivate(int $id): ?SmsTemplate;
}

