<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customers\StoreCustomerNoteRequest;
use App\Http\Resources\Api\V1\NoteResource;
use App\Models\Customer;
use App\Services\Crm\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerNoteController extends Controller
{
    public function index(Customer $customer, NoteService $notes): AnonymousResourceCollection
    {
        $this->authorize('view', $customer);

        return NoteResource::collection($notes->listForCustomer($customer));
    }

    public function store(StoreCustomerNoteRequest $request, Customer $customer, NoteService $notes): JsonResponse
    {
        return (new NoteResource($notes->createForCustomer($request->user(), $customer, $request->validated('body'))))
            ->response()
            ->setStatusCode(201);
    }
}
