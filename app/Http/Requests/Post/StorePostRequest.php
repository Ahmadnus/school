<?php

namespace App\Http\Requests\Post;

use App\Enums\TargetScope;
use App\Models\Grade;
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
        });
    }
}
