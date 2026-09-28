<?php

namespace App\Http\Requests\Message;

use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'type' => ['required', Rule::enum(ConversationType::class)],
            // Guardian threads are always about one student.
            'student_id' => [
                'required_if:type,guardians', 'nullable',
                Rule::exists('students', 'id')->where('school_id', $schoolId),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'participant_ids' => ['nullable', 'array'],
            'participant_ids.*' => [Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * تبويب الكادر للكادر وحده.
     *
     * بلا هذا يكفي أن يرسل العميل `type=staff` ليدخل خيطُ وليّ أمرٍ بين
     * محادثات الموظّفين الداخلية — وقراءةُ القائمة صارت تحرسه بالأطراف،
     * فليُحرَس عند الكتابة أيضاً حتى لا يُخزَّن نوعٌ يكذب على صاحبه.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('type') !== ConversationType::Staff->value) {
                return;
            }

            if ($this->user()->role->isGuardian()) {
                $validator->errors()->add('type', __('messages.conversation.staff_only'));

                return;
            }

            $ids = array_filter((array) $this->input('participant_ids', []));

            if ($ids === []) {
                return;
            }

            $hasGuardian = User::query()
                ->whereIn('id', $ids)
                ->where('role', UserRole::Guardian)
                ->exists();

            if ($hasGuardian) {
                $validator->errors()->add(
                    'participant_ids',
                    __('messages.conversation.guardian_in_staff_thread'),
                );
            }
        }];
    }
}
