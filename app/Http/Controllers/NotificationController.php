<?php

namespace App\Http\Controllers;

use App\Support\ActivityNews;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/**
 * What happened in the book while you were not looking at it.
 *
 * A person only ever receives notices for the parts of the book they can already open, so the
 * inbox needs no filter of its own: whatever is in it is yours to read. There is no Excel
 * download here on purpose, because the Audit Trail export already covers the record of
 * everything, and this page is for glancing rather than for keeping.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Gate::any(ActivityNews::AUDIENCE), 403);

        $user = $request->user();

        $notices = $user->notifications()
            ->when($request->input('state') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], $request->search);

                // The page prints no words the notice does not already carry, so searching the
                // stored payload whole finds exactly what is on the screen.
                $q->where('data', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'unread' => $user->notifications()->whereNull('read_at')->count(),
            'all' => $user->notifications()->count(),
        ];

        return view('notifications.index', compact('notices', 'counts'));
    }

    public function markRead(Request $request, DatabaseNotification $notification)
    {
        // The row is addressed by its own id, so prove it is this reader's notice before
        // letting them mark it seen.
        abort_unless($notification->notifiable_type === $request->user()->getMorphClass()
            && (int) $notification->notifiable_id === $request->user()->id, 403);

        $notification->markAsRead();

        return back()->with('success', 'Marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $marked = $request->user()->unreadNotifications->count();

        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', $marked.' notice'.($marked === 1 ? '' : 's').' marked as read.');
    }
}
