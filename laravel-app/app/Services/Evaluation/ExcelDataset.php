<?php

namespace App\Services\Evaluation;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use ZipArchive;

class ExcelDataset
{
    private const SHEETS = [
        'Dataset' => ['dataset_version'],
        'Questions' => [
            'question_id',
            'question',
            'reference_answer',
            'split',
            'category',
            'is_answerable',
        ],
        'Evidence' => [
            'question_id',
            'source',
            'page',
            'section',
            'evidence_text',
        ],
    ];

    public function read(UploadedFile $file): array
    {
        $this->inspectArchive($file->getRealPath());

        $book = null;

        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true)
                ->setReadEmptyCells(false)
                ->setLoadSheetsOnly(array_keys(self::SHEETS));

            foreach ($reader->listWorksheetInfo($file->getRealPath()) as $info) {
                if (
                    ! array_key_exists($info['worksheetName'], self::SHEETS)
                    && $info['worksheetName'] !== 'Instructions'
                ) {
                    $this->invalid('استخدم قالب Golden Dataset v2 فقط.');
                }

                $maxRows = match ($info['worksheetName']) {
                    'Dataset' => 2,
                    'Questions' => 101,
                    'Evidence' => 2001,
                    default => 100,
                };

                if ($info['totalRows'] > $maxRows || $info['totalColumns'] > 10) {
                    $this->invalid('حجم ورقة Excel يتجاوز الحدود المسموحة.');
                }
            }

            $book = $reader->load($file->getRealPath());
            $tables = [];

            foreach (self::SHEETS as $name => $headers) {
                $sheet = $book->getSheetByName($name);

                if (! $sheet) {
                    $this->invalid("الورقة المطلوبة غير موجودة: {$name}");
                }

                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    if (
                        in_array(
                            $sheet->getCell($coordinate)->getDataType(),
                            [DataType::TYPE_FORMULA, DataType::TYPE_ERROR],
                            true,
                        )
                    ) {
                        $this->invalid(
                            'استخدم قيماً ثابتة في Excel، بدون صيغ حسابية أو أخطاء خلايا.'
                        );
                    }
                }

                $rows = $sheet->toArray(null, false, false, false);
                $header = array_shift($rows) ?? [];

                while ($header !== [] && end($header) === null) {
                    array_pop($header);
                }

                if ($header !== $headers) {
                    $this->invalid(
                        "أعمدة الورقة {$name} يجب أن تكون: ".implode(', ', $headers)
                    );
                }

                $tables[$name] = [];

