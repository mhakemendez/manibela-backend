<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class VehiclePhotoController extends Controller
{
    /**
     * Upload multiple photos.
     */
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless(
            $vehicle->user_id === $request->user()->id,
            403,
            'You are not authorized to manage this vehicle.'
        );

        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $currentCount = $vehicle->photos()->count();
        $newCount = count($validated['photos']);

        if ($currentCount + $newCount > 10) {
            throw ValidationException::withMessages([
                'photos' => ['A vehicle can have a maximum of 10 photos.'],
            ]);
        }

        $uploadedPhotos = [];

        foreach ($validated['photos'] as $index => $photo) {
            $path = $photo->store('vehicles/' . $vehicle->id, 'public');

            $uploadedPhotos[] = $vehicle->photos()->create([
                'image_path' => $path,
                'is_primary' => $currentCount === 0 && $index === 0,
            ]);
        }

        return response()->json([
            'message' => 'Vehicle photos uploaded successfully.',
            'photos' => $uploadedPhotos,
        ], 201);
    }

    /**
     * List vehicle photos.
     */
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless(
            $vehicle->user_id === $request->user()->id,
            403,
            'You are not authorized to view this vehicle photos.'
        );

        return response()->json([
            'photos' => $vehicle->photos()->latest()->get(),
        ]);
    }

    /**
     * Set a photo as primary.
     */
    public function setPrimary(
        Request $request,
        Vehicle $vehicle,
        int $photo
    ): JsonResponse {
        abort_unless(
            $vehicle->user_id === $request->user()->id,
            403,
            'You are not authorized to manage this vehicle.'
        );

        $vehiclePhoto = $vehicle->photos()->findOrFail($photo);

        DB::transaction(function () use ($vehicle, $vehiclePhoto) {
            $vehicle->photos()->update([
                'is_primary' => false,
            ]);

            $vehiclePhoto->update([
                'is_primary' => true,
            ]);
        });

        return response()->json([
            'message' => 'Primary photo updated successfully.',
            'photo' => $vehiclePhoto->fresh(),
        ]);
    }

    /**
     * Delete a vehicle photo.
     */
    public function destroy(
        Request $request,
        Vehicle $vehicle,
        int $photo
    ): JsonResponse {
        abort_unless(
            $vehicle->user_id === $request->user()->id,
            403,
            'You are not authorized to manage this vehicle.'
        );

        $vehiclePhoto = $vehicle->photos()->findOrFail($photo);

        DB::transaction(function () use ($vehicle, $vehiclePhoto) {
            $wasPrimary = $vehiclePhoto->is_primary;

            $vehiclePhoto->delete();

            if ($wasPrimary) {
                $nextPhotoId = DB::table('vehicle_photos')
                    ->where('vehicle_id', $vehicle->id)
                    ->latest()
                    ->value('id');

                if ($nextPhotoId !== null) {
                    DB::table('vehicle_photos')
                        ->where('id', $nextPhotoId)
                        ->update([
                            'is_primary' => true,
                        ]);
                }
            }
        });

        Storage::disk('public')->delete($vehiclePhoto->image_path);

        return response()->json([
            'message' => 'Vehicle photo deleted successfully.',
        ]);
    }
}
