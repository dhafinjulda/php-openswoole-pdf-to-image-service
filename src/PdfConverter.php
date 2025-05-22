<?php
class PdfConverter
{
  private string $uploadDir;
  private array $allowedMimeTypes = ['application/pdf'];

  public function __construct(string $uploadDir)
  {
    $this->uploadDir = rtrim($uploadDir, '/');

    if (!is_dir($this->uploadDir)) {
      mkdir($this->uploadDir, 0777, true);
    }
  }

  public function convertToImages(string $pdfPath): array
  {
    if (!extension_loaded('imagick')) {
      throw new RuntimeException('Imagick extension is not installed');
    }

    $filename = uniqid('pdf_') . '_' . time();
    $outputImages = [];

    try {
      $imagick = new Imagick();
      $imagick->setResolution(150, 150);
      $imagick->readImage($pdfPath);
      $imagick->setImageFormat('png');

      foreach ($imagick as $i => $page) {
        $outputPath = "{$this->uploadDir}/{$filename}_page_{$i}.png";
        $page->writeImage($outputPath);
        $outputImages[] = basename($outputPath);
      }

      $imagick->clear();
      $imagick->destroy();

      return $outputImages;

    } catch (ImagickException $e) {
      throw new RuntimeException("PDF conversion failed: " . $e->getMessage());
    }
  }

  public function getImageContent(string $filename): ?string
  {
    $path = "{$this->uploadDir}/{$filename}";
    return file_exists($path) ? file_get_contents($path) : null;
  }
}