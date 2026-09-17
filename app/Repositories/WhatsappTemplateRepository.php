<?php

namespace App\Repositories;

use App\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Builder;
use App\Repositories\Contracts\WhatsappTemplateRepositoryInterface;

class WhatsappTemplateRepository implements WhatsappTemplateRepositoryInterface
{
    protected $model;

    public function __construct(WhatsappTemplate $model)
    {
        $this->model = $model;
    }

    public function newQuery(): Builder
    {
        return $this->model->newQuery();
    }

    public function all(?int $perPage = null)
    {
        $query = $this->model->with('business')->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function find(int $id): ?WhatsappTemplate
    {
        return $this->model->with('business')->find($id);
    }

    public function create(array $data): WhatsappTemplate
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?WhatsappTemplate
    {
        $template = $this->model->find($id);
        if (!$template) return null;
        $template->update($data);
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

    public function getByLanguage(string $language, ?int $perPage = null)
    {
        $query = $this->model->byLanguage($language)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getActive(?int $perPage = null)
    {
        $query = $this->model->active()->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getApproved(?int $perPage = null)
    {
        $query = $this->model->approved()->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function search(string $search, ?int $perPage = null)
    {
        $query = $this->model->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('display_name', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%")
              ->orWhere('body', 'like', "%{$search}%");
        })->latest();
        
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function activate(int $id): ?WhatsappTemplate
    {
        $template = $this->model->find($id);
        if (!$template) return null;
        
        // WhatsApp templates can only be activated if approved
        if ($template->status !== 'approved') {
            return null;
        }
        
        $template->update(['is_active' => true]);
        
        return $template->fresh('business');
    }

    public function deactivate(int $id): ?WhatsappTemplate
    {
        $template = $this->model->find($id);
        if (!$template) return null;
        
        $template->update(['is_active' => false]);
        
        return $template->fresh('business');
    }

    public function submitForApproval(int $id): ?WhatsappTemplate
    {
        $template = $this->model->find($id);
        if (!$template) return null;
        
        $template->update([
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        
        // TODO: Call Meta API to submit template for approval
        
        return $template->fresh('business');
    }
}

