<?php

namespace App\Repositories;

use App\Models\Message;
use App\Repositories\Contracts\MessageRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class MessageRepository implements MessageRepositoryInterface
{
    protected $model;

    public function __construct(Message $model)
    {
        $this->model = $model;
    }

    public function all(?int $perPage = null)
    {
        $query = $this->model->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function find(int $id): ?Message
    {
        return $this->model->with(['business', 'whatsappMessage', 'smsMessage', 'emailMessage'])->find($id);
    }

    public function create(array $data): Message
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Message
    {
        $message = $this->find($id);
        if (!$message) return null;
        $message->update($data);
        return $message->fresh();
    }

    public function delete(int $id): bool
    {
        $message = $this->find($id);
        return $message ? $message->delete() : false;
    }

    public function getByBusiness(int $businessId, ?int $perPage = null)
    {
        $query = $this->model->where('business_id', $businessId)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getByChannel(string $channel, ?int $perPage = null)
    {
        $query = $this->model->byChannel($channel)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getByStatus(string $status, ?int $perPage = null)
    {
        $query = $this->model->byStatus($status)->latest();
        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getPending(): Collection
    {
        return $this->model->pending()->get();
    }
}


