<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuddyRequest;
use App\Models\ConversationReadState;
use App\Models\DirectConversation;
use App\Models\StudyGroup;
use App\Models\StudyMessage;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudyMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = $request->user()->studyGroups()
            ->latest('study_groups.created_at')
            ->get();

        $conversations = $groups->map(function (StudyGroup $group) use ($request): array {
            $lastMessage = $group->messages()->with('user:id,name')->latest()->first();

            $preview = $lastMessage ? sprintf(
                '%s: %s',
                $lastMessage->user_id === $request->user()->id ? 'You' : ($lastMessage->user ? $lastMessage->user->name : 'Group'),
                $lastMessage->content
            ) : '';

            return [
                'id' => $group->id,
                'type' => 'group',
                'name' => $group->name,
                'initials' => strtoupper(substr($group->name, 0, 2)),
                'avatar_icon' => $group->avatar_icon,
                'avatar_color' => $group->avatar_color,
                'lastMessage' => $preview,
                'time' => $lastMessage?->created_at->diffForHumans(),
                'group_id' => $group->id,
                'unread_count' => $this->unreadMessageCount(
                    $request,
                    'group',
                    $group->id,
                    $group->messages()
                ),
                '_sort_at' => $lastMessage?->created_at?->timestamp ?? $group->created_at->timestamp,
            ];
        });

        $directConversations = DirectConversation::query()
            ->where(fn ($query) => $query
                ->where('user_one_id', $request->user()->id)
                ->orWhere('user_two_id', $request->user()->id))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_user_id', 'direct_conversations.user_one_id')
                ->whereColumn('user_blocks.blocked_user_id', 'direct_conversations.user_two_id'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_user_id', 'direct_conversations.user_two_id')
                ->whereColumn('user_blocks.blocked_user_id', 'direct_conversations.user_one_id'))
            ->with([
                'firstUser' => fn ($query) => $query
                    ->select('id', 'name')
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
                'firstUser.profile:id,user_id,profile_photo_url',
                'secondUser' => fn ($query) => $query
                    ->select('id', 'name')
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
                'secondUser.profile:id,user_id,profile_photo_url',
            ])
            ->get()
            ->map(function (DirectConversation $conversation) use ($request): array {
                $otherUser = $conversation->user_one_id === $request->user()->id
                    ? $conversation->secondUser
                    : $conversation->firstUser;
                $lastMessage = $conversation->messages()->with('user:id,name')->latest()->first();

                return [
                    'id' => $conversation->id,
                    'type' => 'direct',
                    'name' => $otherUser->name,
                    'initials' => strtoupper(substr($otherUser->name, 0, 2)),
                    'profile_photo_url' => $otherUser->profile?->profile_photo_url,
                    'average_rating' => $otherUser->buddy_reviews_received_avg_rating === null
                        ? null
                        : round((float) $otherUser->buddy_reviews_received_avg_rating, 1),
                    'ratings_count' => $otherUser->buddy_reviews_received_count,
                    'lastMessage' => $lastMessage ? sprintf(
                        '%s: %s',
                        $lastMessage->user_id === $request->user()->id ? 'You' : $otherUser->name,
                        $lastMessage->content
                    ) : '',
                    'time' => $lastMessage?->created_at->diffForHumans(),
                    'direct_conversation_id' => $conversation->id,
                    'unread_count' => $this->unreadMessageCount(
                        $request,
                        'direct',
                        $conversation->id,
                        $conversation->messages()
                    ),
                    'unread_sender_ids' => $this->unreadMessageSenderIds(
                        $request,
                        'direct',
                        $conversation->id,
                        $conversation->messages()
                    ),
                    '_sort_at' => $lastMessage?->created_at?->timestamp ?? $conversation->created_at->timestamp,
                ];
            });

        $allConversations = $conversations->concat($directConversations);
        $groupUnreadCount = $allConversations
            ->where('type', 'group')
            ->sum('unread_count');
        $unreadSenderIds = $allConversations
            ->where('type', 'direct')
            ->flatMap(fn (array $conversation): array => $conversation['unread_sender_ids'])
            ->unique()
            ->values();

        $conversations = $allConversations
            ->map(function (array $conversation): array {
                unset($conversation['unread_sender_ids']);

                return $conversation;
            })
            ->sortByDesc('_sort_at')
            ->map(function (array $conversation): array {
                unset($conversation['_sort_at']);

                return $conversation;
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Messages retrieved successfully.',
            'data' => [
                'conversations' => $conversations,
                'unread_count' => $groupUnreadCount + $unreadSenderIds->count(),
            ],
        ]);
    }

    public function createDirectConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'matched_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $matchedUserId = (int) $validated['matched_user_id'];

        if ($matchedUserId === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot start a conversation with yourself.',
            ], 422);
        }

        if (UserBlock::blocksEitherDirection($request->user()->id, $matchedUserId)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot start a conversation with this user.',
            ], 403);
        }

        $isBuddy = BuddyRequest::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($request, $matchedUserId): void {
                $query->where(function ($pair) use ($request, $matchedUserId): void {
                    $pair->where('requester_id', $request->user()->id)
                        ->where('recipient_id', $matchedUserId);
                })->orWhere(function ($pair) use ($request, $matchedUserId): void {
                    $pair->where('requester_id', $matchedUserId)
                        ->where('recipient_id', $request->user()->id);
                });
            })
            ->exists();

        if (! $isBuddy) {
            return response()->json([
                'success' => false,
                'message' => 'You can only start a conversation with an accepted study buddy.',
            ], 403);
        }

        $matchedUser = User::query()
            ->whereKey($matchedUserId)
            ->where('is_suspended', false)
            ->with('profile:id,user_id,profile_photo_url')
            ->withAvg('buddyReviewsReceived', 'rating')
            ->withCount('buddyReviewsReceived')
            ->firstOrFail();

        $conversation = DirectConversation::firstOrCreate([
            'user_one_id' => min($request->user()->id, $matchedUserId),
            'user_two_id' => max($request->user()->id, $matchedUserId),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Direct conversation ready.',
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'type' => 'direct',
                    'name' => $matchedUser->name,
                    'initials' => strtoupper(substr($matchedUser->name, 0, 2)),
                    'profile_photo_url' => $matchedUser->profile?->profile_photo_url,
                    'average_rating' => $matchedUser->buddy_reviews_received_avg_rating === null
                        ? null
                        : round((float) $matchedUser->buddy_reviews_received_avg_rating, 1),
                    'ratings_count' => $matchedUser->buddy_reviews_received_count,
                    'direct_conversation_id' => $conversation->id,
                ],
            ],
        ], 201);
    }

    public function showDirect(Request $request, int $id): JsonResponse
    {
        $conversation = $this->findDirectConversation($request, $id);
        $messages = $conversation->messages()
            ->with([
                'user' => fn ($query) => $query
                    ->select('id', 'name')
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
                'user.profile:id,user_id,profile_photo_url',
            ])
            ->oldest()
            ->get();
        $this->markConversationRead($request, 'direct', $conversation->id, $messages);

        return response()->json([
            'success' => true,
            'message' => 'Direct messages retrieved successfully.',
            'data' => ['messages' => $messages->map(fn (StudyMessage $message) => [
                'id' => $message->id,
                'user_id' => $message->user_id,
                'sender' => $message->user_id === $request->user()->id ? 'me' : 'them',
                'content' => $message->content,
                'created_at' => $message->created_at,
                'user' => $message->user ? [
                    'id' => $message->user->id,
                    'name' => $message->user->name,
                    'profile_photo_url' => $message->user->profile?->profile_photo_url,
                    'average_rating' => $message->user->buddy_reviews_received_avg_rating === null
                        ? null
                        : round((float) $message->user->buddy_reviews_received_avg_rating, 1),
                    'ratings_count' => $message->user->buddy_reviews_received_count,
                ] : null,
            ])],
        ]);
    }

    public function storeDirect(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'client_message_id' => ['nullable', 'string', 'max:64'],
        ]);
        $conversation = $this->findDirectConversation($request, $id);
        $messageAttributes = [
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ];
        $message = isset($validated['client_message_id'])
            ? $conversation->messages()->firstOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'client_message_id' => $validated['client_message_id'],
                ],
                ['content' => $validated['content']]
            )
            : $conversation->messages()->create($messageAttributes);
        $conversation->touch();

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'message' => [
                    'id' => $message->id,
                    'user_id' => $message->user_id,
                    'content' => $message->content,
                    'created_at' => $message->created_at,
                ],
            ],
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group_id' => ['required', 'integer', 'exists:study_groups,id'],
            'content' => ['required', 'string', 'max:2000'],
            'client_message_id' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $group = $request->user()->studyGroups()->findOrFail($request->input('group_id'));

        $messageAttributes = [
            'group_id' => $group->id,
            'user_id' => $request->user()->id,
            'content' => $request->input('content'),
        ];
        $message = $request->filled('client_message_id')
            ? StudyMessage::query()->firstOrCreate(
                [
                    'group_id' => $group->id,
                    'user_id' => $request->user()->id,
                    'client_message_id' => $request->input('client_message_id'),
                ],
                ['content' => $request->input('content')]
            )
            : StudyMessage::create($messageAttributes);

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'message' => [
                    'id' => $message->id,
                    'group_id' => $message->group_id,
                    'user_id' => $message->user_id,
                    'content' => $message->content,
                    'created_at' => $message->created_at,
                ],
            ],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $group = $request->user()->studyGroups()->findOrFail($id);
        $messages = $group->messages()
            ->with([
                'user' => fn ($query) => $query
                    ->select('id', 'name')
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
                'user.profile:id,user_id,profile_photo_url',
            ])
            ->oldest()
            ->get();
        $this->markConversationRead($request, 'group', $group->id, $messages);

        return response()->json([
            'success' => true,
            'message' => 'Group messages retrieved successfully.',
            'data' => [
                'messages' => $messages->map(function (StudyMessage $message) use ($request) {
                    return [
                        'id' => $message->id,
                        'user_id' => $message->user_id,
                        'sender' => $message->user_id === $request->user()->id ? 'me' : 'them',
                        'content' => $message->content,
                        'created_at' => $message->created_at,
                        'user' => $message->user ? [
                            'id' => $message->user->id,
                            'name' => $message->user->name,
                            'profile_photo_url' => $message->user->profile?->profile_photo_url,
                            'average_rating' => $message->user->buddy_reviews_received_avg_rating === null
                                ? null
                                : round((float) $message->user->buddy_reviews_received_avg_rating, 1),
                            'ratings_count' => $message->user->buddy_reviews_received_count,
                        ] : null,
                    ];
                }),
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $message = StudyMessage::where(function ($query) use ($request) {
            $query->whereHas(
                'group.members',
                fn ($query) => $query->where('users.id', $request->user()->id)
            )->orWhereHas('directConversation', fn ($conversation) => $conversation
                ->where('user_one_id', $request->user()->id)
                ->orWhere('user_two_id', $request->user()->id));
        })->findOrFail($id);

        if ($message->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to delete this message.',
            ], 403);
        }

        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully.',
        ]);
    }

    private function unreadMessageCount(
        Request $request,
        string $type,
        int $conversationId,
        HasMany $messages
    ): int {
        $lastReadMessageId = $this->lastReadMessageId($request, $type, $conversationId);

        return $messages
            ->where('user_id', '<>', $request->user()->id)
            ->when($lastReadMessageId, fn ($query) => $query->where('id', '>', $lastReadMessageId))
            ->count();
    }

    private function unreadMessageSenderIds(
        Request $request,
        string $type,
        int $conversationId,
        HasMany $messages
    ): array {
        $lastReadMessageId = $this->lastReadMessageId($request, $type, $conversationId);

        return $messages
            ->where('user_id', '<>', $request->user()->id)
            ->when($lastReadMessageId, fn ($query) => $query->where('id', '>', $lastReadMessageId))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($userId): int => (int) $userId)
            ->all();
    }

    private function lastReadMessageId(Request $request, string $type, int $conversationId): ?int
    {
        $lastReadMessageId = ConversationReadState::query()
            ->where('user_id', $request->user()->id)
            ->where('conversation_type', $type)
            ->where('conversation_id', $conversationId)
            ->value('last_read_message_id');

        return $lastReadMessageId === null ? null : (int) $lastReadMessageId;
    }

    private function markConversationRead(
        Request $request,
        string $type,
        int $conversationId,
        Collection $messages
    ): void {
        $latestMessageId = $messages->max('id');
        if (! $latestMessageId) {
            return;
        }

        ConversationReadState::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'conversation_type' => $type,
                'conversation_id' => $conversationId,
            ],
            ['last_read_message_id' => $latestMessageId]
        );
    }

    private function findDirectConversation(Request $request, int $id): DirectConversation
    {
        $conversation = DirectConversation::query()
            ->whereKey($id)
            ->where(fn ($query) => $query
                ->where('user_one_id', $request->user()->id)
                ->orWhere('user_two_id', $request->user()->id))
            ->firstOrFail();

        $otherUserId = $conversation->user_one_id === $request->user()->id
            ? $conversation->user_two_id
            : $conversation->user_one_id;
        abort_if(UserBlock::blocksEitherDirection($request->user()->id, $otherUserId), 404);

        return $conversation;
    }
}
