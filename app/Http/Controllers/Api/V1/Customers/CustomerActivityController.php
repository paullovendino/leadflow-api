<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Models\Customer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerActivityController extends Controller
{
    public function index(Customer $customer): AnonymousResourceCollection
    {
        $this->authorize('view', $customer);

        return ActivityResource::collection($customer->activities()->with('user')->get());
    }
}
