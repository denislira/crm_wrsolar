<?php
// Importador melhorado: upload -> mapeamento de colunas -> import
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/config.php';

$userId = (int)$_SESSION['user_id'];

function remove_bom($s){
    // Remove UTF-8 BOM in a binary-safe way
    $s = str_replace("\xEF\xBB\xBF", '', $s);
    // Also try Unicode codepoint removal as fallback (only on valid UTF-8)
    $r = @preg_replace('/\x{FEFF}/u','', $s);
    if ($r === null) return $s; // preg_replace error on invalid UTF-8
    return $r;
}

// Debug log
$debugLog = __DIR__ . '/import_debug.log';
function debug_log($msg) {
    global $debugLog;
    file_put_contents($debugLog, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// Function to parse date from CSV (DD/MM/YYYY or DD/MM/YYYY HH:MM) to MySQL DATETIME
function parseDate($dateStr) {
    if (empty($dateStr)) return null;
    $dateStr = trim($dateStr);
    // Normalize multiple slashes or whitespace (e.g. '15/01//2026' -> '15/01/2026')
    $dateStr = preg_replace('#/+#', '/', $dateStr);
    $dateStr = preg_replace('/\s+/', ' ', $dateStr);
    // Try DD/MM/YYYY HH:MM
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})[ T](\d{1,2}):(\d{1,2})$/', $dateStr, $m)) {
        return sprintf('%04d-%02d-%02d %02d:%02d:00', $m[3], $m[2], $m[1], $m[4], $m[5]);
    }
    // Try DD/MM/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $m)) {
        return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[2], $m[1]);
    }
    // Try common ISO-like formats via strtotime as a fallback
    $ts = strtotime($dateStr);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }
    // Unparsable -> return null so DB defaults or NOW() won't fail
    return null;
}

// Ensure uploads dir
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

// Auto-mapping rules: CSV column patterns to DB columns
$autoMap = [
    '/data/i' => 'created_at',
    '/nome/i' => 'name',
    '/whatsapp|telefone|celular|fone/i' => 'phone',
    '/origem/i' => 'source',
    '/ultimo.*contato/i' => 'ultimo_contato',
    '/observacao|obs/i' => 'notes',
    '/kwh|consumo/i' => 'estimativa_projeto_kwh',
    '/valor.*vista|orcamento/i' => 'orcamento_value',
    '/envio.*proposta/i' => 'envio_proposta',
    '/vendedor/i' => 'vendedor',
    '/cidade/i' => 'cidade',
    '/status/i' => 'status',
    '/email/i' => 'email',
    '/cpf|cnpj/i' => 'cpf_cnpj'
];

$error = '';
$result = null;

// Function to remove accents from UTF-8 string
function remove_accents($string) {
    // Use Intl Normalizer when available to strip combining marks cleanly
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($string, Normalizer::FORM_D);
        if ($normalized !== false) {
            // remove combining diacritical marks
            $str = preg_replace('/\p{M}/u', '', $normalized);
            if ($str !== null) return $str;
        }
    }
    // Fallback: simple strtr map for common Latin accents
    $map = array(
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ð'=>'D','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','Þ'=>'Th','ß'=>'ss','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
        'æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ð'=>'d','ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'th','ÿ'=>'y'
    );
    return strtr($string, $map);
}

// Shorten long sample values for UI preview, preserving multibyte chars
function truncate_sample($s, $len = 80) {
    if ($s === null) return '';
    $s = trim((string)$s);
    if ($len <= 0) return '';
    if (mb_strlen($s) <= $len) return $s;
    return mb_substr($s, 0, $len - 3) . '...';
}

