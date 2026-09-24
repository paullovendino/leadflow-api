<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customers\IndexCustomerRequest;
use App\Http\Requests\Api\V1\Customers\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Customers\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use App\Services\Crm\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(IndexCustomerRequest $request, CustomerService $customers): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            $customers->paginate($request->user(), $request->validated()),
        );
    }

    public function store(StoreCustomerRequest $request, CustomerService $customers): JsonResponse
    {
        return (new CustomerResource($customers->create($request->user(), $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer, CustomerService $customers): CustomerResource
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customers->detail($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, CustomerService $customers): CustomerResource
    {
        return new CustomerResource($customers->update($customer, $request->validated()));
    }
}
