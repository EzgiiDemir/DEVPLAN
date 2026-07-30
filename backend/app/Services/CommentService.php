<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommentService
{
    /**
     * @param  Project  $project  Denormalized owner for cascade-delete/scoped queries.
     * @param  string  $commentableType  Morph map alias — 'project' or 'feature_request'.
     */
    public function create(Project $project, string $commentableType, int $commentableId, User $author, string $body): Comment
    {
        return DB::transaction(function () use ($project, $commentableType, $commentableId, $author, $body) {
            $comment = Comment::create([
                'project_id' => $project->id,
                'commentable_type' => $commentableType,
                'commentable_id' => $commentableId,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            foreach ($this->resolveMentions($project, $body) as $mentionedUser) {
                $comment->mentions()->create(['mentioned_user_id' => $mentionedUser->id]);
            }

            return $comment->load(['user:id,name,email', 'mentions.mentionedUser:id,name']);
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveMentions(Project $project, string $body): Collection
    {
        if ($project->team_id === null) {
            return collect();
        }

        $teamMembers = User::whereIn('id', function ($query) use ($project) {
            $query->select('user_id')->from('team_members')->where('team_id', $project->team_id);
        })->get(['id', 'name']);

        return $teamMembers->filter(fn (User $member) => str_contains($body, '@'.$member->name));
    }
}
