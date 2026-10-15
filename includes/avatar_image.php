<?php

/** Save a user avatar as an optimized JPEG with its longest side capped at 200px. */
function wrcrm_save_avatar_image(string $temporaryFile, int $userId): string
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('O servidor não tem suporte GD para processar a foto.');
    }

    $info = @getimagesize($temporaryFile);
    if (!$info || empty($info['mime'])) {
        throw new RuntimeException('O arquivo enviado não é uma imagem válida.');
    }

    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/gif' => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
    ];
    $mime = $info['mime'];
    if (!isset($loaders[$mime]) || !function_exists($loaders[$mime])) {
        throw new RuntimeException('Formato de imagem não suportado pelo servidor.');
    }

    $source = @$loaders[$mime]($temporaryFile);
    if (!$source) {
        throw new RuntimeException('Não foi possível ler a imagem enviada.');
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, 200 / max($width, $height));
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));
    $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
    $white = imagecolorallocate($thumbnail, 255, 255, 255);
    imagefill($thumbnail, 0, 0, $white);

    if (!imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
        imagedestroy($source);
        imagedestroy($thumbnail);
        throw new RuntimeException('Não foi possível redimensionar a imagem.');
    }
    imagedestroy($source);

    $relativePath = 'uploads/avatar_' . $userId . '.jpg';
    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $directory = dirname($absolutePath);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        imagedestroy($thumbnail);
        throw new RuntimeException('Não foi possível preparar a pasta de fotos.');
    }

    $saved = @imagejpeg($thumbnail, $absolutePath, 82);
    imagedestroy($thumbnail);
    if (!$saved) {
        throw new RuntimeException('Não foi possível salvar a foto redimensionada.');
    }
    @chmod($absolutePath, 0644);

    return $relativePath;
}
