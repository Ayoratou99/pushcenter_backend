<?php

namespace App\Repositories\Contracts;

use App\Models\EmailTemplate;

interface EmailTemplateRepositoryInterface
{
    public function all(?int $perPage = null);
    
    public function find(int $id): ?EmailTemplate;
    
    public function create(array $data): EmailTemplate;
    
    public function update(int $id, array $data): ?EmailTemplate;
    
    public function delete(int $id): bool;
    
    public function getByBusiness(int $businessId, ?int $perPage = null);
    
    public function getByStatus(string $status, ?int $perPage = null);
    
    public function getByCategory(string $category, ?int $perPage = null);
    
    public function getActive(?int $perPage = null);
    
    public function search(string $search, ?int $perPage = null);
    
    public function activate(int $id): ?EmailTemplate;
    
    public function deactivate(int $id): ?EmailTemplate;
}

