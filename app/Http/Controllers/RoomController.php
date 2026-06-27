<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoomStoreRequest;
use App\Http\Requests\RoomUpdateRequest;
use App\Models\Floor;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Room::select('id', 'room_type_id', 'room_number', 'floor', 'status', 'notes')
            ->with('roomType:id,name,base_price')
            ->orderBy('room_number');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('floor')) {
            $query->where('floor', $request->floor);
        }

        if ($request->filled('room_type_id')) {
            $query->where('room_type_id', $request->room_type_id);
        }

        return Inertia::render('Rooms/Index', [
            'rooms' => $query->paginate(20),
            'roomTypes' => RoomType::select('id', 'name', 'base_price', 'max_occupancy')->get(),
            'floors' => Floor::orderBy('number')->get(['number', 'name']),
            'filters' => $request->only('status', 'floor', 'room_type_id'),
            'stats' => [
                'total' => Room::count(),
                'available' => Room::where('status', 'available')->count(),
                'occupied' => Room::where('status', 'occupied')->count(),
                'cleaning' => Room::where('status', 'cleaning')->count(),
                'maintenance' => Room::where('status', 'maintenance')->count(),
            ],
        ]);
    }

    public function store(RoomStoreRequest $request): RedirectResponse
    {
        Room::create($request->validated());

        return back()->with('success', 'Dhoma u shtua me sukses.');
    }

    public function update(RoomUpdateRequest $request, Room $room): RedirectResponse
    {
        $data = $request->validated();

        if ($data['status'] === 'available' && $this->roomHasActiveStay($room)) {
            return back()->with('error', 'Dhoma ka nje mysafir brenda (check-in) — nuk mund te kalohet ne te lire.');
        }

        $room->update($data);

        return back()->with('success', 'Dhoma u perditesua me sukses.');
    }

    public function destroy(Room $room): RedirectResponse
    {
        if (!auth()->user()->can('delete_rooms')) {
            abort(403);
        }

        $room->delete();

        return back()->with('success', 'Dhoma u fshi.');
    }

    public function updateStatus(Request $request, Room $room): RedirectResponse
    {
        if (!auth()->user()->can('update_rooms')) {
            abort(403);
        }

        $request->validate([
            'status' => ['required', 'in:available,occupied,cleaning,maintenance'],
        ]);

        if ($request->status === 'available' && $this->roomHasActiveStay($room)) {
            return back()->with('error', 'Dhoma ka nje mysafir brenda (check-in) — nuk mund te kalohet ne te lire.');
        }

        $room->update(['status' => $request->status]);

        return back()->with('success', "Statusi u ndryshua ne {$request->status}.");
    }

    /**
     * Is this room currently occupied by a guest who has checked in (not yet out)?
     */
    private function roomHasActiveStay(Room $room): bool
    {
        return Reservation::where('room_id', $room->id)
            ->where('status', 'checked_in')
            ->exists();
    }
}
