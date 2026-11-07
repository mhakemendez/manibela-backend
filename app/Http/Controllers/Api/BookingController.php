<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\VehicleAvailability;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $booking = DB::transaction(function () use ($request, $validated) {
            // Lock the vehicle row to prevent simultaneous bookings.
            $vehicle = Vehicle::query()
                ->lockForUpdate()
                ->findOrFail($validated['vehicle_id']);

            if ($vehicle->status !== Vehicle::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['This vehicle is not approved for booking.'],
                ]);
            }

            if ($vehicle->user_id === $request->user()->id) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['You cannot book your own vehicle.'],
                ]);
            }

            if (!$vehicle->daily_rate || $vehicle->daily_rate <= 0) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['This vehicle does not have a valid rental rate.'],
                ]);
            }

            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);

            // Date difference: November 10 to November 12 = 2 days.
            $totalDays = $startDate->diffInDays($endDate);

            // Check blocked availability dates.
            $hasBlockedDates = VehicleAvailability::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('start_date', '<=', $validated['end_date'])
                ->where('end_date', '>=', $validated['start_date'])
                ->exists();

            if ($hasBlockedDates) {
                throw ValidationException::withMessages([
                    'start_date' => ['This vehicle is unavailable for the selected dates.'],
                ]);
            }

            // Check existing pending and confirmed bookings.
            $hasConflictingBooking = Booking::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereIn('status', [
                    Booking::STATUS_PENDING,
                    Booking::STATUS_CONFIRMED,
                ])
                ->where('start_date', '<=', $validated['end_date'])
                ->where('end_date', '>=', $validated['start_date'])
                ->exists();

            if ($hasConflictingBooking) {
                throw ValidationException::withMessages([
                    'start_date' => ['This vehicle already has a booking for the selected dates.'],
                ]);
            }

            // Save a snapshot of the current rental price.
            $dailyRate = $vehicle->daily_rate;
            $totalAmount = number_format(
                (float) $dailyRate * $totalDays,
                2,
                '.',
                ''
            );

            return Booking::create([
                'customer_id' => $request->user()->id,
                'vehicle_id' => $vehicle->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'daily_rate' => $dailyRate,
                'total_days' => $totalDays,
                'total_amount' => $totalAmount,
                'status' => Booking::STATUS_PENDING,
            ]);
        });

        return response()->json([
            'message' => 'Booking request submitted successfully.',
            'data' => $booking,
        ], 201);
    }


    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->where('customer_id', $request->user()->id)
            ->with([
                'vehicle:id,brand,model,type,year,daily_rate',
                'vehicle.photos',
            ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'data' => $bookings,
        ]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->customer_id === $request->user()->id,
            403
        );

        $booking->load([
            'vehicle',
            'vehicle.photos',
        ]);

        return response()->json([
            'data' => $booking,
        ]);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->customer_id === $request->user()->id,
            403
        );

        $booking = DB::transaction(function () use ($booking) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (
                !in_array($booking->status, [
                    Booking::STATUS_PENDING,
                    Booking::STATUS_CONFIRMED,
                ], true)
            ) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or confirmed bookings can be cancelled.'],
                ]);
            }

            $booking->update([
                'status' => Booking::STATUS_CANCELLED,
            ]);

            return $booking;
        });

        return response()->json([
            'message' => 'Booking cancelled successfully.',
            'data' => $booking->fresh(),
        ]);
    }


    public function ownerIndex(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->whereHas('vehicle', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->with([
                'customer:id,name,email',
                'vehicle:id,user_id,brand,model,type,year',
                'vehicle.photos',
            ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'data' => $bookings,
        ]);
    }

    public function ownerShow(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->vehicle()->where('user_id', $request->user()->id)->exists(),
            403
        );

        $booking->load([
            'customer:id,name,email',
            'vehicle',
            'vehicle.photos',
        ]);

        return response()->json([
            'data' => $booking,
        ]);
    }

    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->vehicle()->where('user_id', $request->user()->id)->exists(),
            403
        );

        $booking = DB::transaction(function () use ($booking) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if ($booking->status !== Booking::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending bookings can be confirmed.'],
                ]);
            }

            $booking->update([
                'status' => Booking::STATUS_CONFIRMED,
            ]);

            return $booking;
        });

        return response()->json([
            'message' => 'Booking confirmed successfully.',
            'data' => $booking->fresh(),
        ]);
    }

    public function reject(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->vehicle()->where('user_id', $request->user()->id)->exists(),
            403
        );

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = DB::transaction(function () use ($booking, $validated) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if ($booking->status !== Booking::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending bookings can be rejected.'],
                ]);
            }

            $booking->update([
                'status' => Booking::STATUS_REJECTED,
                'rejection_reason' => $validated['rejection_reason'] ?? null,
            ]);

            return $booking;
        });

        return response()->json([
            'message' => 'Booking rejected successfully.',
            'data' => $booking->fresh(),
        ]);
    }

    public function complete(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->vehicle()->where('user_id', $request->user()->id)->exists(),
            403
        );

        $booking = DB::transaction(function () use ($booking) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if ($booking->status !== Booking::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => ['Only confirmed bookings can be completed.'],
                ]);
            }

            $booking->update([
                'status' => Booking::STATUS_COMPLETED,
            ]);

            return $booking;
        });

        return response()->json([
            'message' => 'Booking marked as completed.',
            'data' => $booking->fresh(),
        ]);
    }
}
