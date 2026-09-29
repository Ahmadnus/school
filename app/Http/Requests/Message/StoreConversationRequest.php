<?php

namespace App\Http\Requests\Message;

use App\Enums\ComplaintCategory;
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
            // موضوع الشكوى: أستاذٌ بعينه أو بابٌ من الخدمات — واحدٌ لا اثنان.
            'about_staff_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('school_id', $schoolId),
            ],
            'complaint_category' => ['nullable', Rule::enum(ComplaintCategory::class)],
            'participant_ids' => ['nullable', 'array'],
            'participant_ids.*' => [Rule::exists('users', 'id')->where('school_id', $schoolId)],
            // الرسالة الافتتاحية نصٌّ أو ملفات أو كلاهما — لا فراغ.
            // نفس قاعدة `StoreMessageRequest`: من يفتح خيطاً بصورةٍ وحدها
            // كان يُضطرّ أن يكتب حرفاً ثم يرفق في رسالةٍ ثانية.
            'body' => ['nullable', 'string', 'max:10000', 'required_without:files'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:10240'],
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
            $ids = array_filter((array) $this->input('participant_ids', []));

            // وليُّ الأمر يراسل المدرسة، لا أولياء الأمور الآخرين: دليل
            // الأهالي أسماءُ عائلاتٍ وأرقامها، ولا يُفتح لبعضهم على بعض.
            if ($this->user()->role->isGuardian() && $ids !== []) {
                $otherGuardian = User::query()
                    ->whereIn('id', $ids)
                    ->where('role', UserRole::Guardian)
                    ->whereKeyNot($this->user()->id)
                    ->exists();

                if ($otherGuardian) {
                    $validator->errors()->add(
                        'participant_ids',
                        __('messages.conversation.guardian_to_guardian'),
                    );
                }
            }

            if ($this->input('type') === ConversationType::Complaints->value) {
                $this->validateComplaint($validator);

                return;
            }

            if ($this->input('type') !== ConversationType::Staff->value) {
                return;
            }

            if ($this->user()->role->isGuardian()) {
                $validator->errors()->add('type', __('messages.conversation.staff_only'));

                return;
            }

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

    /**
     * الشكوى: وليُّ أمرٍ يقدّمها، على أستاذٍ أو على بابٍ من الخدمات.
     *
     * أحدهما لا كلاهما: شكوى على أستاذٍ **و**على المقصف معاً تصل الإدارة بلا
     * موضوعٍ واضح، فلا تُفرَز ولا تُحال إلى من يملك حلّها.
     */
    private function validateComplaint(Validator $validator): void
    {
        if (! $this->user()->role->isGuardian()) {
            $validator->errors()->add('type', __('messages.conversation.complaint_by_guardian_only'));

            return;
        }

        $staffId = $this->input('about_staff_id');
        $category = $this->input('complaint_category');

        if (($staffId === null) === ($category === null)) {
            $validator->errors()->add(
                'about_staff_id',
                __('messages.conversation.complaint_needs_one_subject'),
            );

            return;
        }

        if ($staffId === null) {
            return;
        }

        // على موظّفٍ لا على وليّ أمرٍ آخر ولا على سائق: الشكوى تخصّ من يدرّس
        // أو يشرف، وغيرُ ذلك بابٌ آخر.
        $isStaff = User::query()
            ->whereKey($staffId)
            ->whereIn('role', [UserRole::Teacher, UserRole::Admin, UserRole::SuperAdmin])
            ->exists();

        if (! $isStaff) {
            $validator->errors()->add(
                'about_staff_id',
                __('messages.conversation.complaint_about_staff_only'),
            );
        }
    }
}