                foreach ($rows as $row) {
                    if (
                        array_filter(
                            $row,
                            fn ($value) => $value !== null && $value !== ''
                        ) === []
                    ) {
                        continue;
                    }

                    if (
                        array_filter(
                            array_slice($row, count($headers)),
                            fn ($value) => $value !== null && $value !== ''
                        ) !== []
                    ) {
                        $this->invalid("توجد أعمدة إضافية في {$name}.");
                    }

                    $tables[$name][] = array_pad(
                        array_slice($row, 0, count($headers)),
                        count($headers),
                        null,
                    );
                }
            }

            if (count($tables['Dataset']) !== 1) {
                $this->invalid('أدخل إصدار dataset واحداً في ورقة Dataset.');
            }

            $examples = [];

            foreach (
                $tables['Questions'] as [$id, $question, $answer, $split, $category, $answerable]
            ) {
                $id = $this->questionId($id);

                if (array_key_exists($id, $examples)) {
                    $this->invalid("معرف سؤال مكرر: {$id}");
                }

                $examples[$id] = [
                    'question_id' => $id,
                    'question' => $question,
                    'reference_answer' => $answer,
                    'split' => $split ?: 'held_out',
                    'category' => $category,
                    'is_answerable' => $this->boolean($answerable),
                    'evidence' => [],
                ];
            }

            foreach (
                $tables['Evidence'] as [$id, $source, $page, $section, $evidenceText]
            ) {
                $id = $this->questionId($id);

                if (! array_key_exists($id, $examples)) {
                    $this->invalid("معرف سؤال غير موجود في Evidence: {$id}");
                }

                $examples[$id]['evidence'][] = [
                    'source' => $this->nullableString($source),
                    'page' => $this->nullableInteger($page),
                    'section' => $this->nullableString($section),
                    'evidence_text' => $evidenceText,
                ];
            }

            return [
                'schema_version' => 2,
                'dataset_version' => $tables['Dataset'][0][0],
                'examples' => array_values($examples),
            ];
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->invalid(
                'تعذّرت قراءة Excel. استخدم ملف xlsx غير مشفّر مطابقاً لقالب Golden Dataset v2.'
            );
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    public function writeTemplate(string $path): void
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $tables = [
            'Dataset' => [
                ['dataset_version'],
                ['golden-v2'],
            ],
            'Questions' => [
                [
                    'question_id',
                    'question',
                    'reference_answer',
                    'split',
                    'category',
                    'is_answerable',
                ],
                [
                    'q1',
                    'استبدل هذا النص بسؤال من الوثيقة',
                    'الإجابة المرجعية المدعومة من الوثيقة',
                    'held_out',
                    'factual',
                    1,
                ],
                [
                    'q2',
                    'مثال لسؤال لا تجيب عنه الوثيقة',
                    '',
                    'held_out',
                    'unanswerable',
                    0,
                ],
            ],
            'Evidence' => [
                [
                    'question_id',
                    'source',
                    'page',
                    'section',
                    'evidence_text',
                ],
                [
                    'q1',
                    '',
                    1,
                    '',
                    'ضع هنا مقتطفاً قصيراً وثابتاً يدعم الإجابة.',
                ],
            ],
            'Instructions' => [
                [
                    'الورقة',
                    'الحقل',
                    'الحالة',
                    'التعليمات',
                    'مثال',
                ],
                [
                    'Dataset',
                    'dataset_version',
                    'مطلوب',
                    'معرف إصدار مجموعة البيانات. استخدم قيمة ثابتة تميز النسخة التي يجري تقييمها.',
                    'golden-v2',
                ],
                [
                    'Questions',
                    'question_id',
                    'مطلوب',
                    'معرف غير فارغ وفريد لكل سؤال. استخدم نفس المعرف عند إضافة Evidence للسؤال.',
                    'q1',
                ],
                [
                    'Questions',
                    'question',
                    'مطلوب',
                    'نص السؤال الذي سيُرسل إلى نظام RAG.',
                    'ما المعلومات المذكورة عن الموضوع؟',
                ],
                [
                    'Questions',
                    'reference_answer',
                    'مشروط',
                    'مطلوب عندما تكون is_answerable = 1. اتركه فارغاً للسؤال غير القابل للإجابة.',
                    'الإجابة المرجعية المدعومة من الوثائق.',
                ],
                [
                    'Questions',
                    'split',
                    'اختياري',
                    'استخدم development أثناء ضبط الإعدادات أو held_out للتقييم النهائي. إذا تُرك فارغاً يستخدم النظام held_out.',
                    'development',
                ],
                [
                    'Questions',
                    'category',
                    'اختياري',
                    'تصنيف وصفي للسؤال يساعد على تحليل النتائج حسب النوع.',
                    'factual',
                ],
                [
                    'Questions',
                    'is_answerable',
                    'مطلوب',
                    'استخدم 1 إذا كانت الوثائق المختارة تحتوي جواباً، و0 إذا كان السؤال غير قابل للإجابة منها.',
                    '1',
                ],
                [
                    'Evidence',
                    'question_id',
                    'مشروط',
                    'مطلوب لكل صف Evidence ويجب أن يطابق question_id موجوداً في Questions. السؤال القابل للإجابة يحتاج Evidence واحدة على الأقل، والسؤال غير القابل للإجابة لا يملك Evidence.',
                    'q1',
                ],
                [
                    'Evidence',
                    'source',
                    'اختياري',
                    'اسم الملف أو المصدر عندما تكون هذه المعلومة متوفرة، خصوصاً عند تقييم أكثر من وثيقة.',
                    'document.pdf',
                ],
                [
                    'Evidence',
                    'page',
                    'اختياري',
                    'رقم الصفحة إن توفر. يجب أن يكون عدداً صحيحاً موجباً.',
                    '3',
                ],
                [
                    'Evidence',
                    'section',
                    'اختياري',
                    'اسم القسم أو العنوان الذي يحتوي الدليل إن توفر.',
                    'المقدمة',
                ],
                [
                    'Evidence',
                    'evidence_text',
                    'مشروط',
                    'مطلوب في كل صف Evidence. أدخل مقتطفاً مرجعياً واضحاً يدعم الإجابة.',
                    'نص مقتطف من الوثيقة يدعم الإجابة.',
                ],
                [
                    'عام',
                    'document_id / chunk_index',
                    'لا يُستخدم',
                    'لا تضف document_id أو chunk_index داخل الملف. اختر الوثائق من شاشة التقييم وسيتم ربط Golden Evidence بالمقاطع تلقائياً.',
                    '—',
                ],
            ],
        ];

        foreach ($tables as $name => $rows) {
            $sheet = $book->createSheet()->setTitle($name);
            $sheet->setRightToLeft(true)
                ->fromArray($rows, null, 'A1', true);

            $sheet->freezePane('A2');

            $last = $sheet->getHighestDataColumn();
            $sheet->getStyle("A1:{$last}1")
                ->getFont()
                ->setBold(true);

            foreach (range('A', $last) as $column) {
                $sheet->getColumnDimension($column)
                    ->setWidth($column === 'A' ? 22 : 45);
            }

            $sheet->getStyle($sheet->calculateWorksheetDimension())
                ->getAlignment()
                ->setWrapText(true);
        }

        $book->setActiveSheetIndex(3);

        (new XlsxWriter($book))->save($path);

        $book->disconnectWorksheets();
    }

    private function inspectArchive(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            $this->invalid('ملف Excel غير صالح.');
        }

        try {
            if ($zip->numFiles > 250) {
                $this->invalid('ملف Excel يحتوي عناصر كثيرة جداً.');
            }

            $size = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $size += $entry['size'];

                if (
                    $size > 20 * 1024 * 1024
                    || preg_match(
                        '~(^/|(^|/)\.\.(/|$)|vbaProject|externalLinks)~i',
                        $entry['name']
                    )
                ) {
                    $this->invalid(
                        'ملف Excel يتجاوز الحجم الآمن أو يحتوي مكونات غير مدعومة.'
                    );
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function questionId(mixed $value): string
    {
        if (
            (! is_string($value) && ! is_int($value))
            || trim((string) $value) === ''
            || strlen((string) $value) > 100
        ) {
            $this->invalid('question_id يجب أن يكون معرفاً غير فارغ.');
        }

        return trim((string) $value);
    }

    private function boolean(mixed $value): bool
    {
        if (in_array($value, [1, '1', true], true)) {
            return true;
        }

        if (in_array($value, [0, '0', false], true)) {
            return false;
        }

        $this->invalid('is_answerable يجب أن يكون 1 أو 0.');
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            ! is_numeric($value)
            || ! is_finite((float) $value)
            || floor((float) $value) != $value
            || (int) $value < 1
        ) {
            $this->invalid('page يجب أن يكون رقماً صحيحاً موجباً.');
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'data.dataset' => $message,
        ]);
    }
}
