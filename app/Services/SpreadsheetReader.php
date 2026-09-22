<?php

namespace App\Services;

use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads .xlsx and .csv into a header row plus data rows.
 *
 * .xls is deliberately unsupported — the upload screen says so, and OpenSpout
 * does not read the legacy binary format.
 */
class SpreadsheetReader
{
    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public static function read(string $absolutePath, string $extension, int $maxRows = 5000): array
    {
        $reader = match (strtolower($extension)) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader,
            default => throw new RuntimeException('unsupported_format'),
        };

        $reader->open($absolutePath);

        $headers = [];
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    // Row::toArray() yields raw values; dates come back as
                    // DateTimeInterface, so normalise those to Y-m-d.
                    $cells = array_map(
                        fn ($value) => $value instanceof \DateTimeInterface
                            ? $value->format('Y-m-d')
                            : trim((string) $value),
                        $row->toArray(),
                    );

                    if ($index === 1) {
                        $headers = $cells;

                        continue;
                    }

                    // Skip rows that are entirely blank.
                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $rows[] = $cells;

                    if (count($rows) >= $maxRows) {
                        break 2;
                    }
                }

                // Only the first sheet is imported.
                break;
            }
        } finally {
            $reader->close();
        }

        if ($headers === []) {
            throw new RuntimeException('empty_file');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * A first guess at the column mapping, so the mapping screen opens
     * pre-filled rather than empty.
     *
     * @param  array<int, string>  $headers
     * @return array<string, int> field => column index
     */
    public static function guessMapping(array $headers): array
    {
        $aliases = [
            'first_name' => ['الاسم الأول', 'الاسم', 'first name', 'firstname', 'first'],
            'last_name' => ['الاسم الأخير', 'الكنية', 'last name', 'lastname', 'last'],
            'external_id' => ['معرّف خاص', 'معرف خاص', 'الرقم', 'external id', 'id'],
            'birth_date' => ['تاريخ الميلاد', 'الميلاد', 'birth date', 'dob'],
            'gender' => ['الجنس', 'gender', 'sex'],
            'nationality' => ['الجنسية', 'nationality'],
            'blood_type' => ['فصيلة الدم', 'blood type', 'blood'],
            'address' => ['العنوان', 'address'],
            'building' => ['المبنى', 'الفرع', 'building', 'branch'],
            'medical_notes' => ['ملاحظات طبية', 'medical notes', 'medical'],
            'guardian_name' => ['اسم ولي الأمر', 'ولي الأمر', 'guardian', 'guardian name'],
            'guardian_phone' => ['هاتف ولي الأمر', 'الهاتف', 'guardian phone', 'phone'],
        ];

        $mapping = [];

        foreach ($headers as $index => $header) {
            $needle = mb_strtolower(trim($header));

            foreach ($aliases as $field => $names) {
                if (isset($mapping[$field])) {
                    continue;
                }

                foreach ($names as $name) {
                    if ($needle === mb_strtolower($name)) {
                        $mapping[$field] = $index;

                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }
}
