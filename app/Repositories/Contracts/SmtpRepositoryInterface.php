<?php

namespace App\Repositories\Contracts;

use App\Models\SmtpSetting;
use Illuminate\Database\Eloquent\Collection;

interface SmtpRepositoryInterface
{
    public function all(?int $perPage = null);
    
    public function find(int $id): ?SmtpSetting;
    
    public function create(array $data): SmtpSetting;
    
    public function update(int $id, array $data): ?SmtpSetting;
    
    public function delete(int $id): bool;
}