<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Services\CommentService;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(private CommentService $comments) {}

    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'commentable_type' => ['required', 'string', 'in:project,feature_request'],
            'commentable_id' => ['required', 'integer'],
        ]);

        $this->assertCommentableBelongsToProject($project, $data['commentable_type'], $data['commentable_id']);

        return Comment::where('project_id', $project->id)
            ->where('commentable_type', $data['commentable_type'])
            ->where('commentable_id', $data['commentable_id'])
            ->with(['user:id,name,email', 'mentions.mentionedUser:id,name'])
            ->orderBy('id')
            ->get();
    }

    public function store(Request $request, Project $project)
    {
        // Viewers can read a project fully but shouldn't be able to post —
        // commenting is a form of acting on the project's discussion.
        $this->authorize('act', $project);

        $data = $request->validate([
            'commentable_type' => ['required', 'string', 'in:project,feature_request'],
            'commentable_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $this->assertCommentableBelongsToProject($project, $data['commentable_type'], $data['commentable_id']);

        $comment = $this->comments->create($project, $data['commentable_type'], $data['commentable_id'], $request->user(), $data['body']);

        return response()->json($comment, 201);
    }

    public function destroy(Request $request, Project $project, Comment $comment)
    {
        abort_unless($comment->project_id === $project->id, 404);
        // Author can delete their own comment; otherwise it takes 'manage'
        // (an Admin/Owner moderating the discussion).
        if ($comment->user_id !== $request->user()->id) {
            $this->authorize('manage', $project);
        } else {
            $this->authorize('act', $project);
        }

        $comment->delete();

        return response()->noContent();
    }

    private function assertCommentableBelongsToProject(Project $project, string $type, int $id): void
    {
        if ($type === 'project') {
            abort_unless($project->id === $id, 404);

            return;
        }

        abort_unless(FeatureRequest::where('id', $id)->where('project_id', $project->id)->exists(), 404);
    }
}
