<?php

namespace App\Domain\Import;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class ProductImportFileReader
{
    private const MAX_DATA_ROWS = 500;

    private const MAX_COLUMNS = 24;

    private const MAX_ZIP_ENTRIES = 2048;

    private const MAX_ENTRY_BYTES = 12 * 1024 * 1024;

    /**
     * @return array{
     *   file_name: string,
     *   sha256: string,
     *   rows: array<int, array<int, string>>
     * }
     */
    public function read(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new DomainException(
                'El archivo no pudo recibirse correctamente.'
            );
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new DomainException(
                'No se pudo acceder al archivo temporal de importación.'
            );
        }

        $extension = Str::lower(
            $file->getClientOriginalExtension()
        );

        $rows = match ($extension) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new DomainException(
                'Formato no admitido. Usá CSV o Excel .xlsx.'
            ),
        };

        if (count($rows) < 2) {
            throw new DomainException(
                'La planilla debe contener encabezados y al menos una fila de datos.'
            );
        }

        if (count($rows) - 1 > self::MAX_DATA_ROWS) {
            throw new DomainException(
                'El bloque admite hasta '.self::MAX_DATA_ROWS
                .' filas de datos por archivo.'
            );
        }

        return [
            'file_name' => $file->getClientOriginalName(),
            'sha256' => strtoupper(hash_file('sha256', $path)),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException(
                'No se pudo abrir el archivo CSV.'
            );
        }

        try {
            $firstLine = fgets($handle);

            if ($firstLine === false) {
                throw new DomainException(
                    'El archivo CSV está vacío.'
                );
            }

            $firstUtf8 = $this->toUtf8($firstLine);
            $delimiter = $this->detectDelimiter($firstUtf8);

            rewind($handle);

            $rows = [];

            while (
                ($fields = fgetcsv(
                    $handle,
                    0,
                    $delimiter,
                    '"',
                    ''
                )) !== false
            ) {
                $normalized = array_map(
                    fn ($value): string => $this->cleanCell(
                        $this->toUtf8((string) $value)
                    ),
                    $fields
                );

                $rows[] = array_slice(
                    $normalized,
                    0,
                    self::MAX_COLUMNS
                );

                if (count($rows) > self::MAX_DATA_ROWS + 1) {
                    break;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $bestDelimiter = ';';
        $bestCount = 0;

        foreach ([';', ',', "\t"] as $delimiter) {
            $count = count(
                str_getcsv($line, $delimiter, '"', '')
            );

            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        if ($bestCount < 2) {
            throw new DomainException(
                'No se pudo detectar un separador CSV válido.'
            );
        }

        return $bestDelimiter;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readXlsx(string $path): array
    {
        $archive = $this->openZip($path);

        $workbookXml = $this->zipEntry(
            $archive,
            'xl/workbook.xml'
        );
        $relationshipsXml = $this->zipEntry(
            $archive,
            'xl/_rels/workbook.xml.rels'
        );

        if (
            $workbookXml === null
            || $relationshipsXml === null
        ) {
            throw new DomainException(
                'El archivo .xlsx no contiene la estructura de libro esperada.'
            );
        }

        $sheetPath = $this->firstWorksheetPath(
            $workbookXml,
            $relationshipsXml
        );
        $sheetXml = $this->zipEntry(
            $archive,
            $sheetPath
        );

        if ($sheetXml === null) {
            throw new DomainException(
                'No se encontró la primera hoja del archivo Excel.'
            );
        }

        $sharedStringsXml = $this->zipEntry(
            $archive,
            'xl/sharedStrings.xml'
        );
        $sharedStrings = $sharedStringsXml !== null
            ? $this->sharedStrings($sharedStringsXml)
            : [];

        return $this->worksheetRows(
            $sheetXml,
            $sharedStrings
        );
    }

    /**
     * Abre un ZIP OOXML sin depender de ext-zip.
     *
     * @return array{
     *   bytes: string,
     *   entries: array<string, array{
     *     flags: int,
     *     method: int,
     *     crc: int,
     *     compressed_size: int,
     *     uncompressed_size: int,
     *     local_offset: int
     *   }>
     * }
     */
    private function openZip(string $path): array
    {
        $bytes = file_get_contents($path);

        if ($bytes === false || strlen($bytes) < 22) {
            throw new DomainException(
                'El archivo .xlsx no es un contenedor ZIP válido.'
            );
        }

        $eocdOffset = strrpos(
            $bytes,
            "\x50\x4b\x05\x06"
        );

        if (
            $eocdOffset === false
            || $eocdOffset + 22 > strlen($bytes)
        ) {
            throw new DomainException(
                'El archivo .xlsx no posee un directorio ZIP válido.'
            );
        }

        $eocd = unpack(
            'vdisk/vcentral_disk/vdisk_entries/vtotal_entries/'
            .'Vcentral_size/Vcentral_offset/vcomment_length',
            substr($bytes, $eocdOffset + 4, 18)
        );

        if (! is_array($eocd)) {
            throw new DomainException(
                'No se pudo leer el directorio ZIP del archivo Excel.'
            );
        }

        if (
            (int) $eocd['disk'] !== 0
            || (int) $eocd['central_disk'] !== 0
            || (int) $eocd['disk_entries']
                !== (int) $eocd['total_entries']
        ) {
            throw new DomainException(
                'Los archivos Excel ZIP multidisco no están admitidos.'
            );
        }

        $entryCount = (int) $eocd['total_entries'];

        if (
            $entryCount < 1
            || $entryCount > self::MAX_ZIP_ENTRIES
        ) {
            throw new DomainException(
                'El archivo Excel contiene una cantidad de entradas ZIP no admitida.'
            );
        }

        $centralOffset = (int) $eocd['central_offset'];
        $centralSize = (int) $eocd['central_size'];
        $length = strlen($bytes);

        if (
            $centralOffset < 0
            || $centralSize < 0
            || $centralOffset + $centralSize > $length
        ) {
            throw new DomainException(
                'El directorio central del Excel está fuera de rango.'
            );
        }

        $offset = $centralOffset;
        $entries = [];

        for ($index = 0; $index < $entryCount; $index++) {
            if (
                $offset + 46 > $length
                || substr($bytes, $offset, 4)
                    !== "\x50\x4b\x01\x02"
            ) {
                throw new DomainException(
                    'El directorio ZIP del Excel está dañado.'
                );
            }

            $header = unpack(
                'vversion_made/vversion_needed/vflags/vmethod/'
                .'vtime/vdate/Vcrc/Vcompressed_size/'
                .'Vuncompressed_size/vname_length/vextra_length/'
                .'vcomment_length/vdisk_start/vinternal/'
                .'Vexternal/Vlocal_offset',
                substr($bytes, $offset + 4, 42)
            );

            if (! is_array($header)) {
                throw new DomainException(
                    'No se pudo leer una entrada ZIP del Excel.'
                );
            }

            $nameLength = (int) $header['name_length'];
            $extraLength = (int) $header['extra_length'];
            $commentLength = (int) $header['comment_length'];
            $recordLength =
                46
                + $nameLength
                + $extraLength
                + $commentLength;

            if (
                $recordLength < 46
                || $offset + $recordLength > $length
            ) {
                throw new DomainException(
                    'Una entrada ZIP del Excel está truncada.'
                );
            }

            $name = substr(
                $bytes,
                $offset + 46,
                $nameLength
            );
            $normalized = $this->normalizeZipPath($name);

            if ($normalized !== '') {
                $entries[$normalized] = [
                    'flags' => (int) $header['flags'],
                    'method' => (int) $header['method'],
                    'crc' => (int) $header['crc'],
                    'compressed_size' =>
                        (int) $header['compressed_size'],
                    'uncompressed_size' =>
                        (int) $header['uncompressed_size'],
                    'local_offset' =>
                        (int) $header['local_offset'],
                ];
            }

            $offset += $recordLength;
        }

        return [
            'bytes' => $bytes,
            'entries' => $entries,
        ];
    }

    /**
     * @param array{
     *   bytes: string,
     *   entries: array<string, array{
     *     flags: int,
     *     method: int,
     *     crc: int,
     *     compressed_size: int,
     *     uncompressed_size: int,
     *     local_offset: int
     *   }>
     * } $archive
     */
    private function zipEntry(
        array $archive,
        string $path
    ): ?string {
        $normalized = $this->normalizeZipPath($path);
        $entry = $archive['entries'][$normalized] ?? null;

        if ($entry === null) {
            return null;
        }

        if (($entry['flags'] & 0x0001) !== 0) {
            throw new DomainException(
                'Los archivos Excel cifrados no están admitidos.'
            );
        }

        if (
            $entry['uncompressed_size'] < 0
            || $entry['uncompressed_size']
                > self::MAX_ENTRY_BYTES
        ) {
            throw new DomainException(
                'Una entrada interna del Excel supera el límite permitido.'
            );
        }

        $bytes = $archive['bytes'];
        $offset = $entry['local_offset'];
        $length = strlen($bytes);

        if (
            $offset < 0
            || $offset + 30 > $length
            || substr($bytes, $offset, 4)
                !== "\x50\x4b\x03\x04"
        ) {
            throw new DomainException(
                'Una entrada local del Excel está dañada.'
            );
        }

        $local = unpack(
            'vversion/vflags/vmethod/vtime/vdate/'
            .'Vcrc/Vcompressed_size/Vuncompressed_size/'
            .'vname_length/vextra_length',
            substr($bytes, $offset + 4, 26)
        );

        if (! is_array($local)) {
            throw new DomainException(
                'No se pudo leer una entrada local del Excel.'
            );
        }

        $dataOffset =
            $offset
            + 30
            + (int) $local['name_length']
            + (int) $local['extra_length'];
        $compressedSize = $entry['compressed_size'];

        if (
            $compressedSize < 0
            || $dataOffset < 0
            || $dataOffset + $compressedSize > $length
        ) {
            throw new DomainException(
                'Los datos comprimidos del Excel están truncados.'
            );
        }

        $compressed = substr(
            $bytes,
            $dataOffset,
            $compressedSize
        );

        $data = match ($entry['method']) {
            0 => $compressed,
            8 => $this->inflate($compressed),
            default => throw new DomainException(
                'El Excel usa un método ZIP no admitido: '
                .$entry['method'].'.'
            ),
        };

        if (
            strlen($data)
            !== $entry['uncompressed_size']
        ) {
            throw new DomainException(
                'El tamaño interno de una entrada Excel no coincide.'
            );
        }

        if (
            pack('V', crc32($data))
            !== pack('V', $entry['crc'])
        ) {
            throw new DomainException(
                'La verificación CRC del archivo Excel falló.'
            );
        }

        return $data;
    }

    private function inflate(string $compressed): string
    {
        if (! function_exists('gzinflate')) {
            throw new DomainException(
                'PHP debe disponer de zlib para leer archivos Excel comprimidos.'
            );
        }

        $inflated = @gzinflate($compressed);

        if ($inflated === false) {
            throw new DomainException(
                'No se pudo descomprimir una entrada del archivo Excel.'
            );
        }

        if (strlen($inflated) > self::MAX_ENTRY_BYTES) {
            throw new DomainException(
                'Una entrada descomprimida del Excel supera el límite permitido.'
            );
        }

        return $inflated;
    }

    private function firstWorksheetPath(
        string $workbookXml,
        string $relationshipsXml
    ): string {
        if (
            preg_match(
                '/<sheet\b([^>]*)\/?>/i',
                $workbookXml,
                $sheetMatch
            ) !== 1
        ) {
            throw new DomainException(
                'El archivo Excel no contiene hojas.'
            );
        }

        $relationshipId = $this->xmlAttribute(
            $sheetMatch[1],
            'r:id'
        );

        if ($relationshipId === null) {
            throw new DomainException(
                'No se pudo resolver la primera hoja del Excel.'
            );
        }

        if (
            preg_match_all(
                '/<Relationship\b([^>]*)\/?>/i',
                $relationshipsXml,
                $relationshipMatches,
                PREG_SET_ORDER
            ) === false
        ) {
            throw new DomainException(
                'No se pudieron leer las relaciones del libro Excel.'
            );
        }

        foreach ($relationshipMatches as $match) {
            $id = $this->xmlAttribute(
                $match[1],
                'Id'
            );

            if ($id !== $relationshipId) {
                continue;
            }

            $target = $this->xmlAttribute(
                $match[1],
                'Target'
            );

            if ($target === null || $target === '') {
                break;
            }

            if (str_starts_with($target, '/')) {
                return $this->normalizeZipPath(
                    ltrim($target, '/')
                );
            }

            return $this->normalizeZipPath(
                'xl/'.$target
            );
        }

        throw new DomainException(
            'La primera hoja del Excel no posee una relación válida.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(
        string $sharedStringsXml
    ): array {
        $count = preg_match_all(
            '/<si\b[^>]*>(.*?)<\/si>/si',
            $sharedStringsXml,
            $matches
        );

        if ($count === false || $count === 0) {
            return [];
        }

        return array_map(
            fn (string $item): string =>
                $this->xmlTextNodes($item),
            $matches[1]
        );
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function worksheetRows(
        string $sheetXml,
        array $sharedStrings
    ): array {
        $count = preg_match_all(
            '/<row\b[^>]*>(.*?)<\/row>/si',
            $sheetXml,
            $rowMatches
        );

        if ($count === false || $count === 0) {
            return [];
        }

        $rows = [];

        foreach ($rowMatches[1] as $rowXml) {
            $cells = preg_match_all(
                '/<c\b([^>]*)>(.*?)<\/c>|<c\b([^>]*)\/>/si',
                $rowXml,
                $cellMatches,
                PREG_SET_ORDER
            );

            if ($cells === false) {
                throw new DomainException(
                    'No se pudieron interpretar las celdas del Excel.'
                );
            }

            $values = [];
            $sequentialIndex = 0;

            foreach ($cellMatches as $match) {
                $attributes = $match[1] !== ''
                    ? $match[1]
                    : ($match[3] ?? '');
                $body = $match[1] !== ''
                    ? ($match[2] ?? '')
                    : '';
                $reference = $this->xmlAttribute(
                    $attributes,
                    'r'
                );
                $index = $reference !== null
                    ? $this->columnIndex($reference)
                    : $sequentialIndex;

                if (
                    $index < 0
                    || $index >= self::MAX_COLUMNS
                ) {
                    $sequentialIndex++;

                    continue;
                }

                $type = $this->xmlAttribute(
                    $attributes,
                    't'
                ) ?? '';
                $values[$index] = $this->xlsxCellValue(
                    $type,
                    $body,
                    $sharedStrings
                );
                $sequentialIndex = $index + 1;
            }

            if ($values === []) {
                $rows[] = [];
            } else {
                $lastIndex = max(array_keys($values));
                $row = [];

                for (
                    $index = 0;
                    $index <= $lastIndex;
                    $index++
                ) {
                    $row[] = $this->cleanCell(
                        (string) ($values[$index] ?? '')
                    );
                }

                $rows[] = $row;
            }

            if (count($rows) > self::MAX_DATA_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function xlsxCellValue(
        string $type,
        string $body,
        array $sharedStrings
    ): string {
        if ($type === 'inlineStr') {
            return $this->xmlTextNodes($body);
        }

        $value = '';

        if (
            preg_match(
                '/<v\b[^>]*>(.*?)<\/v>/si',
                $body,
                $valueMatch
            ) === 1
        ) {
            $value = $this->decodeXmlText(
                $valueMatch[1]
            );
        }

        if ($type === 's') {
            $index = (int) $value;

            return $sharedStrings[$index] ?? '';
        }

        if ($type === 'b') {
            return $value === '1' ? '1' : '0';
        }

        if ($type === 'str') {
            return $this->decodeXmlText($value);
        }

        return $value;
    }

    private function xmlTextNodes(string $xml): string
    {
        $count = preg_match_all(
            '/<t\b[^>]*>(.*?)<\/t>/si',
            $xml,
            $matches
        );

        if ($count === false || $count === 0) {
            return '';
        }

        return implode(
            '',
            array_map(
                fn (string $text): string =>
                    $this->decodeXmlText($text),
                $matches[1]
            )
        );
    }

    private function xmlAttribute(
        string $attributes,
        string $name
    ): ?string {
        $quotedName = preg_quote($name, '/');

        if (
            preg_match(
                '/(?:^|\s)'
                .$quotedName
                .'\s*=\s*(["\'])(.*?)\1/si',
                $attributes,
                $match
            ) !== 1
        ) {
            return null;
        }

        return $this->decodeXmlText($match[2]);
    }

    private function decodeXmlText(string $value): string
    {
        if (
            str_starts_with($value, '<![CDATA[')
            && str_ends_with($value, ']]>')
        ) {
            return substr($value, 9, -3);
        }

        return html_entity_decode(
            $value,
            ENT_QUOTES | ENT_XML1,
            'UTF-8'
        );
    }

    private function columnIndex(string $reference): int
    {
        if (
            ! preg_match(
                '/^([A-Z]+)\d+$/i',
                $reference,
                $matches
            )
        ) {
            return -1;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26)
                + (ord($letter) - ord('A') + 1);
        }

        return $index - 1;
    }

    private function normalizeZipPath(
        string $path
    ): string {
        $parts = [];

        foreach (
            explode(
                '/',
                str_replace('\\', '/', $path)
            )
            as $part
        ) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts === []) {
                    return '';
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function toUtf8(string $value): string
    {
        $value = str_replace("\0", '', $value);

        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            $value = substr($value, 3);
        }

        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding(
                $value,
                'UTF-8',
                ['Windows-1252', 'ISO-8859-1']
            );
        }

        if (function_exists('iconv')) {
            $converted = @iconv(
                'Windows-1252',
                'UTF-8//IGNORE',
                $value
            );

            if ($converted !== false) {
                return $converted;
            }
        }

        return $value;
    }

    private function cleanCell(string $value): string
    {
        return Str::of($value)
            ->replace(["\r\n", "\r"], "\n")
            ->trim()
            ->toString();
    }
}
