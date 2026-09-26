<?php

namespace App\Http\Controllers\Api\V1\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicServiceResource;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PublicServiceResource::collection(
            Service::query()->active()->orderBy('name')->get(),
        );
    }
}
