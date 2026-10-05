<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrollment = $this->whenLoaded('currentEnrollment');

        // الملف الشخصيّ للإدارة ولوليّ أمر الطالب وحدهما (StudentPolicy::viewPersonal).
        // بلا مستخدم (إشعار، كاش، أمر طرفيّ) يُرسل كاملاً كما كان.
        $user = $request->user();
        $personal = $user === null || $user->can('viewPersonal', $this->resource);

        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'student_number' => $this->student_number,
            'external_id' => $this->external_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'photo_url' => $this->photo_path
                ? Storage::disk('public')->url($this->photo_path)
                : null,
            'gender' => $this->gender?->value,
            'gender_label' => $this->gender?->label(),
            $this->mergeWhen($personal, fn () => [
                'birth_date' => $this->birth_date?->toDateString(),
                'nationality' => $this->nationality,
                'blood_type' => $this->blood_type,
                'address' => $this->address,
                'building' => $this->building,
                'medical_notes' => $this->medical_notes,
                'phone' => $this->phone,
                'email' => $this->email,
                'emergency_contact_name' => $this->emergency_contact_name,
                'emergency_contact_phone' => $this->emergency_contact_phone,
                'emergency_contact_relation' => $this->emergency_contact_relation,
            ]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // The list chip "<grade> - <section>" comes from the current-year enrollment.
            'current_enrollment' => $this->when(
                $this->relationLoaded('currentEnrollment'),
                fn () => $this->currentEnrollment
                    ? new StudentEnrollmentResource($this->currentEnrollment)
                    : null,
            ),
            'enrollments' => StudentEnrollmentResource::collection($this->whenLoaded('enrollments')),
            'guardians' => $this->when(
                $personal,
                fn () => GuardianResource::collection($this->whenLoaded('guardians')),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