// Sanitize status/value strings from CSV to remove stray '?' and replacement chars
function sanitize_status_value($s) {
    if ($s === null) return '';
    $s = trim((string)$s);
    // remove BOM sequences and replacement bytes
    $s = str_replace("\xEF\xBF\xBD", '', $s);
    // remove the visible replacement character if present
    $s = str_replace('�', '', $s);
    // remove literal question marks that often appear after bad encoding
    $s = str_replace('?', '', $s);
    // remove control characters and collapse whitespace
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

// Ensure a string is valid UTF-8; attempt common single-byte encodings and fallbacks
function ensure_utf8($s) {
    if ($s === null) return null;
    $s = (string)$s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    // Try to detect the source encoding and convert cleanly to UTF-8
    $detected = @mb_detect_encoding($s, ['UTF-8','Windows-1252','ISO-8859-1','CP1252','ASCII'], true);
    if ($detected && $detected !== 'UTF-8') {
        $try = @mb_convert_encoding($s, 'UTF-8', $detected);
        if ($try !== false && mb_check_encoding($try, 'UTF-8')) {
            debug_log("ensure_utf8: converted from detected encoding {$detected}");
            return $try;
        }
    }
    // Fallback attempts: try common single-byte encodings without transliteration
    foreach (['Windows-1252','ISO-8859-1'] as $enc) {
        $try = @mb_convert_encoding($s, 'UTF-8', $enc);
        if ($try !== false && mb_check_encoding($try, 'UTF-8')) {
            debug_log("ensure_utf8: converted from {$enc} (fallback)");
            return $try;
        }
    }
    // Strip only invalid byte sequences (preserve valid multibyte chars like accents)
    $clean = @preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]+/u', '', $s);
    if ($clean !== null && mb_check_encoding($clean, 'UTF-8')) {
        debug_log('ensure_utf8: stripped invalid bytes');
        return $clean;
    }
    // Conservative final fallback: try forcing via iconv ignore to drop invalid bytes but keep accents
    $forced = @iconv('CP1252', 'UTF-8//IGNORE', $s);
    if ($forced !== false && mb_check_encoding($forced, 'UTF-8')) return $forced;
    // If everything fails, return original string after removing the replacement char
    $fallback = str_replace("\xEF\xBF\xBD", '', $s);
    return $fallback;
}

// Function to auto-map CSV column to DB column
function auto_map_column($csvCol, $autoMap, $dbCols) {
    $csvCol = trim($csvCol);
    $normalizedCsv = strtolower(remove_accents($csvCol));
    debug_log("Auto-mapping: original '$csvCol', normalized '$normalizedCsv', dbCols: " . json_encode($dbCols));
    foreach ($autoMap as $pattern => $dbCol) {
        if (preg_match($pattern, $normalizedCsv) && in_array($dbCol, $dbCols)) {
            debug_log("Matched pattern '$pattern' to '$dbCol'");
            return $dbCol;
        }
    }
    // Fallback: exact match ignoring case and accents
    foreach ($dbCols as $dbCol) {
        $normalizedDb = strtolower(remove_accents($dbCol));
        if ($normalizedCsv === $normalizedDb) {
            debug_log("Fallback matched '$normalizedCsv' to '$dbCol'");
            return $dbCol;
        }
    }
    debug_log("No match for '$csvCol'");
    return '';
}

// Helper: detect delimiter from header line
function detect_delimiter($line){
    $counts = [','=>substr_count($line, ','), ';'=>substr_count($line, ';'), "\t"=>substr_count($line, "\t")];
    arsort($counts);
    return key($counts);
}

