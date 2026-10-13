<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Notifications\NotificationCenter;
use App\Domain\Notifications\NotificationPolicy;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationController
{
    public function index(Request $request, NotificationCenter $center): JsonResponse
    {
        $business = $this->business($request);
        $notifications = $center->query($request->user(), $business)
            ->with('booking:id,reference,starts_at,location_id', 'booking.location:id,timezone')
            ->latest('created_at')
            ->paginate(25);

        return response()->json([
            'data' => $notifications->getCollection()->map(fn (UserNotification $notification): array => $this->resource($notification)),
            'links' => [
                'first' => $notifications->url(1),
                'last' => $notifications->url($notifications->lastPage()),
                'prev' => $notifications->previousPageUrl(),
                'next' => $notifications->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request, NotificationCenter $center): JsonResponse
    {
        return response()->json(['count' => $center->query($request->user(), $this->business($request))->whereNull('read_at')->count()]);
    }

    public function markRead(Request $request, string $notification, NotificationCenter $center): JsonResponse
    {
        $record = $center->markRead($request->user(), $this->business($request), $notification)
            ->load('booking:id,reference,starts_at,location_id', 'booking.location:id,timezone');

        return response()->json(['data' => $this->resource($record)]);
    }

    public function markAllRead(Request $request, NotificationCenter $center): JsonResponse
    {
        return response()->json(['updated' => $center->markAllRead($request->user(), $this->business($request))]);
    }

    public function preferences(Request $request, NotificationCenter $center): JsonResponse
    {
        return response()->json(['data' => $center->preferences($request->user(), $this->business($request))]);
    }

    public function updatePreferences(Request $request, NotificationCenter $center): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.notification_type' => ['required', 'string', 'distinct', 'in:'.implode(',', NotificationPolicy::TYPES)],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);
        $center->savePreferences($request->user(), $this->business($request), $data['preferences']);

        return $this->preferences($request, $center);
    }

    public function settings(Request $request, NotificationCenter $center): JsonResponse
    {
        abort_unless(app(BusinessAccess::class)->canManageBusinessSettings($request->user(), $this->business($request)), 403);

        return response()->json(['data' => $center->businessSettings($this->business($request))]);
    }

    public function updateSettings(Request $request, NotificationCenter $center): JsonResponse
    {
        $business = $this->business($request);
        abort_unless(app(BusinessAccess::class)->canManageBusinessSettings($request->user(), $business), 403);

        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.role' => ['required', 'string', 'in:'.implode(',', array_column(BusinessRole::cases(), 'value'))],
            'rules.*.notification_type' => ['required', 'string', 'in:'.implode(',', NotificationPolicy::TYPES)],
            'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.reminder_intervals' => ['nullable', 'array', 'min:1', 'max:8'],
            'rules.*.reminder_intervals.*' => ['required', 'integer', 'min:1', 'max:525600', 'distinct'],
        ]);
        $keys = collect($data['rules'])->map(fn (array $rule): string => $rule['role'].':'.$rule['notification_type']);
        if ($keys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['rules' => ['Each role and notification type may be configured only once.']]);
        }
        $center->saveBusinessSettings($business, $data['rules']);

        return response()->json(['data' => $center->businessSettings($business)]);
    }

    private function business(Request $request): Business
    {
        return $request->attributes->get('business');
    }

    private function resource(UserNotification $notification): array
    {
        return [
            'id' => $notification->getKey(),
            'notification_type' => $notification->notification_type,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
            'booking' => $notification->booking ? [
                'id' => $notification->booking->getKey(),
                'reference' => $notification->booking->reference,
                'starts_at' => $notification->booking->starts_at,
            ] : null,
        ];
    }
}
