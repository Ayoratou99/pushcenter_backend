<?php

namespace App\Repositories\Contracts;

use App\Models\WhatsappTemplate;

use Illuminate\Database\Eloquent\Builder;

interface WhatsappTemplateRepositoryInterface
{
    /**
     * Fresh query builder, so callers can compose their own filters.
     */
    public function newQuery(): Builder;

    public function all(?int $perPage = null);
    
    public function find(int $id): ?WhatsappTemplate;
    
    public function create(array $data): WhatsappTemplate;
    
    public function update(int $id, array $data): ?WhatsappTemplate;
    
    public function delete(int $id): bool;
    
    public function getByBusiness(int $businessId, ?int $perPage = null);
    
    public function getByStatus(string $status, ?int $perPage = null);
    
    public function getByCategory(string $category, ?int $perPage = null);
    
    public function getByLanguage(string $language, ?int $perPage = null);
    
    public function getActive(?int $perPage = null);
    
    public function getApproved(?int $perPage = null);
    
    public function search(string $search, ?int $perPage = null);
    
    public function activate(int $id): ?WhatsappTemplate;
    
    public function deactivate(int $id): ?WhatsappTemplate;
}

