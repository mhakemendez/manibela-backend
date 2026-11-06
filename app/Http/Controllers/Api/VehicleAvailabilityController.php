<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VehicleAvailabilityController extends Controller
{
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeVehicleOwner($request, $vehicle);

        $availabilities = $vehicle->availabilities()
            ->orderBy('start_date')
            ->get();

        return response()->json([
            'data' => $availabilities,
        ]);
    }

    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeVehicleOwner($request, $vehicle);

        $validated = $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->ensureNoOverlap(
            $vehicle,
            $validated['start_date'],
            $validated['end_date']
        );

        $availability = $vehicle->availabilities()->create($validated);

        return response()->json([
            'message' => 'Vehicle availability created successfully.',
            'data' => $availability,
        ], 201);
    }

    public function update(
        Request $request,
        Vehicle $vehicle,
        VehicleAvailability $availability
    ): JsonResponse {
        $this->authorizeVehicleOwner($request, $vehicle);

        $availability = $vehicle->availabilities()
            ->findOrFail($availability->id);

        $validated = $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->ensureNoOverlap(
            $vehicle,
            $validated['start_date'],
            $validated['end_date'],
            $availability->id
        );

        $availability->update($validated);

        return response()->json([
            'message' => 'Vehicle availability updated successfully.',
            'data' => $availability->refresh(),
        ]);
    }

    public function destroy(
        Request $request,
        Vehicle $vehicle,
        VehicleAvailability $availability
    ): JsonResponse {
        $this->authorizeVehicleOwner($request, $vehicle);

        $availability = $vehicle->availabilities()
            ->findOrFail($availability->id);

        $availability->delete();

        return response()->json([
            'message' => 'Vehicle availability deleted successfully.',
        ]);
    }

    private function authorizeVehicleOwner(
        Request $request,
        Vehicle $vehicle
    ): void {
        abort_unless(
            $vehicle->user_id === $request->user()->id,
            403,
            'You are not authorized to manage this vehicle.'
        );
    }

    private function ensureNoOverlap(
        Vehicle $vehicle,
        string $startDate,
        string $endDate,
        ?int $ignoreId = null
    ): void {
        $query = $vehicle->availabilities()
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'This period overlaps with an existing unavailable period.',
                ],
            ]);
        }
    }
}