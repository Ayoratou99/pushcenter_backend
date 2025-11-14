<?php

namespace App\Repositories;

use App\Models\SmtpSetting;
use App\Repositories\Contracts\SmtpRepositoryInterface;

class SmtpRepository implements SmtpRepositoryInterface
{
    protected $model;

    public function __construct(SmtpSetting $model)
    {
        $this->model = $model;
    }

    public function all(?int $perPage = null)
    {
        $query = $this->model->with('business')->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function find(int $id): ?SmtpSetting
    {
        return $this->model->with('business')->find($id);
    }

    public function create(array $data): SmtpSetting
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?SmtpSetting
    {
        $smtpSetting = $this->model->find($id);
        if (!$smtpSetting) return null;
        $smtpSetting->update($data);
        return $smtpSetting->fresh('business');
    }

    public function delete(int $id): bool
    {
        $smtpSetting = $this->model->find($id);
        if (!$smtpSetting) return false;
        return $smtpSetting->delete();
    }
}