// Step 1: upload and show mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {
    debug_log("Iniciando upload de CSV");
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Erro no upload do arquivo. Envie um arquivo CSV.';
        debug_log("Erro no upload: " . ($_FILES['csv_file']['error'] ?? 'desconhecido'));
    } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $name = $_FILES['csv_file']['name'];
        $size = $_FILES['csv_file']['size'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'])) {
            $error = 'Formato inválido. Salve o Excel como CSV e envie (.csv).';
        } elseif ($size == 0) {
            $error = 'Arquivo vazio. Selecione um arquivo com dados.';
        } else {
            $contents = file_get_contents($tmp);
            debug_log("Conteúdo lido (raw), size: " . strlen($contents) . ", first 200: " . substr($contents, 0, 200) . "...");
            // Detect encoding and convert to UTF-8 safely
            $encoding = mb_detect_encoding($contents, ['UTF-8','ISO-8859-1','Windows-1252'], true);
            debug_log("Encoding detectado: " . ($encoding ?: 'desconhecido'));
            if ($encoding && $encoding !== 'UTF-8') {
                $contents = mb_convert_encoding($contents, 'UTF-8', $encoding);
                debug_log("Conteúdo convertido para UTF-8");
            }
            // Remove BOM safely
            $contents = remove_bom($contents);
            debug_log("Conteúdo após remoção de BOM, size: " . strlen($contents) . ", first 200: " . substr($contents, 0, 200) . "...");
            if ($contents === false || trim($contents) === '') { 
                $error = 'Arquivo vazio.'; 
                debug_log("Arquivo vazio ou erro na leitura.");
            }
            else {
                $lines = preg_split('/\r\n|\n|\r/', trim($contents));
                debug_log("Linhas detectadas: " . count($lines));
                // Find first non-empty line for header
                $headerLine = '';
                $sampleLine = '';
                $foundHeader = false;
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $clean = trim(remove_bom($line));
                        if ($clean !== '') {
                            if (!$foundHeader) {
                                $headerLine = $line;
                                $foundHeader = true;
                            } else {
                                $sampleLine = $line;
                                break;
                            }
                        }
                    }
                }
                debug_log("Header line: '" . $headerLine . "'");
                debug_log("Sample line: '" . $sampleLine . "'");
                if ($headerLine === '') {
                    $header = [];
                } else {
                    $delim = detect_delimiter($headerLine);
                    debug_log("Delimitador detectado: '" . $delim . "'");
                    $cleanLine = trim(remove_bom($headerLine));

                    // Primary parse: simple split
                        $header = array_map('trim', explode($delim, $cleanLine));
                    debug_log("Cabeçalho parseado (explode): " . json_encode($header));

                    // Also parse using CSV-aware parser for robustness
                    $headerCsv = str_getcsv($cleanLine, $delim);
                    $headerCsv = array_map('trim', $headerCsv);
                    debug_log("Cabeçalho parseado (str_getcsv): " . json_encode($headerCsv));

                    // Prepare sample similarly using CSV-aware parser
                    if ($sampleLine) {
                        $sample = str_getcsv($sampleLine, $delim);
                        $sample = array_map('trim', $sample);
                        debug_log("Sample parseado (str_getcsv): " . json_encode($sample));
                    } else {
                        $sample = [];
                        debug_log("Sample vazio");
                    }

                    // If headerCsv looks better (more columns) prefer it
                    if (count($headerCsv) > count($header)) {
                        debug_log("Usando headerCsv porque tem mais colunas: " . count($headerCsv));
                        $header = $headerCsv;
                    }

                    // Save both variants for debugging/UI and the keys used by the mapping step
                    $_SESSION['import_csv_header_raw'] = $header; // chosen header
                    $_SESSION['import_csv_header_csv'] = $headerCsv; // csv parsed header
                    $_SESSION['import_csv_sample_raw'] = $sample;
                    // The mapping step expects these session keys
                    $_SESSION['import_csv_header'] = $header;
                    $_SESSION['import_csv_sample'] = $sample;
                }
                    $tmpName = uniqid('csv_') . '.csv';
                    $dest = $uploadDir . DIRECTORY_SEPARATOR . $tmpName;
                    $moved = false;
                    if (is_uploaded_file($tmp)) { $moved = @move_uploaded_file($tmp, $dest); }
                    if (!$moved) { $moved = @copy($tmp, $dest); }
                    if (!$moved) { $error = 'Falha ao salvar arquivo temporário.'; }
                    else {
                        debug_log("Arquivo salvo em: " . $dest);
                        $_SESSION['import_csv_file'] = $dest;
                        $_SESSION['import_csv_delim'] = $delim;
                        // header and sample stored above
                        debug_log("Sessão armazenada: header=" . json_encode($_SESSION['import_csv_header_raw']) . ", header_csv=" . json_encode($_SESSION['import_csv_header_csv']) . ", sample=" . json_encode($_SESSION['import_csv_sample_raw']));
                        // continue to mapping UI
                    }
            }
        }
    }
}

