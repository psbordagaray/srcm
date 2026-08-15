<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;
use DateTimeZone;
use DomainException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

final class FinancialStatementXlsxReader
{
    private const MAX_ENTRIES = 200;
    private const MAX_XML_BYTES = 8388608;

    /**
     * @return list<list<string>>
     */
    public function rows(
        string $path,
        FinancialStatementCsvMapping $mapping
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException(
                'La extensión PHP zip es obligatoria para leer XLSX.'
            );
        }

        if (! function_exists('simplexml_load_string')) {
            throw new DomainException(
                'La extensión PHP SimpleXML es obligatoria para leer XLSX.'
            );
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new DomainException(
                'El archivo XLSX no pudo abrirse como paquete Office.'
            );
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new DomainException(
                    'El XLSX contiene demasiadas entradas internas.'
                );
            }

            $workbook = $this->xmlEntry(
                $zip,
                'xl/workbook.xml'
            );

            $relationships = $this->xmlEntry(
                $zip,
                'xl/_rels/workbook.xml.rels'
            );

            $sheetPath = $this->firstSheetPath(
                $workbook,
                $relationships
            );

            $sharedStrings =
                $this->sharedStrings($zip);

            $dateStyles =
                $this->dateStyles($zip);

            $date1904 =
                $this->uses1904Dates($workbook);

            $sheet = $this->xmlEntry(
                $zip,
                $sheetPath
            );

