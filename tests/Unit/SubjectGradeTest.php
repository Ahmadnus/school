<?php

namespace Tests\Unit;

use App\Services\SubjectGrade;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * العلامة من مئة، والوزن حصّة **النوع**: كويزات ٢٠ · نصفي ٣٠ · نهائي ٥٠.
 */
class SubjectGradeTest extends TestCase
{
    /** ورقة: نوعها، سقفها، ووزن خاص بها إن وُجد. */
    private function paper(int $id, ?int $typeId, float $max, ?float $typeWeight = null, ?float $ownWeight = null): object
    {
        return (object) [
            'id' => $id,
            'assessment_type_id' => $typeId,
            'max_score' => $max,
            'weight_percent' => $ownWeight,
            'type' => $typeId === null ? null : (object) ['weight_percent' => $typeWeight],
        ];
    }

    /** @param array<int, float|null> $byAssessment */
    private function scores(array $byAssessment): Collection
    {
        return collect($byAssessment)
            ->filter(fn (?float $s) => $s !== null)
            ->map(fn (float $s) => (object) ['score' => $s]);
    }

    public function test_weighted_types_add_up_the_way_a_school_says_them(): void
    {
        $assessments = collect([
            $this->paper(1, 10, 100, 20.0),  // كويز
            $this->paper(2, 20, 100, 30.0),  // نصفي
            $this->paper(3, 30, 100, 50.0),  // نهائي
        ]);

        // ٨٠×٠٫٢ + ٦٠×٠٫٣ + ٩٠×٠٫٥ = ١٦ + ١٨ + ٤٥ = ٧٩
        $this->assertSame(79.0, SubjectGrade::percent($assessments, $this->scores([1 => 80, 2 => 60, 3 => 90])));
    }

    public function test_two_quizzes_share_their_type_weight(): void
    {
        $assessments = collect([
            $this->paper(1, 10, 100, 20.0),
            $this->paper(2, 10, 100, 20.0),
            $this->paper(3, 30, 100, 80.0),
        ]);

        // الكويزان ٢٠٪ معاً لا ٢٠٪ لكلٍّ: متوسّطهما ٧٠ ثم ×٠٫٢ = ١٤،
        // مع ٩٠×٠٫٨ = ٧٢ — المجموع ٨٦.
        $this->assertSame(86.0, SubjectGrade::percent($assessments, $this->scores([1 => 60, 2 => 80, 3 => 90])));
    }

    public function test_a_paper_out_of_twenty_is_read_as_a_ratio(): void
    {
        $assessments = collect([$this->paper(1, 10, 20, 100.0)]);

        // ١٨ من ٢٠ = ٩٠٪ مهما كان سقف الورقة.
        $this->assertSame(90.0, SubjectGrade::percent($assessments, $this->scores([1 => 18])));
    }

    public function test_an_exam_not_held_yet_does_not_lower_the_grade(): void
    {
        $assessments = collect([
            $this->paper(1, 10, 20, 20.0),
            $this->paper(2, 30, 100, 50.0),  // النهائي، بلا علامة بعد
        ]);

        // لو قُسم على المئة لظهر ١٨٪ وكأن الطالب راسب؛ الصواب ٩٠٪ من
        // الوزن الحاضر، وتنزل العلامة حين تصل علامة النهائي فعلاً.
        $this->assertSame(90.0, SubjectGrade::percent($assessments, $this->scores([1 => 18, 2 => null])));
    }

    public function test_unweighted_types_split_what_is_left(): void
    {
        $assessments = collect([
            $this->paper(1, 10, 100, 50.0),  // نصفه معلَن
            $this->paper(2, 20, 100),        // بلا وزن
            $this->paper(3, 30, 100),        // بلا وزن
        ]);

        // ٥٠ معلَنة، و٥٠ تتقاسمها ورقتان (٢٥ لكلٍّ):
        // ١٠٠×٠٫٥ + ٦٠×٠٫٢٥ + ٤٠×٠٫٢٥ = ٥٠ + ١٥ + ١٠ = ٧٥.
        $this->assertSame(75.0, SubjectGrade::percent($assessments, $this->scores([1 => 100, 2 => 60, 3 => 40])));
    }

    public function test_with_no_weights_at_all_everything_is_equal(): void
    {
        $assessments = collect([
            $this->paper(1, 10, 100),
            $this->paper(2, 20, 100),
        ]);

        // سلوك النظام قبل الأوزان — البيانات القائمة لا تتغيّر حساباتها.
        $this->assertSame(70.0, SubjectGrade::percent($assessments, $this->scores([1 => 60, 2 => 80])));
    }

    public function test_a_paper_weight_overrides_its_type(): void
    {
        $assessments = collect([
            $this->paper(1, 10, 100, 20.0, 60.0),  // كويز استثنائي بـ٦٠٪
            $this->paper(2, 30, 100, 40.0),
        ]);

        // ١٠٠×٠٫٦ + ٥٠×٠٫٤ = ٨٠.
        $this->assertSame(80.0, SubjectGrade::percent($assessments, $this->scores([1 => 100, 2 => 50])));
    }

    public function test_nothing_corrected_yet_is_not_zero(): void
    {
        $assessments = collect([$this->paper(1, 10, 100, 100.0)]);

        // صفرٌ ادّعاءُ رسوب؛ و`null` تقول الحقيقة: لا نعرف بعد.
        $this->assertNull(SubjectGrade::percent($assessments, $this->scores([1 => null])));
    }

    public function test_a_paper_out_of_zero_does_not_break_the_subject(): void
    {
        $assessments = collect([
            $this->paper(1, 10, 0, 50.0),   // ورقة من صفر — خطأ إدخال
            $this->paper(2, 30, 100, 50.0),
        ]);

        // القسمة على صفر كانت ستُسقط المادة كلّها بخطأ.
        $this->assertSame(80.0, SubjectGrade::percent($assessments, $this->scores([1 => 5, 2 => 80])));
    }
}