// Step 2: mapping submitted -> perform import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'map') {
    if (empty($_SESSION['import_csv_file']) || !file_exists($_SESSION['import_csv_file'])) { $error = 'Arquivo de importação não encontrado. Faça upload novamente.'; }
    else {
        $filePath = $_SESSION['import_csv_file'];
        $delim = $_SESSION['import_csv_delim'] ?? ',';
        $header = $_SESSION['import_csv_header'] ?? [];
        $mapping = $_POST['map'] ?? [];

        // fetch DB columns for leads
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' ORDER BY ORDINAL_POSITION ASC");
        $colStmt->execute();
        $dbCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

// Build target columns based on mapping
        $mappedTo = [];
        foreach ($mapping as $i => $target) {
            if ($target === 'ignore' || $target === '') continue;
            if (!in_array($target, $dbCols)) continue;
            $mappedTo[$i] = $target;
        }

        // If user selected a Status column but didn't map it to the DB 'status' column,
        // add it automatically so the stage mapping can be applied during import.
        $posted_status_col = isset($_POST['status_col']) && $_POST['status_col'] !== '' ? (int)$_POST['status_col'] : null;
        $hasStatusMapped = in_array('status', $mappedTo, true);
        if ($posted_status_col !== null && !$hasStatusMapped) {
            // Only add if this column index exists (we don't have header here but accept the provided index)
            $mappedTo[$posted_status_col] = 'status';
            debug_log("Auto-mapped CSV column index {$posted_status_col} to 'status' because status mapping was provided");
        }

        // Prepare status→stage mappings (if provided)
        $status_values = $_POST['status_values'] ?? [];
        $status_maps = $_POST['status_map'] ?? [];
        $status_news = $_POST['status_new'] ?? [];
        $statusToStage = [];
        $includeStageId = false; // will be true if user provided status mappings
        if (!empty($status_values) && is_array($status_values)) {
            $includeStageId = true;
            // detect funil_stages name column
            try {
                $fsColStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
                $fsColStmt->execute();
                $fsCols = $fsColStmt->fetchAll(PDO::FETCH_COLUMN);
                $FS_NAME_COL = in_array('name', $fsCols) ? 'name' : (in_array('stage_name', $fsCols) ? 'stage_name' : 'name');
            } catch (Exception $e) {
                $FS_NAME_COL = 'name';
            }
            // prepared statements for stage creation
            $stageMaxStmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) AS mx FROM funil_stages WHERE user_id = ?");
            $stageInsert = $pdo->prepare(sprintf('INSERT INTO funil_stages (user_id, %s, position, created_at) VALUES (?, ?, ?, NOW())', $FS_NAME_COL));
            // process each provided status
            foreach ($status_values as $i => $orig) {
                $origVal = trim((string)$orig);
                if ($origVal === '') continue;
                // If user requested creation of a new stage name
                $newName = trim($status_news[$i] ?? '');
                if ($newName !== '') {
                    try {
                        $stageMaxStmt->execute([$userId]);
                        $mx = (int)$stageMaxStmt->fetchColumn();
                        $newPos = $mx + 1;
                        $stageInsert->execute([$userId, $newName, $newPos]);
                        $newId = (int)$pdo->lastInsertId();
                        $statusToStage[$origVal] = $newId;
                        debug_log("Created new funil_stage for status '{$origVal}': {$newName} (id {$newId})");
                    } catch (Exception $e) {
                        debug_log("Failed to create funil_stage for status '{$origVal}': " . $e->getMessage());
                    }
                } elseif (!empty($status_maps[$i])) {
                    $statusToStage[$origVal] = (int)$status_maps[$i];
                }
            }
            debug_log('Status->Stage mapping provided: ' . json_encode($statusToStage));
        }

        // Prepare insert dynamically: always include user_id, importado, handle created_at and updated_at separately
        $insertCols = ['user_id', 'importado'];
        $placeholders = ['?', '?'];
        $createdAtMapped = false;
        $updatedAtMapped = false;
        $createdAtPos = null;
        $updatedAtPos = null;
        foreach ($mappedTo as $pos => $col) {
            if ($col === 'created_at') {
                $createdAtMapped = true;
                $createdAtPos = $pos;
            } elseif ($col === 'updated_at') {
                $updatedAtMapped = true;
                $updatedAtPos = $pos;
            } else {
                $insertCols[] = $col;
                $placeholders[] = '?';
            }
        }

        $createdAtPlaceholder = $createdAtMapped ? '?' : 'NOW()';
        $updatedAtPlaceholder = $updatedAtMapped ? '?' : 'NOW()';
        $sql = 'INSERT INTO leads (' . implode(', ', $insertCols) . ', created_at, updated_at) VALUES (' . implode(', ', $placeholders) . ', ' . $createdAtPlaceholder . ', ' . $updatedAtPlaceholder . ')';
        $ins = $pdo->prepare($sql);

        // Prepare status helpers: ensure new statuses are added to lead_statuses (global, user_id IS NULL)
        $statusCheck = $pdo->prepare('SELECT COUNT(*) FROM lead_statuses WHERE user_id IS NULL AND name = ?');
        $statusMaxStmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) AS mx FROM lead_statuses WHERE user_id IS NULL');
        $statusInsert = $pdo->prepare('INSERT INTO lead_statuses (user_id, name, position) VALUES (NULL, ?, ?)');

        // Process CSV rows
        $handle = fopen($filePath, 'r');
        if (!$handle) { $error = 'Falha ao abrir o arquivo salvo.'; }
        else {
            $rowCount = 0; $inserted = 0; $errors = [];
            $pdo->beginTransaction();
            // skip header
            $h = fgetcsv($handle, 0, $delim);
            while (($row = fgetcsv($handle, 0, $delim)) !== false) {
                $rowCount++;
                // skip empty rows
                $nonEmpty = false; foreach ($row as $v) { if (trim($v) !== '') { $nonEmpty = true; break; } }
                if (!$nonEmpty) continue;

                // Skip rows with multiple values in VALOR or KWH
                $skip = false;
                foreach ($mappedTo as $pos => $col) {
                    if ($col === 'orcamento_value' || $col === 'estimativa_projeto_kwh') {
                        $val = isset($row[$pos]) ? trim($row[$pos]) : '';
                        if (strpos($val, '|') !== false || strpos($val, ' E ') !== false || strpos($val, ' / ') !== false) {
                            $skip = true;
                            break;
                        }
                    }
                }
                if ($skip) continue;

                $values = [$userId, 1];
                foreach ($mappedTo as $pos => $col) {
                    if ($col === 'created_at' || $col === 'updated_at') continue; // handle separately
                    $val = isset($row[$pos]) ? trim($row[$pos]) : null;
                    // Remove stray question marks and replacement chars from values
                    if (is_string($val)) {
                        // remove literal '?' and the UTF-8 replacement char if present
                        $val = str_replace('?', '', $val);
                        $val = str_replace("\xEF\xBF\xBD", '', $val);
                        $val = trim($val);
                        // ensure valid UTF-8 before inserting
                        $val = ensure_utf8($val);
                    }
                    // If this is a status column, ensure it exists in lead_statuses (global)
                    if ($col === 'status' && is_string($val) && $val !== '') {
                        try {
                            $statusCheck->execute([$val]);
                            if ($statusCheck->fetchColumn() == 0) {
                                $statusMaxStmt->execute();
                                $mx = (int)$statusMaxStmt->fetchColumn();
                                $newPos = $mx + 1;
                                try {
                                    $statusInsert->execute([$val, $newPos]);
                                    debug_log("Inserted new status into lead_statuses: $val (pos $newPos)");
                                } catch (Exception $ei) {
                                    debug_log("Failed inserting status '$val': " . $ei->getMessage());
                                }
                            }
                        } catch (Exception $e) {
                            debug_log("Error checking/inserting status '$val': " . $e->getMessage());
                        }
                    }
                    // basic numeric cleanup
                    if (in_array($col, ['orcamento_value'])) {
                        $val = str_replace(['R$',' '],'',$val);
                        $val = str_replace('.','',$val); // remove thousand separators
                        $val = str_replace(',','.', $val); // decimal separator
                        $val = $val === '' ? null : $val;
                    }
                    // Date parsing for DATETIME fields
                    if (in_array($col, ['ultimo_contato', 'envio_proposta'])) {
                        $val = parseDate($val);
                    }
                    $values[] = $val;
                }
                // Handle created_at
                if ($createdAtMapped) {
                    $val = isset($row[$createdAtPos]) ? trim($row[$createdAtPos]) : null;
                    if (is_string($val)) { $val = str_replace('?', '', $val); $val = str_replace("\xEF\xBF\xBD", '', $val); $val = trim($val); }
                    $values[] = parseDate($val);
                }
                // Handle updated_at
                if ($updatedAtMapped) {
                    $val = isset($row[$updatedAtPos]) ? trim($row[$updatedAtPos]) : null;
                    if (is_string($val)) { $val = str_replace('?', '', $val); $val = str_replace("\xEF\xBF\xBD", '', $val); $val = trim($val); }
                    $values[] = parseDate($val);
                }

                try { $ins->execute($values); $inserted++; } catch (Exception $e) { $errors[] = 'Linha ' . ($rowCount+1) . ': ' . $e->getMessage(); }
            }
            $pdo->commit();
            fclose($handle);
            // cleanup
            @unlink($filePath);
            unset($_SESSION['import_csv_file'], $_SESSION['import_csv_delim'], $_SESSION['import_csv_header'], $_SESSION['import_csv_sample']);
            $result = ['rows'=>$rowCount,'inserted'=>$inserted,'errors'=>$errors];
        }
    }
}

