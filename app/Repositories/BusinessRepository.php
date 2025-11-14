<?php

namespace App\Repositories;

use App\Models\Business;
use App\Repositories\Contracts\BusinessRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class BusinessRepository implements BusinessRepositoryInterface
{
    protected $model;

    public function __construct(Business $model)
    {
        $this->model = $model;
    }

    public function all(?int $perPage = null)
    {
        $query = $this->model->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function find(int $id): ?Business
    {
        return $this->model->with(['messages', 'templates'])->find($id);
    }

    public function create(array $data): Business
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Business
    {
        $business = $this->model->find($id);
        if (!$business) return null;
        $business->update($data);
        return $business->fresh(['messages', 'templates']);
    }

    public function delete(int $id): bool
    {
        $business = $this->model->find($id);
        return $business ? $business->delete() : false;
    }

    public function getByStatus(string $status, ?int $perPage = null)
    {
        $query = $this->model->where('status', $status)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function search(string $search, ?int $perPage = null)
    {
        $query = $this->model->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        })->latest();
        
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getStats(int $id): ?array
    {
        $business = $this->model->find($id);
        
        if (!$business) return null;

        return [
            'total_messages' => $business->messages()->count(),
            'total_templates' => $business->templates()->count(),
            'messages_by_type' => [
                'email' => $business->messages()->where('message_type', 'email')->count(),
                'sms' => $business->messages()->where('message_type', 'sms')->count(),
                'whatsapp' => $business->messages()->where('message_type', 'whatsapp')->count(),
            ],
            'messages_by_status' => [
                'pending' => $business->messages()->where('status', 'pending')->count(),
                'sent' => $business->messages()->where('status', 'sent')->count(),
                'delivered' => $business->messages()->where('status', 'delivered')->count(),
                'failed' => $business->messages()->where('status', 'failed')->count(),
            ],
            'total_cost' => $business->messages()->sum('cost'),
        ];
    }
}
