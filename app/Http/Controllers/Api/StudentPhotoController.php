<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * صورة الطالب: يرفعها من يملك تعديل بياناته، وتُستبدل الصورة السابقة.
 *
 * على القرص العامّ لأنّها تُعرض في القوائم بلا توكن — والاسم عشوائيّ طويل،
 * فلا يُخمَّن رابط صورة طالبٍ من رقمه.
 */
class StudentPhotoController extends Controller
{
    public function store(Request $request, Student $student): JsonResponse
    {
        $this->authorize('update', $student);

        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $old = $student->photo_path;
        $path = $data['photo']->store('students/photos', 'public');

        $student->forceFill(['photo_path' => $path])->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'message' => __('messages.student.photo_updated'),
            'data' => new StudentResource($student->load('currentEnrollment.section.grade')),
        ]);
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->authorize('update', $student);

        if ($student->photo_path) {
            Storage::disk('public')->delete($student->photo_path);
            $student->forceFill(['photo_path' => null])->save();
        }

        return response()->json([
            'message' => __('messages.student.photo_removed'),
            'data' => new StudentResource($student->load('currentEnrollment.section.grade')),
        ]);
    }
}
