<?php

namespace App\Http\Requests\Student;

use App\Enums\EnrollmentScope;
use App\Enums\Gender;
use App\Enums\Status;
use App\Models\AcademicYear;
use App\Models\FeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'external_id' => [
                'nullable', 'string', 'max:100',
                Rule::unique('students', 'external_id')->where('school_id', $schoolId),
            ],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'nationality' => ['nullable', 'string', 'max:100'],
            'blood_type' => ['nullable', 'string', 'max:8'],
            'address' => ['nullable', 'string', 'max:1000'],
            'building' => ['nullable', 'string', 'max:255'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(Status::class)],

            // Optional enrollment created alongside the student (the grade/section
            // section of the create form is marked "optional").
            'section_id' => [
                'nullable',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'scope' => ['nullable', 'required_with:section_id', Rule::enum(EnrollmentScope::class)],
            'enrolled_at' => ['nullable', 'required_with:section_id', 'date'],
            'transport_subscribed' => ['nullable', 'boolean'],

            // خطة الرسوم عند التسجيل. غيابها = لا تُنشَأ خطة، فيبقى السلوك
            // القديم كما هو لمن ينشئ الطالب أوّلاً ويعيّن الرسوم لاحقاً.
            'plan_mode' => ['nullable', 'required_with:section_id', 'in:none,full,subjects'],

            // خطة بعينها بدل الاعتماد على وسم «الافتراضي»: المستخدم يفكّر بـ
            // «قسط العلمي» لا بـ«النوع الموسوم افتراضيّاً للصف». غيابه يعني: خذ الافتراضي.
            'plan_fee_type_id' => [
                'nullable',
                Rule::exists('fee_types', 'id')->where('school_id', $schoolId),
            ],

            // مواد مختارة: مطلوبة في وضع subjects وحده.
            'subject_ids' => ['nullable', 'array', 'required_if:plan_mode,subjects', 'min:1'],
            'subject_ids.*' => [Rule::exists('subjects', 'id')],

            // المبلغ يُكتب يدويّاً مع المواد المختارة؛ الخطة الكاملة تأخذه
            // من نوع الرسوم الافتراضي للصف، وتمريره هنا يجعله يسود عليه.
            // لم يعد إلزاميّاً مع المواد المختارة: الخادم يجمع أسعارها،
            // ويبقى المكتوب مسموحاً ليسود عند الحاجة (حالة خاصّة، خصم متّفق
            // عليه). كتابته لكل تسجيل كانت تعني حسبةً يدويّة تُخطئ.
            'plan_total_amount' => ['nullable', 'numeric', 'min:0'],
            'plan_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'plan_discount_reason' => [
                'nullable',
                'string',
                'max:300',
                'required_with:plan_discount_amount',
            ],
        ];
    }

    /**
     * المواد المختارة تحتاج سعراً — من أسعار المواد أو مكتوباً باليد.
     *
     * بلا هذا الفحص يُنشأ الطالب ولا تُنشأ له خطة (المبلغ صفر، والخطة
     * الصفرية تُتخطّى عمداً)، فيمضي التسجيل بنجاحٍ ظاهر ويختفي الطالب من
     * صفحة الرسوم — مالٌ لا يطالب به أحد لأن أحداً لا يعلم أنه غائب.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('plan_mode') !== 'subjects') {
                return;
            }

            if ((float) $this->input('plan_total_amount', 0) > 0) {
                return;
            }

            $priced = FeeType::query()
                ->whereIn('subject_id', (array) $this->input('subject_ids', []))
                ->where('status', 'active')
                ->sum('total_minor');

            if ((int) $priced > 0) {
                return;
            }

            $validator->errors()->add(
                'plan_total_amount',
                __('validation.required', ['attribute' => __('validation.attributes.plan_total_amount')]),
            );
        });
    }
}
