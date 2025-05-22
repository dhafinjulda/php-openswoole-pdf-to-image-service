<?php
use OpenSwoole\Http\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Coroutine;

require __DIR__ . '/PdfConverter.php';

class PdfImageServer
{
  private Server $server;
  private PdfConverter $converter;
  private string $uploadDir;
  private string $appUrlScheme;
  private string $appUrlHost;
  private int $appUrlPort;

  public function __construct(string $host = '0.0.0.0', int $port = 9501)
  {
    $this->uploadDir = getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads';
    $this->converter = new PdfConverter($this->uploadDir);
    $this->server = new Server($host, $port);

    $this->appUrlScheme = getenv('APP_URL_SCHEME') ?: 'http';
    $this->appUrlHost = getenv('APP_URL_HOST') ?: $host;
    $this->appUrlPort = (int)(getenv('APP_URL_PORT') ?: $port);

    $this->configureServer();
  }

  private function configureServer(): void
  {
    $this->server->set([
      'worker_num' => OpenSwoole\Util::getCPUNum() * 2,
      'upload_tmp_dir' => $this->uploadDir,
      'document_root' => $this->uploadDir,
      'enable_static_handler' => true,
      'http_parse_post' => true,
      'package_max_length' => 50 * 1024 * 1024,
    ]);

    $this->server->on('start', [$this, 'onStart']);
    $this->server->on('request', [$this, 'onRequest']);
    $this->server->on('workerStart', [$this, 'onWorkerStart']);
  }

  public function onStart(Server $server): void
  {
    echo sprintf(
      "PDF to Image service started. Internally listening at http://%s:%d. Accessible base URL for downloads: %s://%s:%d\n",
      $server->host,
      $server->port,
      $this->appUrlScheme,
      $this->appUrlHost,
      $this->appUrlPort
    );
  }

