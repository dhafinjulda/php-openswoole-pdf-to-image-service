<?php
class PdfConverter
{
  private string $uploadDir;
  private array $allowedMimeTypes = ['application/pdf'];

  public function __construct(string $uploadDir)
  {
    $this->uploadDir = rtrim($uploadDir, '/');

    if (!is_dir($this->uploadDir) && !mkdir($concurrentDirectory = $this->uploadDir, 0777, true) && !is_dir($concurrentDirectory)) {
      throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
    }
  }

  public function convertToImages(string $pdfPath, string $filename, string $output = "blob"): array
  {
    if (!extension_loaded('imagick')) {
      throw new RuntimeException('Imagick extension is not installed');
    }
    $outputImages = [];

    try {
      $imagick = new Imagick();
      $imagick->setResolution(300, 300);
      $imagick->readImage($pdfPath);
      $imagick->setImageFormat('png');
      $imagick->setImageCompressionQuality(100);

      foreach ($imagick as $i => $page) {
        if ($output === "base64") {
          // Get image blob and encode directly to base64
          $outputImages[] = base64_encode($page->getImageBlob());
        } else {
          $outputPath = "{$this->uploadDir}/{$filename}.png";
          if (count($imagick)>1) {
            $outputPath = "{$this->uploadDir}/{$filename}-{$i}.png";
          }
          $page->writeImage($outputPath);
          $outputImages[] = basename($outputPath);
        }
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