<?php

namespace Tests\Unit;

use App\Rules\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الرقم يُقبل من أي دولة — بشرط أن تكون دولةً موجودة.
 *
 * الخطأ الذي يحرسه هذا الملف صامتٌ بطبيعته: رقمٌ مختلق يُحفظ بلا اعتراض،
 * ولا يُكتشف يوم الإدخال بل يوم تحتاج المدرسة أن تتّصل بأهل طالب غائب.
 */
class PhoneNumberRuleTest extends TestCase
{
    private function passes(string $phone): bool
    {
        return Validator::make(
            ['phone' => $phone],
            ['phone' => [new PhoneNumber]],
        )->passes();
    }

    /** @return array<string, array{string}> */
    public static function validNumbers(): array
    {
        return [
            'محليّ سوريّ' => ['0955123456'],
            'سوريا بالرمز' => ['+963955123456'],
            'سوريا بصيغة 00' => ['00963955123456'],
            'السعودية' => ['+966501234567'],
            'الإمارات' => ['+971501234567'],
            'تركيا' => ['+905321234567'],
            'مصر' => ['+201001234567'],
            'ألمانيا' => ['+4915112345678'],
            'أمريكا' => ['+12125551234'],
            'مسافات وشُرَط' => ['+963 955 123-456'],
            'أقواس' => ['+1 (212) 555-1234'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidNumbers(): array
    {
        return [
            'رمز دولة غير موجود' => ['+99912345678'],
            'أصفار' => ['+00000000000'],
            'رمز مختلق' => ['+88812345678'],
            'قصير جداً' => ['+96312'],
            'طويل جداً' => ['+9639551234567890'],
            'حروف' => ['+963abc123456'],
            'محليّ قصير' => ['12345'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_a_real_number_passes(string $phone): void
    {
        $this->assertTrue($this->passes($phone), "رُفض رقم صحيح: {$phone}");
    }

    #[DataProvider('invalidNumbers')]
    public function test_a_made_up_number_is_refused(string $phone): void
    {
        $this->assertFalse($this->passes($phone), "قُبل رقم خاطئ: {$phone}");
    }

    public function test_an_empty_value_is_left_to_required(): void
    {
        // لارافيل يتخطّى قواعد الصيغة على القيمة الفارغة عمداً: الفراغ شأن
        // `required`، وخلطُ المسؤوليتين يجعل حقلاً اختيارياً إلزاميّاً.
        $this->assertTrue($this->passes(''));
    }

    public function test_the_longest_country_code_wins(): void
    {
        // `1` رمز أمريكا الشمالية، و`123` ليس رمزاً. لولا مطابقة الأطول
        // أوّلاً لمرّ كلّ رقمٍ يبدأ بواحد مهما كان ما بعده.
        $this->assertTrue($this->passes('+12125551234'));

        // `999` غير مخصَّص، ولا يجوز أن يُقرأ كـ`9` (وهو أيضاً غير مخصَّص).
        $this->assertFalse($this->passes('+99912345678'));
    }
}
