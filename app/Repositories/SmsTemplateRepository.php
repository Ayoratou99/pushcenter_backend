<?php

namespace App\Repositories;

use App\Models\SmsTemplate;
use App\Repositories\Contracts\SmsTemplateRepositoryInterface;

class SmsTemplateRepository implements SmsTemplateRepositoryInterface
{
    protected $model;

    public function __construct(SmsTemplate $model)
    {
        $this->model = $model;
    }

    public function all(?int $perPage = null)
    {
        $query = $this->model->with('business')->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function find(int $id): ?SmsTemplate
    {
        return $this->model->with('business')->find($id);
    }

    public function create(array $data): SmsTemplate
    {
        $template = $this->model->create($data);
        
        // Calculate segments after creation
        if (isset($data['message'])) {
            $template->calculateSegments();
            $template->save();
        }
        
        return $template;
    }

    public function update(int $id, array $data): ?SmsTemplate
    {
        $template = $this->model->find($id);
        if (!$template) return null;
        
        $template->update($data);
        
        // Recalculate segments if message changed
        if (isset($data['message'])) {
            $template->calculateSegments();
            $template->save();
        }
        
        return $template->fresh('business');
    }

    public function delete(int $id): bool
    {
        $template = $this->model->find($id);
        return $template ? $template->delete() : false;
    }

    public function getByBusiness(int $businessId, ?int $perPage = null)
    {
        $query = $this->model->where('business_id', $businessId)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getByStatus(string $status, ?int $perPage = null)
    {
        $query = $this->model->where('status', $status)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getByCategory(string $category, ?int $perPage = null)
    {
        $query = $this->model->byCategory($category)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getActive(?int $perPage = null)
    {
        $query = $this->model->active()->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function search(string $search, ?int $perPage = null)
    {
        $query = $this->model->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('message', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        })->latest();
        
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function activate(int $id): ?SmsTemplate
    {
        $template = $this->model->find($id);
        if (!$template) return null;
        
        $template->update([
            'status' => 'active',
            'is_active' => true,
        ]);
        
        return $template->fresh('business');
    }

    public function deactivate(int $id): ?SmsTemplate
    {
        $template = $this->model->find($id);
        if (!$template) return null;
        
        $template->update([
            'status' => 'draft',
            'is_active' => false,
        ]);
        
        return $template->fresh('business');
    }
}