  public function onWorkerStart(Server $server, int $workerId): void
  {
    echo "Worker #{$workerId} started\n";

    if (!file_exists($this->uploadDir) && !mkdir($concurrentDirectory = $this->uploadDir, 0777, true) && !is_dir($concurrentDirectory)) {
      throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
    }

    $tempDir = $this->uploadDir . '/temp';
    if (!is_dir($tempDir)) {
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $tempDir));
        }
    }
  }

  public function onRequest(Request $request, Response $response): void
  {
    $this->setCorsHeaders($response);

    if ($request->server['request_method'] === 'OPTIONS') {
      $response->status(200);
      $response->end();
      return;
    }

    try {
      $path = $request->server['request_uri'] ?? '/';

      if ($path === '/convert' && $request->server['request_method'] === 'POST') {
        $this->handleConvertRequest($request, $response);
      } elseif ($path === '/fetch-and-convert' && $request->server['request_method'] === 'POST') {
        $this->handleFetchAndConvertRequest($request, $response);
      } elseif (strpos($path, '/download/') === 0) {
        $this->handleDownloadRequest($request, $response);
      } elseif ($path === '/health') {
        $this->handleHealthCheck($response);
      } else {
        $response->status(404);
        $response->end(json_encode(['error' => 'Endpoint not found']));
      }
    } catch (Throwable $e) {
      $this->handleError($response, $e);
    }
  }

  private function handleConvertRequest(Request $request, Response $response): void
  {
    if (empty($request->files['pdf_file'])) {
      throw new InvalidArgumentException('No PDF file uploaded');
    }

    $uploadedFile = $request->files['pdf_file'];

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException(sprintf(
        'File upload error: %s',
        $this->getUploadErrorMessage($uploadedFile['error'])
      ));
    }

    // Sanitize the original filename
    $originalFilename = $uploadedFile['name'];
    $sanitizedOriginalFilename = preg_replace('/[^A-Za-z0-9\._-]/', '', basename($originalFilename));
    if (empty($sanitizedOriginalFilename) || $sanitizedOriginalFilename === '.pdf') {
        $sanitizedOriginalFilename = 'uploaded_file.pdf'; // Fallback filename
    }
    if (strtolower(pathinfo($sanitizedOriginalFilename, PATHINFO_EXTENSION)) !== 'pdf') {
      $sanitizedOriginalFilename .= '.pdf';
    }

    $filenameNoExt = pathinfo($sanitizedOriginalFilename, PATHINFO_FILENAME);

    // Validate MIME type using the temporary uploaded file
    $mimeType = mime_content_type($uploadedFile['tmp_name']);
    if ($mimeType !== 'application/pdf') {
      throw new InvalidArgumentException(
        'Invalid file type. Only PDF files are allowed. Detected: ' . $mimeType
      );
    }

    // Validate file size
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($uploadedFile['size'] > $maxSize) {
      throw new InvalidArgumentException(
        sprintf('File too large. Maximum size is %dMB', $maxSize / 1024 / 1024)
      );
    }

    // Move uploaded file to uploads/temp
    $tmpDir = $this->uploadDir . '/temp';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
    }
    $persistedFilePath = $tmpDir . '/' . $sanitizedOriginalFilename;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $persistedFilePath)) {
        throw new RuntimeException('Failed to move uploaded file to permanent location.');
    }

    // Determine output type (blob or base64)
    $output = "blob"; // Default output type
    if (
      isset($request->header['content-type']) &&
      strpos($request->header['content-type'], 'application/json') !== false
    ) {
        $data = json_decode($request->rawContent(), true);
        $output = $data['output'] ?? $request->post['output'] ?? "blob";
    } else {
        $output = $request->post['output'] ?? "blob";
    }

    Coroutine::create(function () use ($persistedFilePath, $filenameNoExt, $response, $output, $originalFilename) {
      try {
        // Use the path to the file now in uploads/temp
        $result = $this->converter->convertToImages(
          $persistedFilePath,
          $filenameNoExt, // Pass the sanitized base filename
          $output          // Pass the desired output type ("blob" or "base64")
        );

        $baseUrl = sprintf(
          '%s://%s:%d/download/',
          $this->appUrlScheme,
          $this->appUrlHost,
          $this->appUrlPort
        );

        $imagesData = [];
        if ($output === "base64") {
            $imagesData = $result; // Result is already an array of base64 strings
        } else {
            $imagesData = array_map(
                fn($img) => $baseUrl . $img, // Result is an array of filenames
                $result
            );
        }

        $responseData = [
          'success' => true,
          'original_name' => $originalFilename,
          'pages' => count($result),
          'images' => $imagesData,
          'output_type' => $output,
          'timestamp' => time(),
        ];

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($responseData));

      } catch (Throwable $e) {
        $this->handleError($response, $e);
      }
      // The file at $persistedFilePath in uploads/temp will be kept
      // as there's no unlink operation here.
    });
  }

  private function handleFetchAndConvertRequest(Request $request, Response $response): void
  {
    $output = "blob";
    if (
      isset($request->header['content-type']) &&
      strpos($request->header['content-type'], 'application/json') !== false
    ) {
      $data = json_decode($request->rawContent(), true);
      $url = $data['url'] ?? null;
      $output = $data['output'] ?? "blob";
    } else {
      $url = $request->post['url'] ?? null;
      $output = $request->post['output'] ?? "blob";
    }

    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
      throw new InvalidArgumentException('A valid URL must be provided');
    }

    $tmpDir = $this->uploadDir . '/temp';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
      throw new \RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
    }

    $headers = @get_headers($url, 1);
    $filename = basename(parse_url($url, PHP_URL_PATH));

    if (isset($headers['Content-Disposition'])) {
        if (is_array($headers['Content-Disposition'])) {
            foreach ($headers['Content-Disposition'] as $cdHeader) {
                if (preg_match('/filename="?([^"]+)"?/i', $cdHeader, $matches)) {
                    $filename = $matches[1];
                    break;
                }
            }
        } elseif (preg_match('/filename="?([^"]+)"?/i', $headers['Content-Disposition'], $matches)) {
            $filename = $matches[1];
        }
    }

    $filename = preg_replace('/[^A-Za-z0-9\._-]/', '', $filename);
    if (empty($filename) || $filename === '.pdf') {
        $filename = 'downloaded_file.pdf';
    }
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
      $filename .= '.pdf';
    }

    $fullTmpPath = $tmpDir . '/' . $filename;

    $fileContent = @file_get_contents($url);
    if ($fileContent === false) {
      throw new RuntimeException('Failed to download file from URL: ' . $url);
    }
    if (file_put_contents($fullTmpPath, $fileContent) === false) {
        throw new RuntimeException('Failed to write downloaded file to: ' . $fullTmpPath);
    }

    $mimeType = mime_content_type($fullTmpPath);
    if ($mimeType !== 'application/pdf') {
      if (file_exists($fullTmpPath)) {
        unlink($fullTmpPath);
      }
      throw new InvalidArgumentException('Downloaded file is not a valid PDF. Detected MIME type: ' . $mimeType);
    }

    $maxSize = 10 * 1024 * 1024;
    if (filesize($fullTmpPath) > $maxSize) {
      if (file_exists($fullTmpPath)) {
        unlink($fullTmpPath);
      }
      throw new InvalidArgumentException(
        sprintf('File too large. Maximum size is %dMB', $maxSize / 1024 / 1024)
      );
    }

    $filenameNoExt = pathinfo($filename, PATHINFO_FILENAME);

    Coroutine::create(function () use ($filenameNoExt, $fullTmpPath, $response, $url, $output) {
      try {
        $result = $this->converter->convertToImages(
          $fullTmpPath,
          $filenameNoExt,
          $output
        );

        $baseUrl = sprintf(
          '%s://%s:%d/download/',
          $this->appUrlScheme,
          $this->appUrlHost,
          $this->appUrlPort
        );

        $imagesData = [];
        if ($output === "base64") {
            $imagesData = $result;
        } else {
            $imagesData = array_map(
                static fn($img) => $baseUrl . $img,
                $result
            );
        }

        $responseData = [
          'success' => true,
          'source_url' => $url,
          'pages' => count($result),
          'images' => $imagesData,
          'output_type' => $output,
          'timestamp' => time(),
        ];

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($responseData));
      } catch (Throwable $e) {
        $this->handleError($response, $e);
      }
    });
  }

  private function handleDownloadRequest(Request $request, Response $response): void
  {
    $filename = basename($request->server['request_uri']);
    $filePath = $this->uploadDir . '/' . $filename;

    if (!file_exists($filePath)) {
      $response->status(404);
      $response->end('Image not found');
      return;
    }

    $mimeType = mime_content_type($filePath);
    $fileContent = file_get_contents($filePath);

    $response->header('Content-Type', $mimeType);
    $response->header('Content-Disposition', sprintf(
      'inline; filename="%s"',
      basename($filePath)
    ));
    $response->header('Cache-Control', 'public, max-age=86400');
    $response->end($fileContent);
  }

  private function handleHealthCheck(Response $response): void
  {
    $status = [
      'status' => 'ok',
      'version' => '1.0',
      'timestamp' => time(),
      'services' => [
        'imagick' => extension_loaded('imagick'),
        'swoole' => extension_loaded('swoole'),
      ],
    ];

    $response->header('Content-Type', 'application/json');
    $response->end(json_encode($status));
  }

  private function handleError(Response $response, Throwable $e): void
  {
    $statusCode = $e instanceof InvalidArgumentException ? 400 : 500;
    $errorData = [
      'error' => $e->getMessage(),
      'code' => $e->getCode(),
      'type' => get_class($e),
    ];

    if (getenv('APP_ENV') === 'development') {
      $errorData['trace'] = $e->getTrace();
    }

    $response->status($statusCode);
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode($errorData));
  }

  private function setCorsHeaders(Response $response): void
  {
    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Content-Type');
    $response->header('Access-Control-Max-Age', '86400');
  }

  private function getUploadErrorMessage(int $errorCode): string
  {
    $errors = [
      UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
      UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
      UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
      UPLOAD_ERR_NO_FILE => 'No file was uploaded',
      UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
      UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
      UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
    ];

    return $errors[$errorCode] ?? 'Unknown upload error';
  }

  public function start(): void
  {
    $this->server->start();
  }
}

$server = new PdfImageServer();
$server->start();
