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
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'daily_rate' => ['required', 'numeric', 'gt:0'],
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
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'daily_rate' => ['sometimes', 'required', 'numeric', 'gt:0'],
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

    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['sometimes', 'numeric', 'min:1', 'max:50'],
            'type' => [
                'sometimes',
                Rule::in([
                    Vehicle::TYPE_CAR,
                    Vehicle::TYPE_MOTORCYCLE,
                ])
            ],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $latitude = $validated['latitude'];
        $longitude = $validated['longitude'];
        $radius = $validated['radius'] ?? 10;

        $distance = "
            (6371 * ACOS(
                MAX(-1, MIN(1,
                    COS(RADIANS(?)) *
                    COS(RADIANS(pickup_latitude)) *
                    COS(RADIANS(pickup_longitude) - RADIANS(?)) +
                    SIN(RADIANS(?)) *
                    SIN(RADIANS(pickup_latitude))
                ))
            ))
        ";

        $vehicleQuery = Vehicle::query()
            ->where('status', Vehicle::STATUS_APPROVED)
            ->whereNotNull('pickup_latitude')
            ->whereNotNull('pickup_longitude')
            ->when(isset($validated['type']), function ($query) use ($validated) {
                $query->where('type', $validated['type']);
            })
            ->whereDoesntHave('availabilities', function ($query) use ($validated) {
                $query->where('start_date', '<=', $validated['end_date'])
                    ->where('end_date', '>=', $validated['start_date']);
            })
            ->select('vehicles.*')
            ->selectRaw("$distance AS distance_km", [
                $latitude,
                $longitude,
                $latitude,
            ]);

        $vehicles = Vehicle::query()
            ->fromSub($vehicleQuery, 'nearby_vehicles')
            ->where('distance_km', '<=', $radius)
            ->with('photos')
            ->orderBy('distance_km')
            ->paginate($validated['per_page'] ?? 10);

        return response()->json($vehicles);
    }
}