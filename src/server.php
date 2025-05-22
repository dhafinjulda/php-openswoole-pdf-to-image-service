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
  // Add properties for URL parts
  private string $appUrlScheme;
  private string $appUrlHost;
  private int $appUrlPort;

  public function __construct(string $host = '0.0.0.0', int $port = 9501)
  {
    $this->uploadDir = getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads';
    $this->converter = new PdfConverter($this->uploadDir);
    $this->server = new Server($host, $port); // This is the internal server binding

    // --- Read base URL parts from environment variables ---
    $this->appUrlScheme = getenv('APP_URL_SCHEME') ?: 'http';
    $this->appUrlHost = getenv('APP_URL_HOST') ?: $host; // Default to internal host if not set
    $this->appUrlPort = (int)(getenv('APP_URL_PORT') ?: $port); // Default to internal port if not set
    // -----------------------------------------------------

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
      'package_max_length' => 50 * 1024 * 1024, // 50MB
    ]);

    $this->server->on('start', [$this, 'onStart']);
    $this->server->on('request', [$this, 'onRequest']);
    $this->server->on('workerStart', [$this, 'onWorkerStart']);
  }

  public function onStart(Server $server): void
  {
    // Output the actual accessible URL for clarity
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

    // Initialize any worker-specific resources here
    if (!file_exists($this->uploadDir) && !mkdir($concurrentDirectory = $this->uploadDir, 0777, true) && !is_dir($concurrentDirectory)) {
      throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
    }
  }

  public function onRequest(Request $request, Response $response): void
  {
    // Enable CORS (adjust as needed for your environment)
    $this->setCorsHeaders($response);

    // Handle preflight requests
    if ($request->server['request_method'] === 'OPTIONS') {
      $response->status(200);
      $response->end();
      return;
    }

    // Route requests
    try {
      $path = $request->server['request_uri'] ?? '/';

      if ($path === '/convert' && $request->server['request_method'] === 'POST') {
        $this->handleConvertRequest($request, $response);
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
    // Validate request
    if (empty($request->files['pdf_file'])) {
      throw new InvalidArgumentException('No PDF file uploaded');
    }

    $uploadedFile = $request->files['pdf_file'];

    // Validate upload
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException(sprintf(
        'File upload error: %s',
        $this->getUploadErrorMessage($uploadedFile['error'])
      ));
    }

    // Validate file type
    $mimeType = mime_content_type($uploadedFile['tmp_name']);
    if ($mimeType !== 'application/pdf') {
      throw new InvalidArgumentException(
        'Invalid file type. Only PDF files are allowed'
      );
    }

    // Validate file size (10MB limit)
    $maxSize = 10 * 1024 * 1024;
    if ($uploadedFile['size'] > $maxSize) {
      throw new InvalidArgumentException(
        sprintf('File too large. Maximum size is %dMB', $maxSize / 1024 / 1024)
      );
    }

    // Process in coroutine to avoid blocking
    Coroutine::create(function () use ($uploadedFile, $request, $response) { // Added $request here
      try {
        $result = $this->converter->convertToImages(
          $uploadedFile['tmp_name'],
          $request->post['dpi'] ?? 150,
          $request->post['quality'] ?? 90
        );

        // --- Construct baseUrl using environment variables ---
        $baseUrl = sprintf(
          '%s://%s:%d/download/',
          $this->appUrlScheme,
          $this->appUrlHost,
          $this->appUrlPort
        );
        // --------------------------------------------------

        $responseData = [
          'success' => true,
          'original_name' => $uploadedFile['name'],
          'pages' => count($result),
          'images' => array_map(
            fn($img) => $baseUrl . $img,
            $result
          ),
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

// Start the server
$server = new PdfImageServer();
$server->start();