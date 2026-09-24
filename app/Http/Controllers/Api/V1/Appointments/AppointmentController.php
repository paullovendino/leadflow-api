<?php

namespace App\Http\Controllers\Api\V1\Appointments;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Appointments\AvailableSlotsRequest;
use App\Http\Requests\Api\V1\Appointments\ChangeAppointmentStatusRequest;
use App\Http\Requests\Api\V1\Appointments\IndexAppointmentRequest;
use App\Http\Requests\Api\V1\Appointments\StoreAppointmentRequest;
use App\Http\Requests\Api\V1\Appointments\UpdateAppointmentRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Models\Appointment;
use App\Services\Crm\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function index(IndexAppointmentRequest $request, AppointmentService $appointments): AnonymousResourceCollection
    {
        return AppointmentResource::collection(
            $appointments->paginate($request->user(), $request->validated()),
        );
    }

    public function store(StoreAppointmentRequest $request, AppointmentService $appointments): JsonResponse
    {
        return (new AppointmentResource($appointments->create($request->user(), $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Appointment $appointment, AppointmentService $appointments): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return new AppointmentResource($appointments->detail($appointment));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment, AppointmentService $appointments): AppointmentResource
    {
        return new AppointmentResource(
            $appointments->update($request->user(), $appointment, $request->validated()),
        );
    }

    public function status(ChangeAppointmentStatusRequest $request, Appointment $appointment, AppointmentService $appointments): AppointmentResource
    {
        return new AppointmentResource(
            $appointments->changeStatus(
                $request->user(),
                $appointment,
                AppointmentStatus::from($request->validated('status')),
            ),
        );
    }

    public function slots(AvailableSlotsRequest $request, AppointmentService $appointments): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $appointments->availableSlots(
                (int) $validated['staff_user_id'],
                $validated['date'],
                (int) $validated['service_id'],
                isset($validated['ignore_appointment_id']) ? (int) $validated['ignore_appointment_id'] : null,
            ),
        ]);
    }
}
