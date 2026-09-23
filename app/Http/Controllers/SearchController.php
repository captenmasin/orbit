<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
        ]);
        $term = trim($data['q'] ?? '');
        if ($term === '') {
            return response()->json(['results' => [], 'has_more' => false]);
        }
        $search = mb_strtolower($term);
        $projectId = $data['project_id'] ?? null;
        $results = [];
        $hasMore = false;
        $sources = [
            'project' => ['projects', ['projects.name', 'projects.description'], 'overview'],
            'document' => ['project_documents', ['project_documents.title', 'project_documents.body'], 'documents'],
            'task' => ['tasks', ['tasks.title', 'tasks.description'], 'board'],
            'link' => ['project_links', ['project_links.label', 'project_links.category', 'project_links.url'], 'overview'],
            'secret' => ['project_secrets', ['project_secrets.name', 'project_secrets.environment', 'project_secrets.service', 'project_secrets.description', 'project_secrets.management_url'], 'secrets'],
        ];
        foreach ($sources as $type => [$table, $fields, $tab]) {
            $query = DB::table($table);
            if ($type === 'task') {
                $query->join('board_columns', 'tasks.board_column_id', '=', 'board_columns.id')
                    ->join('projects', 'board_columns.project_id', '=', 'projects.id');
            } elseif ($type !== 'project') {
                $query->join('projects', $table.'.project_id', '=', 'projects.id');
            }
            $query->when($projectId, fn (Builder $query) => $query->where('projects.id', $projectId))
                ->where(function (Builder $query) use ($fields, $search, $type): void {
                    foreach ($fields as $field) {
                        $query->orWhereRaw('instr(lower('.$field.'), ?) > 0', [$search]);
                    }
                    if ($type === 'project') {
                        $query->orWhereExists(fn (Builder $tags) => $tags->selectRaw('1')->from('project_tag')
                            ->join('tags', 'project_tag.tag_id', '=', 'tags.id')->whereColumn('project_tag.project_id', 'projects.id')
                            ->whereRaw('instr(lower(tags.name), ?) > 0', [$search]));
                    }
                });
            $records = $query->select([$table.'.id', 'projects.id as project_id', 'projects.name as project_name', ...$fields])
                ->orderByRaw('projects.name COLLATE NOCASE')->orderBy($table.'.id')->limit(11)->get();
            $hasMore = $hasMore || $records->count() > 10;
            foreach ($records->take(10) as $record) {
                $values = array_map(fn (string $field): string => (string) ($record->{substr($field, strrpos($field, '.') + 1)} ?? ''), $fields);
                $excerpt = '';
                foreach (array_slice($values, 1) as $value) {
                    if (mb_stripos($value, $term) !== false) {
                        $excerpt = $this->excerpt($value, $term);
                        break;
                    }
                }
                $results[] = [
                    'id' => $record->id,
                    'project_id' => $record->project_id,
                    'project' => $record->project_name,
                    'type' => $type,
                    'title' => $values[0],
                    'excerpt' => $excerpt,
                    'url' => route('projects.show', ['project' => $record->project_id, 'tab' => $tab, ...($type === 'project' ? [] : [$type => $record->id])], absolute: false),
                ];
            }
        }

        return response()->json(['results' => $results, 'has_more' => $hasMore])->header('Cache-Control', 'private, no-store');
    }

    private function excerpt(string $text, string $term): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $start = max(0, (int) mb_stripos($text, $term) - 60);

        return ($start > 0 ? '…' : '').mb_substr($text, $start, 180).(mb_strlen($text) > $start + 180 ? '…' : '');
    }
}
