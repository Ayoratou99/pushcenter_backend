<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\BaseController;
use Illuminate\Http\Request;

class SmtpController extends BaseController
{
    protected $smtpRepository;

    /**
     * Constructor
     *
     * @param SmtpRepository $smtpRepository
     */
    public function __construct(SmtpRepository $smtpRepository)
    {
        $this->smtpRepository = $smtpRepository;
    }

    public function index(Request $request)
    {
        return $this->successResponse($this->smtpRepository->all($request->per_page));
    }

    public function show(int $id)
    {
        return $this->successResponse($this->smtpRepository->find($id));
    }

    public function store(Request $request)
    {
        return $this->successResponse($this->smtpRepository->create($request->all()));
    }

    public function update(int $id, Request $request)
    {
        return $this->successResponse($this->smtpRepository->update($id, $request->all()));
    }

    public function destroy(int $id)
    {
        return $this->successResponse($this->smtpRepository->delete($id));
    }
}
