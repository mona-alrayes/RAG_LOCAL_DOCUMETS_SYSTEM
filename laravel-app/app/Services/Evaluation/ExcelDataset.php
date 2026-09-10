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
        'Questions' => ['question_id', 'question', 'expected_answer'],
        'Documents' => ['question_id', 'document_id'],
        'RelevantChunks' => ['question_id', 'document_id', 'chunk_index'],
    ];

    public function read(UploadedFile $file): array
    {
        $this->inspectArchive($file->getRealPath());
        $book = null;
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true)->setReadEmptyCells(false)->setLoadSheetsOnly(array_keys(self::SHEETS));
            foreach ($reader->listWorksheetInfo($file->getRealPath()) as $info) {
                if (! array_key_exists($info['worksheetName'], self::SHEETS) && $info['worksheetName'] !== 'Instructions') {
                    $this->invalid('استخدم قالب Excel الخاص بالداشبورد؛ أسماء أوراق الملف غير مطابقة.');
                }
                $maxRows = match ($info['worksheetName']) {
                    'Dataset' => 2, 'Questions' => 101, 'Documents' => 2001, default => 10001
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
                    $this->invalid("الورقة المطلوبة غير موجودة: $name");
                }
                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    if (in_array($sheet->getCell($coordinate)->getDataType(), [DataType::TYPE_FORMULA, DataType::TYPE_ERROR], true)) {
                        $this->invalid('استخدم قيماً ثابتة في Excel، بدون صيغ حسابية أو أخطاء خلايا.');
                    }
                }
                $rows = $sheet->toArray(null, false, false, false);
                $header = array_shift($rows) ?? [];
                while ($header !== [] && end($header) === null) {
                    array_pop($header);
                }
                if ($header !== $headers) {
                    $this->invalid("أعمدة الورقة $name يجب أن تكون: ".implode(', ', $headers));
                }
                $tables[$name] = [];
                foreach ($rows as $row) {
                    if (array_filter($row, fn ($value) => $value !== null && $value !== '') === []) {
                        continue;
                    }
                    if (array_filter(array_slice($row, count($headers)), fn ($value) => $value !== null && $value !== '') !== []) {
                        $this->invalid("توجد أعمدة إضافية في $name.");
                    }
                    $tables[$name][] = array_pad(array_slice($row, 0, count($headers)), count($headers), null);
                }
            }
            if (count($tables['Dataset']) !== 1) {
                $this->invalid('أدخل إصدار dataset واحداً في ورقة Dataset.');
            }
            $examples = [];
            foreach ($tables['Questions'] as [$id, $question, $answer]) {
                $id = $this->questionId($id);
                if (array_key_exists($id, $examples)) {
                    $this->invalid("معرف سؤال مكرر: $id");
                }
                $examples[$id] = ['question' => $question, 'expected_answer' => $answer, 'document_ids' => [], 'relevant_chunks' => []];
            }
            foreach ($tables['Documents'] as [$id, $document]) {
                $id = $this->questionId($id);
                if (! array_key_exists($id, $examples)) {
                    $this->invalid("معرف سؤال غير موجود في Documents: $id");
                }
                $examples[$id]['document_ids'][] = $this->integer($document, 1);
            }
            foreach ($tables['RelevantChunks'] as [$id, $document, $chunk]) {
                $id = $this->questionId($id);
                if (! array_key_exists($id, $examples)) {
                    $this->invalid("معرف سؤال غير موجود في RelevantChunks: $id");
                }
                $examples[$id]['relevant_chunks'][] = ['document_id' => $this->integer($document, 1), 'chunk_index' => $this->integer($chunk, 0)];
            }

            return ['schema_version' => 1, 'dataset_version' => $tables['Dataset'][0][0], 'examples' => array_values($examples)];
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->invalid('تعذّرت قراءة Excel. استخدم ملف xlsx غير مشفّر مطابقاً للقالب.');
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    public function writeTemplate(string $path): void
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $tables = [
            'Dataset' => [['dataset_version'], ['golden-v1']],
            'Questions' => [['question_id', 'question', 'expected_answer'], ['q1', 'استبدل هذا النص بسؤال من وثائقك', 'الإجابة المرجعية (اختيارية)']],
            'Documents' => [['question_id', 'document_id'], ['q1', 1]],
            'RelevantChunks' => [['question_id', 'document_id', 'chunk_index'], ['q1', 1, 0]],
            'Instructions' => [['الورقة', 'طريقة التعبئة'], ['Dataset', 'إصدار واحد للملف.'], ['Questions', 'سطر لكل سؤال؛ المعرف فريد. حتى ١٠٠ سؤال.'], ['Documents', 'سطر لكل مستند ضمن نطاق بحث السؤال، بما فيها المستندات المشتتة. استبدل الرقم ١ برقم مستندك الفعلي.'], ['RelevantChunks', 'سطر لكل مقطع مرجعي يدعم الإجابة. استخدم أرقام Documents وChunks في اللوحة؛ يبدأ chunk_index من صفر.'], ['الحدود', 'ملف xlsx حتى ١ MiB، و١٠٠٠٠ سطر مقاطع مرجعية إجمالاً. قيَم ثابتة دون صيغ.'], ['المراجعة', 'قالب للتعبئة؛ لا تشغله على الأرقام التوضيحية قبل استبدالها.']],
        ];
        foreach ($tables as $name => $rows) {
            $sheet = $book->createSheet()->setTitle($name);
            $sheet->setRightToLeft(true)->fromArray($rows, null, 'A1', true);
            $sheet->freezePane('A2');
            $last = $sheet->getHighestDataColumn();
            $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
            foreach (range('A', $last) as $column) {
                $sheet->getColumnDimension($column)->setWidth($column === 'A' ? 22 : 55);
            }
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true);
        }
        $book->setActiveSheetIndex(1);
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
                if ($size > 20 * 1024 * 1024 || preg_match('~(^/|(^|/)\.\.(/|$)|vbaProject|externalLinks)~i', $entry['name'])) {
                    $this->invalid('ملف Excel يتجاوز الحجم الآمن أو يحتوي مكونات غير مدعومة.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function questionId(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '' || strlen((string) $value) > 100) {
            $this->invalid('question_id يجب أن يكون نصاً أو رقماً صحيحاً غير فارغ.');
        }

        return trim((string) $value);
    }

    private function integer(mixed $value, int $minimum): int
    {
        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value) || ! is_finite((float) $value) || floor((float) $value) != $value || $value < $minimum || $value > PHP_INT_MAX) {
            $this->invalid('document_id وchunk_index يجب أن يكونا أرقاماً صحيحة ضمن المجال المسموح.');
        }

        return (int) $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['data.dataset' => $message]);
    }
}
