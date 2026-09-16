<?php

namespace App\Services;

use App\Rules\PhoneNumber;
use App\Enums\EnrollmentScope;
use App\Enums\Gender;
use App\Enums\GuardianRelation;
use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCounter;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The two screens the spec lists as missing: column mapping (#6) and row
 * review (#7). Mapping validates every row up front so the review screen can
 * show exactly what will and will not be imported, and nothing is written to
 * students until the import is committed.
 */
class StudentImportProcessor
{
    /**
     * Applies a column mapping and validates every row.
     *
     * @param  array<string, int>  $mapping  field => column index
     */
    public static function applyMapping(StudentImport $import, array $mapping): StudentImport
    {
        $import->update(['mapping' => $mapping]);

        // External ids already taken, so a duplicate is caught before commit.
        $takenExternalIds = Student::query()
            ->where('school_id', $import->school_id)
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->all();

        $seenExternalIds = [];
        $valid = 0;
        $invalid = 0;

        foreach ($import->rows()->get() as $row) {
            $mapped = self::mapRow($row->raw, $mapping);
            $errors = self::validate($mapped);

            // A duplicate external id inside the same file is an error too.
            $external = $mapped['external_id'] ?? null;

            if ($external !== null && $external !== '') {
                if (in_array($external, $takenExternalIds, true)) {
                    $errors['external_id'][] = __('messages.import.external_id_taken');
                } elseif (in_array($external, $seenExternalIds, true)) {
                    $errors['external_id'][] = __('messages.import.external_id_duplicate');
                } else {
                    $seenExternalIds[] = $external;
                }
            }

            $ok = $errors === [];
            $ok ? $valid++ : $invalid++;

            $row->update([
                'mapped' => $mapped,
                'errors' => $ok ? null : $errors,
                'status' => $ok ? ImportRowStatus::Valid : ImportRowStatus::Invalid,
            ]);
        }

        $import->update([
            'status' => ImportStatus::Mapped,
            'valid_count' => $valid,
            'invalid_count' => $invalid,
        ]);

        return $import->fresh();
    }

    /**
     * Writes the valid rows: a student, an enrollment into the section chosen
     * before upload, and a guardian link when the file carries one.
     */
    public static function commit(StudentImport $import): StudentImport
    {
        $rows = $import->validRows()->get();

        if ($rows->isEmpty()) {
            return $import;
        }

        DB::transaction(function () use ($import, $rows) {
            // One contiguous block of student numbers for the whole file.
            $next = StudentCounter::reserve($import->school_id, $rows->count());

            foreach ($rows as $row) {
                $data = $row->mapped;

                $student = Student::create([
                    'school_id' => $import->school_id,
                    'student_number' => $next++,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? null,
                    'external_id' => ($data['external_id'] ?? '') !== '' ? $data['external_id'] : null,
                    'birth_date' => $data['birth_date'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'nationality' => $data['nationality'] ?? null,
                    'blood_type' => $data['blood_type'] ?? null,
                    'address' => $data['address'] ?? null,
                    'building' => $data['building'] ?? null,
                    'medical_notes' => $data['medical_notes'] ?? null,
                ]);

                $student->enrollments()->create([
                    'section_id' => $import->section_id,
                    'academic_year_id' => $import->academic_year_id,
                    'scope' => EnrollmentScope::FullYear,
                    'enrolled_at' => now()->toDateString(),
                ]);

                self::linkGuardian($import, $student, $data);

                $row->update([
                    'status' => ImportRowStatus::Imported,
                    'student_id' => $student->id,
                ]);
            }

            $import->update([
                'status' => ImportStatus::Committed,
                'imported_count' => $rows->count(),
            ]);
        });

        return $import->fresh();
    }

    /**
     * @param  array<int, string>  $raw
     * @param  array<string, int>  $mapping
     * @return array<string, string|null>
     */
    private static function mapRow(array $raw, array $mapping): array
    {
        $mapped = [];

        foreach ($mapping as $field => $index) {
            if (! array_key_exists($field, StudentImport::FIELDS)) {
                continue;
            }

            $value = trim((string) ($raw[$index] ?? ''));
            $mapped[$field] = $value === '' ? null : $value;
        }

        // Normalise the two fields that arrive in loose shapes.
        if (isset($mapped['gender'])) {
            $mapped['gender'] = self::normaliseGender($mapped['gender']);
        }

        if (isset($mapped['birth_date'])) {
            $mapped['birth_date'] = self::normaliseDate($mapped['birth_date']);
        }

        return $mapped;
    }

    /** @return array<string, array<int, string>> */
    private static function validate(array $mapped): array
    {
        $validator = Validator::make($mapped, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'external_id' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'blood_type' => ['nullable', 'string', 'max:8'],
            'address' => ['nullable', 'string', 'max:1000'],
            'building' => ['nullable', 'string', 'max:255'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', new PhoneNumber],
        ]);

        return $validator->fails() ? $validator->errors()->toArray() : [];
    }

    private static function linkGuardian(StudentImport $import, Student $student, array $data): void
    {
        $phone = $data['guardian_phone'] ?? null;
        $name = $data['guardian_name'] ?? null;

        if (! $phone && ! $name) {
            return;
        }

        // Phone is the identity; without one there is nothing to match on.
        if (! $phone) {
            return;
        }

        $guardian = Guardian::firstOrCreate(
            ['school_id' => $import->school_id, 'phone' => $phone],
            ['name' => $name ?: $phone],
        );

        $student->guardianLinks()->create([
            'guardian_id' => $guardian->id,
            'relation' => GuardianRelation::Father,
            'is_primary' => true,
        ]);
    }

    private static function normaliseGender(string $value): string
    {
        $needle = mb_strtolower(trim($value));

        return match (true) {
            in_array($needle, ['ذكر', 'male', 'm', 'ذ'], true) => Gender::Male->value,
            in_array($needle, ['أنثى', 'انثى', 'female', 'f', 'أ'], true) => Gender::Female->value,
            default => $value,
        };
    }

    private static function normaliseDate(string $value): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (InvalidFormatException|InvalidArgumentException $e) {
                // Carbon throws on a mismatch rather than returning false, and
                // a row with an unparseable date must fail validation, not the
                // whole mapping pass.
                continue;
            }

            if ($date && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return $value;
    }

    /** Marks a row as skipped so the review screen can exclude it. */
    public static function skip(StudentImportRow $row): void
    {
        $row->update(['status' => ImportRowStatus::Skipped]);
    }
}