            return $this->sheetRows(
                $sheet,
                $sharedStrings,
                $dateStyles,
                $date1904,
                $mapping
            );
        } finally {
            $zip->close();
        }
    }

    private function xmlEntry(
        ZipArchive $zip,
        string $name
    ): SimpleXMLElement {
        $stat = $zip->statName($name);

        if (! is_array($stat)) {
            throw new DomainException(
                'El XLSX no contiene la estructura requerida: '
                .$name.'.'
            );
        }

        $size = (int) ($stat['size'] ?? 0);

        if (
            $size <= 0
            || $size > self::MAX_XML_BYTES
        ) {
            throw new DomainException(
                'Una parte interna del XLSX excede el tamaño permitido.'
            );
        }

        $contents = $zip->getFromName($name);

        if (! is_string($contents)) {
            throw new DomainException(
                'No se pudo leer una parte interna del XLSX.'
            );
        }

        $xml = simplexml_load_string(
            $contents,
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_NOCDATA
        );

        if (! $xml instanceof SimpleXMLElement) {
            throw new DomainException(
                'El XLSX contiene XML inválido.'
            );
        }

        return $xml;
    }

    private function firstSheetPath(
        SimpleXMLElement $workbook,
        SimpleXMLElement $relationships
    ): string {
        $mainNamespace =
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $relationshipNamespace =
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $packageRelationshipNamespace =
            'http://schemas.openxmlformats.org/package/2006/relationships';

        $workbookMain =
            $workbook->children($mainNamespace);

        if (! isset($workbookMain->sheets)) {
            throw new DomainException(
                'El XLSX no contiene hojas.'
            );
        }

        $sheets = $workbookMain->sheets;
        $sheetNodes = $sheets->children(
            $mainNamespace
        );

        if (! isset($sheetNodes->sheet[0])) {
            throw new DomainException(
                'El XLSX no contiene una primera hoja legible.'
            );
        }

        $firstSheet = $sheetNodes->sheet[0];

        $relationshipId = (string) $firstSheet
            ->attributes($relationshipNamespace)
            ->id;

        if ($relationshipId === '') {
            throw new DomainException(
                'La primera hoja XLSX no posee relación interna.'
            );
        }

        $target = null;

        foreach (
            $relationships
                ->children($packageRelationshipNamespace)
                ->Relationship
            as $relationship
        ) {
            $attributes = $relationship->attributes();

            if (
                (string) $attributes->Id
                === $relationshipId
            ) {
                $target = (string) $attributes->Target;

                break;
            }
        }

        if (
            ! is_string($target)
            || $target === ''
            || str_contains($target, '://')
            || str_contains($target, '..')
        ) {
            throw new DomainException(
                'La relación de la primera hoja XLSX no es segura.'
            );
        }

        $target = ltrim(
            str_replace('\\', '/', $target),
            '/'
        );

        if (str_starts_with($target, 'xl/')) {
            return $target;
        }

        return 'xl/'.$target;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(
        ZipArchive $zip
    ): array {
        if (
            $zip->locateName(
                'xl/sharedStrings.xml'
            ) === false
        ) {
            return [];
        }

        $xml = $this->xmlEntry(
            $zip,
            'xl/sharedStrings.xml'
        );

        $mainNamespace =
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $result = [];

        foreach (
            $xml
                ->children($mainNamespace)
                ->si
            as $item
        ) {
            $result[] =
                $this->sharedStringText(
                    $item,
                    $mainNamespace
                );
        }

        return $result;
    }

    private function sharedStringText(
        SimpleXMLElement $item,
        string $mainNamespace
    ): string {
        $main = $item->children(
            $mainNamespace
        );

        if (isset($main->t)) {
            return (string) $main->t;
        }

        $parts = [];

        foreach ($main->r as $run) {
            $parts[] = (string) $run
                ->children($mainNamespace)
                ->t;
        }

        return implode('', $parts);
    }

    /**
     * @return array<int, bool>
     */
    private function dateStyles(
        ZipArchive $zip
    ): array {
        if (
            $zip->locateName('xl/styles.xml')
                === false
        ) {
            return [];
        }

        $xml = $this->xmlEntry(
            $zip,
            'xl/styles.xml'
        );

        $mainNamespace =
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $main = $xml->children(
            $mainNamespace
        );

        $customFormats = [];

        foreach (
            $main->numFmts
                ->children($mainNamespace)
                ->numFmt
            as $format
        ) {
            $attributes = $format->attributes();
            $id = (int) $attributes->numFmtId;
            $code = (string) $attributes->formatCode;

            $customFormats[$id] =
                $this->looksLikeDateFormat(
                    $code
                );
        }

        $styles = [];
        $index = 0;

        foreach (
            $main->cellXfs
                ->children($mainNamespace)
                ->xf
            as $xf
        ) {
            $attributes = $xf->attributes();
            $formatId =
                (int) $attributes->numFmtId;

            $styles[$index] =
                $this->builtInDateFormat(
                    $formatId
                )
                || ($customFormats[$formatId]
                    ?? false);

            $index++;
        }

        return $styles;
    }

    private function builtInDateFormat(
        int $formatId
    ): bool {
        return in_array(
            $formatId,
            [
                14, 15, 16, 17,
                18, 19, 20, 21, 22,
                27, 28, 29, 30, 31,
                32, 33, 34, 35, 36,
                45, 46, 47, 50, 51,
                52, 53, 54, 55, 56,
                57, 58,
            ],
            true
        );
    }

    private function looksLikeDateFormat(
        string $code
    ): bool {
        $normalized = strtolower($code);
        $normalized = preg_replace(
            '/"[^"]*"|\\\\.|\\[[^\\]]*\\]/',
            '',
            $normalized
        ) ?? $normalized;

        return preg_match(
            '/[ydhs]/',
            $normalized
        ) === 1;
    }

    private function uses1904Dates(
        SimpleXMLElement $workbook
    ): bool {
        $mainNamespace =
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $main = $workbook->children(
            $mainNamespace
        );

        if (! isset($main->workbookPr)) {
            return false;
        }

        $properties = $main->workbookPr;

        $value = strtolower(
            (string) $properties
                ->attributes()
                ->date1904
        );

        return in_array(
            $value,
            ['1', 'true'],
            true
        );
    }

    /**
     * @param list<string> $sharedStrings
     * @param array<int, bool> $dateStyles
     * @return list<list<string>>
     */
    private function sheetRows(
        SimpleXMLElement $sheet,
        array $sharedStrings,
        array $dateStyles,
        bool $date1904,
        FinancialStatementCsvMapping $mapping
    ): array {
        $mainNamespace =
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $sheetMain = $sheet->children(
            $mainNamespace
        );

        if (! isset($sheetMain->sheetData)) {
            throw new DomainException(
                'La primera hoja XLSX no contiene filas.'
            );
        }

        $sheetData = $sheetMain->sheetData;

        $rows = [];

        foreach (
            $sheetData
                ->children($mainNamespace)
                ->row
            as $row
        ) {
            $rowNumber = (int) (
                $row->attributes()->r
                ?: count($rows) + 1
            );

            if ($rowNumber <= 0) {
                throw new DomainException(
                    'El XLSX contiene un número de fila inválido.'
                );
            }

            while (
                count($rows) < $rowNumber - 1
            ) {
                $rows[] = [];
            }

            $values = [];

            foreach (
                $row
                    ->children($mainNamespace)
                    ->c
                as $cell
            ) {
                $attributes = $cell->attributes();
                $reference = (string) $attributes->r;
                $columnIndex =
                    $this->columnIndex(
                        $reference
                    );

                $values[$columnIndex] =
                    $this->cellValue(
                        $cell,
                        $sharedStrings,
                        $dateStyles,
                        $date1904,
                        $mapping,
                        $mainNamespace
                    );
            }

            if ($values === []) {
                $rows[] = [];

                continue;
            }

            $max = max(array_keys($values));
            $normalized = [];

            for ($index = 0; $index <= $max; $index++) {
                $normalized[] =
                    $values[$index] ?? '';
            }

            $rows[] = $normalized;
        }

        if ($rows === []) {
            throw new DomainException(
                'La primera hoja XLSX no contiene datos.'
            );
        }

        $headerWidth = count($rows[0]);

        if ($headerWidth === 0) {
            throw new DomainException(
                'La primera fila XLSX debe contener la cabecera.'
            );
        }

        foreach ($rows as $index => $row) {
            if (
                $index > 0
                && count($row) < $headerWidth
            ) {
                $rows[$index] = array_pad(
                    $row,
                    $headerWidth,
                    ''
                );
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $sharedStrings
     * @param array<int, bool> $dateStyles
     */
    private function cellValue(
        SimpleXMLElement $cell,
        array $sharedStrings,
        array $dateStyles,
        bool $date1904,
        FinancialStatementCsvMapping $mapping,
        string $mainNamespace
    ): string {
        $main = $cell->children(
            $mainNamespace
        );

        if (isset($main->f)) {
            throw new DomainException(
                'P7.4 no acepta fórmulas XLSX; exporte valores confirmados.'
            );
        }

        $attributes = $cell->attributes();
        $type = (string) $attributes->t;
        $styleIndex =
            isset($attributes->s)
            ? (int) $attributes->s
            : null;

        if ($type === 'inlineStr') {
            return $this->inlineString(
                $main->is,
                $mainNamespace
            );
        }

        $raw = isset($main->v)
            ? (string) $main->v
            : '';

        if ($type === 's') {
            if (
                preg_match('/^\d+$/D', $raw)
                    !== 1
            ) {
                throw new DomainException(
                    'El XLSX contiene un índice shared string inválido.'
                );
            }

            $index = (int) $raw;

            if (! array_key_exists(
                $index,
                $sharedStrings
            )) {
                throw new DomainException(
                    'El XLSX referencia un shared string inexistente.'
                );
            }

            return $sharedStrings[$index];
        }

        if ($type === 'str') {
            return $raw;
        }

        if ($type === 'b') {
            return $raw === '1' ? '1' : '0';
        }

        if (
            $styleIndex !== null
            && ($dateStyles[$styleIndex] ?? false)
            && $raw !== ''
        ) {
            return $this->excelDateValue(
                $raw,
                $date1904,
                $mapping
            );
        }

        if (
            $raw !== ''
            && is_numeric($raw)
            && $mapping->decimalSeparator === ','
        ) {
            return str_replace('.', ',', $raw);
        }

        return $raw;
    }

    private function inlineString(
        ?SimpleXMLElement $inline,
        string $mainNamespace
    ): string {
        if ($inline === null) {
            return '';
        }

        $main = $inline->children(
            $mainNamespace
        );

        if (isset($main->t)) {
            return (string) $main->t;
        }

        $parts = [];

        foreach ($main->r as $run) {
            $parts[] = (string) $run
                ->children($mainNamespace)
                ->t;
        }

        return implode('', $parts);
    }

    private function excelDateValue(
        string $raw,
        bool $date1904,
        FinancialStatementCsvMapping $mapping
    ): string {
        if (! is_numeric($raw)) {
            throw new DomainException(
                'El XLSX contiene una fecha serial inválida.'
            );
        }

        $serial = (float) $raw;

        if ($serial < 0) {
            throw new DomainException(
                'El XLSX contiene una fecha serial negativa.'
            );
        }

        $days = (int) floor($serial);
        $seconds = (int) round(
            ($serial - $days) * 86400
        );

        if ($seconds >= 86400) {
            $days++;
            $seconds -= 86400;
        }

        try {
            $timezone = new DateTimeZone(
                $mapping->timezone
            );

            $base = CarbonImmutable::create(
                $date1904 ? 1904 : 1899,
                $date1904 ? 1 : 12,
                $date1904 ? 1 : 30,
                0,
                0,
                0,
                $timezone
            );

            $date = $base
                ->addDays($days)
                ->addSeconds($seconds);
        } catch (Throwable $exception) {
            throw new DomainException(
                'No se pudo interpretar una fecha XLSX.',
                previous: $exception
            );
        }

        return match ($mapping->dateFormat) {
            'iso8601' =>
                $date->toIso8601String(),
            'Y-m-d H:i:s' =>
                $date->format(
                    'Y-m-d H:i:s'
                ),
            'd/m/Y H:i:s' =>
                $date->format(
                    'd/m/Y H:i:s'
                ),
            'd/m/Y' =>
                $date->format('d/m/Y'),
            default => throw new DomainException(
                'El formato de fecha del mapeo no es compatible con XLSX.'
            ),
        };
    }

    private function columnIndex(
        string $reference
    ): int {
        if (
            preg_match(
                '/^([A-Z]+)\d+$/D',
                strtoupper($reference),
                $matches
            ) !== 1
        ) {
            throw new DomainException(
                'El XLSX contiene una referencia de celda inválida.'
            );
        }

        $letters = $matches[1];
        $index = 0;

        foreach (
            str_split($letters)
            as $letter
        ) {
            $index = ($index * 26)
                + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
