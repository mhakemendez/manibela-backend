<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vehicles = $request->user()
            ->vehicles()
            ->latest()
            ->paginate(10);

        return response()->json([
            'data' => $vehicles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => [
                'required',
                Rule::in([
                    Vehicle::TYPE_CAR,
                    Vehicle::TYPE_MOTORCYCLE,
                ]),
            ],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1900', 'max:' . (now()->year + 1)],
            'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'],
            'transmission' => ['required', 'string', 'max:20'],
            'fuel_type' => ['required', 'string', 'max:30'],
            'seats' => ['required', 'integer', 'min:1', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $vehicle = $request->user()
            ->vehicles()
            ->create($validated);

        $vehicle->status = Vehicle::STATUS_PENDING;
        $vehicle->save();

        return response()->json([
            'message' => 'Vehicle submitted successfully and is pending approval.',
            'data' => $vehicle,
        ], 201);
    }

    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => $vehicle,
        ]);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'type' => [
                'sometimes',
                'required',
                Rule::in([
                    Vehicle::TYPE_CAR,
                    Vehicle::TYPE_MOTORCYCLE,
                ]),
            ],
            'brand' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'year' => [
                'sometimes',
                'required',
                'integer',
                'min:1900',
                'max:' . (now()->year + 1),
            ],
            'plate_number' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('vehicles', 'plate_number')->ignore($vehicle->id),
            ],
            'transmission' => ['sometimes', 'required', 'string', 'max:20'],
            'fuel_type' => ['sometimes', 'required', 'string', 'max:30'],
            'seats' => ['sometimes', 'required', 'integer', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $vehicle->update($validated);

        return response()->json([
            'message' => 'Vehicle updated successfully.',
            'data' => $vehicle->fresh(),
        ]);
    }

    public function destroy(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 403);

        $vehicle->delete();

        return response()->json([
            'message' => 'Vehicle deleted successfully.',
        ]);
    }

    public function adminIndex(): JsonResponse
    {
        $vehicles = Vehicle::where('status', Vehicle::STATUS_PENDING)
            ->with('owner:id,name,email')
            ->latest()
            ->paginate(10);

        return response()->json([
            'data' => $vehicles,
        ]);
    }

    public function approve(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->status !== Vehicle::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending vehicles can be approved.',
            ], 422);
        }

        $vehicle->status = Vehicle::STATUS_APPROVED;
        $vehicle->save();

        return response()->json([
            'message' => 'Vehicle approved successfully.',
            'data' => $vehicle->fresh(),
        ]);
    }

    public function reject(Request $request, Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->status !== Vehicle::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending vehicles can be rejected.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $vehicle->status = Vehicle::STATUS_REJECTED;
        $vehicle->save();

        return response()->json([
            'message' => 'Vehicle rejected successfully.',
            'data' => $vehicle->fresh(),
            'reason' => $validated['reason'],
        ]);
    }
}