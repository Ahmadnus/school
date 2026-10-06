<?php

namespace App\Http\Requests\Post;

use App\Enums\TargetScope;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One create screen with variable fields for all 13 types — the spec leaves
 * that choice open (§7-b question 1), so the shape here is the shared core:
 * title, body, optional subject, and targeting.
 */
class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'post_type_id' => [
                'required',
                Rule::exists('post_types', 'id')->where('school_id', $schoolId)->where('is_enabled', true),
            ],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'subject_id' => [
                'nullable',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.scope' => ['required', Rule::enum(TargetScope::class)],
            'targets.*.target_id' => ['nullable', 'integer'],
            // draft = keep editing, submit = send through the approval flow.
            'submit' => ['nullable', 'boolean'],
            'requires_confirmation' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('targets', []) as $index => $target) {
                $scope = $target['scope'] ?? null;
                $id = $target['target_id'] ?? null;

                // Only the whole-school scope may omit an id.
                if ($scope !== TargetScope::School->value && $id === null) {
                    $validator->errors()->add("targets.$index.target_id", __('messages.post.target_required'));
                }
            }

            self::rejectUnreachableTargets($validator, $this->user(), $this->input('targets', []));
        });
    }

    /**
     * منشورٌ لطالبٍ أو لشعبة يصل إلى أهلها مباشرة، فلا يوجّهه إلّا من يصلهم:
     * الإدارة إلى أيّ أحد، والأستاذ إلى شعبه وطلابها. الصفّ والمعهد كلّه
     * يحكمهما `min_role` في نوع المنشور كما كانا.
     */
    public static function rejectUnreachableTargets($validator, User $user, array $targets): void
    {
        foreach ($targets as $index => $target) {
            $id = isset($target['target_id']) ? (int) $target['target_id'] : null;

            $reachable = match ($target['scope'] ?? null) {
                TargetScope::Student->value => ($student = Student::find($id)) !== null && $user->can('view', $student),
                TargetScope::Section->value => $user->reachesSection($id),
                default => true,
            };

            if (! $reachable) {
                $validator->errors()->add("targets.$index.target_id", __('messages.unauthorized'));
            }
        }
    }
}