// Limpa sessão se for GET com ?reset=1
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['import_csv_file'], $_SESSION['import_csv_delim'], $_SESSION['import_csv_header'], $_SESSION['import_csv_sample']);
    debug_log("Sessão limpa via reset");
    header('Location: import_leads.php');
    exit;
}

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="flex-grow-1 p-4">
        <div class="container">
            <h1 class="h4">Importar Leads (CSV) — Mapeamento</h1>
            <p>Você pode associar cada coluna do seu arquivo CSV a uma coluna da tabela <strong>leads</strong> antes de importar.</p>
            <p class="small text-muted">Logs de debug em: <code><?php echo htmlspecialchars($debugLog); ?></code></p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($result)): ?>
                <div class="alert alert-success">Linhas processadas: <?php echo $result['rows']; ?> — Inseridos: <?php echo $result['inserted']; ?></div>
                <?php if (!empty($result['errors'])): ?>
                    <div class="alert alert-warning">Erros:<br><ul><?php foreach($result['errors'] as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul></div>
                <?php endif; ?>
                <a href="leads_gestao.php" class="btn btn-secondary">Voltar para Gestão</a>
            <?php else: ?>

            <?php if (empty($_SESSION['import_csv_file'])): ?>
                <form method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                    </div>
                    <div class="mb-2 small text-muted">Dica: salve do Excel usando CSV UTF-8. O importador detecta `,`, `;` ou tab.</div>
                    <button class="btn btn-primary">Enviar e Mapear</button>
                    <a href="leads_gestao.php" class="btn btn-secondary">Cancelar</a>
                </form>
            <?php else: 
                $header = $_SESSION['import_csv_header_raw'] ?? [];
                $headerCsv = $_SESSION['import_csv_header_csv'] ?? [];
                $sample = $_SESSION['import_csv_sample_raw'] ?? [];
                // fetch DB columns
                $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' ORDER BY ORDINAL_POSITION ASC");
                $colStmt->execute();
                $dbCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                // show warning if header/sample counts mismatch
                if (empty($header) || !is_array($header) || (count($header) == 1 && trim($header[0]) == '')) {
                    echo '<div class="alert alert-warning">Não foi possível detectar o cabeçalho do CSV. Verifique se o arquivo tem uma primeira linha com os nomes das colunas separados por vírgula, ponto-e-vírgula ou tab.</div>';
                    echo '<pre>Debug header (chosen): '.htmlspecialchars(print_r($header, true))."\nDebug header (csv parser): " . htmlspecialchars(print_r($headerCsv, true)) . '</pre>';
                    echo '<a href="import_leads.php?reset=1" class="btn btn-secondary mt-2">Fazer upload novamente</a>';
                    unset($_SESSION['import_csv_file'], $_SESSION['import_csv_delim'], $_SESSION['import_csv_header_raw'], $_SESSION['import_csv_header_csv'], $_SESSION['import_csv_sample_raw']);
                    debug_log("Sessão limpa automaticamente por cabeçalho inválido");
                    echo '<script>setTimeout(function(){ window.location.href = "import_leads.php"; }, 100);</script>';
                } else {
                    // If header and sample have different counts, show a warning and the parsed variants
                    if (!empty($sample) && count($header) !== count($sample)) {
                        echo '<div class="alert alert-warning">Aviso: número de colunas do cabeçalho ('.count($header).') difere do número de campos na primeira linha de dados ('.count($sample).'). Verifique delimitador ou formato do CSV.</div>';
                        echo '<div class="small text-muted">Cabeçalho (csv parse): '.htmlspecialchars(implode(' | ', $headerCsv)).'<br>Sample: '.htmlspecialchars(implode(' | ', $sample)).'</div>';
                    }

            ?>
                <div class="alert alert-info small">Cabeçalho detectado: <code><?= htmlspecialchars(implode(' | ', $header)) ?></code></div>
                <form method="post">
                    <input type="hidden" name="action" value="map">
                    <table class="table table-sm">
                        <thead><tr><th>Coluna CSV</th><th>Exemplo</th><th>Mapear para</th></tr></thead>
                        <tbody>
                        <?php foreach ($header as $i => $h): $hClean = remove_bom($h); $sampleVal = $sample[$i] ?? ''; $autoMapped = auto_map_column($hClean, $autoMap, $dbCols); ?>
                            <tr>
                                <td><?php echo htmlspecialchars($hClean); ?></td>
                                <td><?php echo htmlspecialchars($sampleVal); ?></td>
                                <td>
                                    <select name="map[<?php echo $i; ?>]" class="form-select form-select-sm">
                                        <option value="">-- ignorar --</option>
                                        <?php foreach ($dbCols as $col): $selected = ($autoMapped === $col) ? 'selected' : ''; ?>
                                            <option value="<?php echo htmlspecialchars($col); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($col); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php
                    // --- Status -> Stage mapping UI (restored) ---
                    $chosenStatusCol = isset($_GET['status_col']) ? (int)$_GET['status_col'] : null;
                    $autoStatusIndex = null;
                    foreach ($header as $i => $hh) {
                        if (auto_map_column($hh, $autoMap, $dbCols) === 'status') { $autoStatusIndex = $i; break; }
                    }
                    if ($chosenStatusCol === null) { $chosenStatusCol = $autoStatusIndex; }
                    ?>

                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Mapeamento: <small class="text-muted">Status CSV → Estágio (stage_id)</small></h6>
                                <button id="autoMapStatusBtn" type="button" class="btn btn-sm btn-outline-primary">Mapear automaticamente</button>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Coluna de Status no CSV</label>
                                <select name="status_col" class="form-select form-select-sm" onchange="if(this.value === '') { window.location.href='import_leads.php'; } else { window.location.href='import_leads.php?status_col='+encodeURIComponent(this.value); }">
                                    <option value="">-- nenhum --</option>
                                    <?php foreach ($header as $i => $hh): $sel = ($i === $chosenStatusCol) ? 'selected' : ''; ?>
                                        <option value="<?php echo $i; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($hh); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($chosenStatusCol !== null && isset($_SESSION['import_csv_file']) && file_exists($_SESSION['import_csv_file'])):
                                $statusCounts = [];
                                $fh = fopen($_SESSION['import_csv_file'], 'r');
                                if ($fh) {
                                    $skip = fgetcsv($fh, 0, $_SESSION['import_csv_delim'] ?? ',');
                                    while (($r = fgetcsv($fh, 0, $_SESSION['import_csv_delim'] ?? ',')) !== false) {
                                        $v = isset($r[$chosenStatusCol]) ? $r[$chosenStatusCol] : '';
                                        $v = remove_bom(trim($v));
                                        if (strpos($v, "\xEF\xBF\xBD") !== false || mb_detect_encoding($v, 'UTF-8', true) === false) {
                                            $try = @mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1'); if ($try !== false && $try !== null) { $v = $try; }
                                        }
                                        $v = sanitize_status_value($v);
                                        if ($v !== '') { $statusCounts[$v] = ($statusCounts[$v] ?? 0) + 1; }
                                    }
                                    fclose($fh);
                                }

                                if (!empty($statusCounts)) {
                                    try {
                                        $fsColStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'"); $fsColStmt->execute(); $fsCols = $fsColStmt->fetchAll(PDO::FETCH_COLUMN); $FS_NAME_COL = in_array('name',$fsCols)?'name':(in_array('stage_name',$fsCols)?'stage_name':'name');
                                    } catch (Exception $e) { $FS_NAME_COL = 'name'; }
                                    $stStmt = $pdo->prepare(sprintf('SELECT id, %s AS name FROM funil_stages WHERE (user_id = ? OR user_id IS NULL) ORDER BY COALESCE(position,id) ASC', $FS_NAME_COL));
                                    $stStmt->execute([$userId]); $stages = $stStmt->fetchAll(PDO::FETCH_ASSOC);
                                    echo '<div class="small text-muted mb-2">Valores únicos de status encontrados: ' . count($statusCounts) . '</div>';
                                    echo '<table class="table table-sm"><thead><tr><th>Status CSV</th><th>Linhas</th><th>Stage (usar existente)</th><th>Criar novo (nome)</th></tr></thead><tbody>';
                                    $i = 0;
                                    foreach ($statusCounts as $sv => $cnt) {
                                        $svClean = sanitize_status_value($sv);
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($svClean) . '<input type="hidden" name="status_values[]" value="' . htmlspecialchars($svClean) . '"></td>';
                                        echo '<td>' . $cnt . '</td>';
                                        echo '<td><select name="status_map[' . $i . ']" class="form-select form-select-sm"><option value="">-- nenhum --</option>';
                                        foreach ($stages as $s) { echo '<option value="' . $s['id'] . '">' . htmlspecialchars($s['name']) . '</option>'; }
                                        echo '</select></td>';
                                        echo '<td><input type="text" name="status_new[' . $i . ']" class="form-control form-control-sm" placeholder="Criar novo estágio (nome)"></td>';
                                        echo '</tr>';
                                        $i++;
                                    }
                                    echo '</tbody></table>';
                                    echo '<div id="autoMapSummary" class="small mt-2"></div>';
                                } else {
                                    echo '<div class="small text-muted">Nenhum valor de status encontrado nesta coluna.</div>';
                                }
                            endif; ?>
                        </div>
                    </div>

                    <input type="hidden" name="status_col" value="<?php echo htmlspecialchars($chosenStatusCol !== null ? $chosenStatusCol : ''); ?>">
                    <button class="btn btn-primary">Importar com mapeamento</button>
                    <a href="import_leads.php?reset=1" class="btn btn-secondary">Fazer upload novamente</a>
                </form>
            <?php } endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('autoMapStatusBtn');
    if (!btn) return;
    btn.addEventListener('click', function(){
        const statusInputs = Array.from(document.querySelectorAll('input[name="status_values[]"]'));
        if (statusInputs.length === 0) return;
        // Use options from the first available select in the table
        const anySelect = document.querySelector('select[name^="status_map"]');
        if (!anySelect) return;
        const options = Array.from(anySelect.options).map(o => ({ value: o.value, text: o.textContent }));

        function norm(s){
            if (!s) return '';
            try{
                return s.toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,' ').trim().toLowerCase();
            } catch(e) {
                return s.toString().replace(/\s+/g,' ').trim().toLowerCase();
            }
        }

        let mapped = 0;
        let fallbackMapped = 0;
        statusInputs.forEach(function(input){
            const sv = norm(input.value);
            if (sv === '') return;
            let found = null;
            for (const o of options) { if (norm(o.text) === sv) { found = o; break; } }
            if (!found) {
                for (const o of options) { const on = norm(o.text); if (on.includes(sv) || sv.includes(on)) { found = o; break; } }
            }
            if (!found) {
                const parts = sv.split(' ');
                let best = null, bestScore = 0;
                for (const o of options) {
                    const on = norm(o.text);
                    let score = 0;
                    parts.forEach(p => { if (p.length > 2 && on.includes(p)) score++; });
                    if (score > bestScore) { bestScore = score; best = o; }
                }
                if (bestScore > 0) found = best;
            }
            // Find corresponding select in the same row
            const tr = input.closest('tr');
            const sel = tr ? tr.querySelector('select[name^="status_map"]') : null;
            if (!sel) return;
            let perRowFallback = false;
            if (!found) {
                // fallback: choose first non-empty option
                found = options.find(o => o.value && o.value !== '') || null;
                if (found) perRowFallback = true;
            }
            if (found && found.value !== '') {
                // set value and trigger change to ensure it is present in form submission
                sel.value = found.value;
                const ev = new Event('change', { bubbles: true });
                sel.dispatchEvent(ev);
                mapped++;
                if (perRowFallback) {
                    fallbackMapped++;
                    sel.classList.add('border-warning');
                }
            }
        });

        // Build a summary of assignments
        const summaryWrap = document.getElementById('autoMapSummary');
        if (summaryWrap) {
            const rows = [];
            statusInputs.forEach(function(input){
                const tr = input.closest('tr');
                const sel = tr ? tr.querySelector('select[name^="status_map"]') : null;
                const stageId = sel ? sel.value : '';
                const stageText = sel ? (sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '') : '';
                rows.push({ status: input.value, stageId: stageId, stageText: stageText });
            });
            let html = '<strong>Resumo de mapeamento:</strong><br><ul class="small mb-0">';
            rows.forEach(r => { html += '<li>' + (r.status ? r.status : '(vazio)') + ' → ' + (r.stageId ? (r.stageText + ' (id:'+r.stageId+')') : '<em>nenhum</em>') + '</li>'; });
            html += '</ul>';
            summaryWrap.innerHTML = html;
        }

        const original = btn.textContent;
        btn.textContent = mapped ? ('Mapeado: ' + mapped + (fallbackMapped ? (' (fallback ' + fallbackMapped + ')') : '')) : 'Nenhum mapeamento encontrado';
        setTimeout(function(){ btn.textContent = original; }, 2000);
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php';
