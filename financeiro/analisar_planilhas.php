<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Leitor local e somente-leitura de metadados, valores e formulas de arquivos XLSX.
 * Uso: php analisar_planilhas.php arquivo1.xlsx [arquivo2.xlsx ...]
 */

function xmlFromZip(ZipArchive $zip, string $path): ?DOMDocument
{
    $contents = $zip->getFromName($path);
    if ($contents === false) {
        return null;
    }

    $document = new DOMDocument();
    $document->preserveWhiteSpace = false;
    if (!@$document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT)) {
        return null;
    }
    return $document;
}

function xpath(DOMDocument $document): DOMXPath
{
    return new DOMXPath($document);
}

function nodeText(?DOMNode $node): ?string
{
    return $node === null ? null : $node->textContent;
}

function excelDate(float $serial): string
{
    $days = (int) floor($serial);
    $seconds = (int) round(($serial - $days) * 86400);
    $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
    return $base->modify("+{$days} days")->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
}

function isDateFormat(int $formatId, string $formatCode): bool
{
    if (in_array($formatId, [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47], true)) {
        return true;
    }
    $normalized = strtolower(preg_replace('/"[^\"]*"|\\\\.|\[[^\]]*\]/', '', $formatCode) ?? '');
    return (bool) preg_match('/(^|[^a-z])[dmyhs]+([^a-z]|$)/', $normalized);
}

function inspectWorkbook(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new RuntimeException("Nao foi possivel abrir {$file}");
    }

    $sharedStrings = [];
    if ($sharedDoc = xmlFromZip($zip, 'xl/sharedStrings.xml')) {
        $sharedXpath = xpath($sharedDoc);
        foreach ($sharedXpath->query('//*[local-name()="si"]') as $stringNode) {
            $parts = [];
            foreach ($sharedXpath->query('.//*[local-name()="t"]', $stringNode) as $textNode) {
                $parts[] = $textNode->textContent;
            }
            $sharedStrings[] = implode('', $parts);
        }
    }

    $customFormats = [];
    $cellFormats = [];
    if ($stylesDoc = xmlFromZip($zip, 'xl/styles.xml')) {
        $stylesXpath = xpath($stylesDoc);
        foreach ($stylesXpath->query('//*[local-name()="numFmts"]/*[local-name()="numFmt"]') as $formatNode) {
            if ($formatNode instanceof DOMElement) {
                $customFormats[(int) $formatNode->getAttribute('numFmtId')] = $formatNode->getAttribute('formatCode');
            }
        }
        foreach ($stylesXpath->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $formatNode) {
            if ($formatNode instanceof DOMElement) {
                $formatId = (int) $formatNode->getAttribute('numFmtId');
                $cellFormats[] = [
                    'numFmtId' => $formatId,
                    'formatCode' => $customFormats[$formatId] ?? '',
                ];
            }
        }
    }

    $relationships = [];
    if ($relsDoc = xmlFromZip($zip, 'xl/_rels/workbook.xml.rels')) {
        foreach (xpath($relsDoc)->query('//*[local-name()="Relationship"]') as $relationshipNode) {
            if ($relationshipNode instanceof DOMElement) {
                $target = str_replace('\\', '/', $relationshipNode->getAttribute('Target'));
                $relationships[$relationshipNode->getAttribute('Id')] = str_starts_with($target, '/')
                    ? ltrim($target, '/')
                    : 'xl/' . ltrim($target, '/');
            }
        }
    }

    $workbookDoc = xmlFromZip($zip, 'xl/workbook.xml');
    if (!$workbookDoc) {
        throw new RuntimeException("workbook.xml ausente em {$file}");
    }

    $result = [
        'arquivo' => basename($file),
        'tamanho_bytes' => filesize($file),
        'abas' => [],
    ];
    $workbookXpath = xpath($workbookDoc);
    foreach ($workbookXpath->query('//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheetNode) {
        if (!$sheetNode instanceof DOMElement) {
            continue;
        }
        $relationId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $sheetPath = $relationships[$relationId] ?? null;
        if (!$sheetPath || !($sheetDoc = xmlFromZip($zip, $sheetPath))) {
            continue;
        }

        $sheetXpath = xpath($sheetDoc);
        $dimensionNode = $sheetXpath->query('//*[local-name()="dimension"]')->item(0);
        $dimension = $dimensionNode instanceof DOMElement ? $dimensionNode->getAttribute('ref') : null;
        $merged = [];
        foreach ($sheetXpath->query('//*[local-name()="mergeCell"]') as $mergeNode) {
            if ($mergeNode instanceof DOMElement) {
                $merged[] = $mergeNode->getAttribute('ref');
            }
        }

        $rows = [];
        $formulaCount = 0;
        $nonEmptyCount = 0;
        foreach ($sheetXpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
            if (!$rowNode instanceof DOMElement) {
                continue;
            }
            $cells = [];
            foreach ($sheetXpath->query('./*[local-name()="c"]', $rowNode) as $cellNode) {
                if (!$cellNode instanceof DOMElement) {
                    continue;
                }
                $reference = $cellNode->getAttribute('r');
                $type = $cellNode->getAttribute('t');
                $styleIndex = $cellNode->hasAttribute('s') ? (int) $cellNode->getAttribute('s') : 0;
                $formula = nodeText($sheetXpath->query('./*[local-name()="f"]', $cellNode)->item(0));
                $rawValue = nodeText($sheetXpath->query('./*[local-name()="v"]', $cellNode)->item(0));
                $value = $rawValue;

                if ($type === 's' && $rawValue !== null) {
                    $value = $sharedStrings[(int) $rawValue] ?? $rawValue;
                } elseif ($type === 'inlineStr') {
                    $textParts = [];
                    foreach ($sheetXpath->query('.//*[local-name()="t"]', $cellNode) as $textNode) {
                        $textParts[] = $textNode->textContent;
                    }
                    $value = implode('', $textParts);
                } elseif ($type === 'b' && $rawValue !== null) {
                    $value = $rawValue === '1';
                } elseif ($rawValue !== null && is_numeric($rawValue)) {
                    $style = $cellFormats[$styleIndex] ?? ['numFmtId' => 0, 'formatCode' => ''];
                    if (isDateFormat((int) $style['numFmtId'], (string) $style['formatCode'])) {
                        $value = excelDate((float) $rawValue);
                    } else {
                        $value = (float) $rawValue;
                    }
                }

                if ($value !== null || $formula !== null) {
                    $nonEmptyCount++;
                    if ($formula !== null) {
                        $formulaCount++;
                    }
                    $cell = ['celula' => $reference, 'valor' => $value];
                    if ($formula !== null) {
                        $cell['formula'] = $formula;
                    }
                    $cells[] = $cell;
                }
            }
            if ($cells !== []) {
                $rows[] = ['linha' => (int) $rowNode->getAttribute('r'), 'celulas' => $cells];
            }
        }

        $result['abas'][] = [
            'nome' => $sheetNode->getAttribute('name'),
            'estado' => $sheetNode->getAttribute('state') ?: 'visible',
            'dimensao' => $dimension,
            'celulas_preenchidas' => $nonEmptyCount,
            'formulas' => $formulaCount,
            'mesclagens' => $merged,
            'linhas' => $rows,
        ];
    }

    $zip->close();
    return $result;
}

if ($argc < 2) {
    fwrite(STDERR, "Informe ao menos um arquivo XLSX.\n");
    exit(1);
}

$results = [];
foreach (array_slice($argv, 1) as $file) {
    $results[] = inspectWorkbook($file);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
