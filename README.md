# PDF to Image Conversion Service

This service provides an HTTP API to convert uploaded PDF files into a series of images (one per page). It's built with PHP, OpenSwoole for high-concurrency, and uses ImageMagick for the PDF processing.


## Features

*   Upload PDF files via a POST request.
*   Converts each page of the PDF into an image (default: JPG).
*   Configurable DPI and image quality for conversion.
*   Provides download links for the generated images.
*   Health check endpoint.
*   CORS enabled for wider client-side integration.
*   Built to run in a Docker container.
## Prerequisites

*   [Docker](https://www.docker.com/get-started)
*   [Docker Compose](https://docs.docker.com/compose/install/)

## Setup and Running

1.  **Clone the Repository (if applicable)**
    If this project is in a Git repository, clone it:
    ```bash
    git clone <your-repository-url>
    cd <repository-directory>
    ```

2.  **Configure Environment Variables (Optional)**
    The service is configured using environment variables in the `docker-compose.yml` file. You can adjust these as needed:
    *   `APP_ENV`: Set to `development` for detailed error traces, or `production`.
    *   `UPLOAD_DIR`: Path within the container where uploads are stored.
    *   `APP_URL_SCHEME`: The scheme (e.g., `http`, `https`) for the generated image URLs.
    *   `APP_URL_HOST`: The hostname (e.g., `localhost`, `yourdomain.com`) for the generated image URLs.
    *   `APP_URL_PORT`: The port (e.g., `9501`) for the generated image URLs.

    Default `docker-compose.yml` snippet:
    ```yaml
    services:
      pdf-converter:
        # ... other configs
        environment:
          - APP_ENV=development
          - UPLOAD_DIR=/var/www/uploads
          - APP_URL_SCHEME=http
          - APP_URL_HOST=localhost
          - APP_URL_PORT=9501 # Matches the host port mapping
    ```

3.  **Build and Start the Service**
    Use Docker Compose to build the image and start the service:
    ```bash
    docker-compose build
    docker-compose up -d
    ```
    The service will typically be available at `http://localhost:9501` (or as configured by `APP_URL_HOST` and `APP_URL_PORT`).

4.  **Verify Service is Running**
    You can check the service logs:
    ```bash
    docker-compose logs -f pdf-converter
    ```
    You should also be able to access the health check endpoint in your browser or via `curl`:
    `http://localhost:9501/health`

## API Endpoints

### 1. Convert PDF to Images

*   **Endpoint:** `/convert`
*   **Method:** `POST`
*   **Content-Type:** `multipart/form-data`
*   **Form Parameters:**
    *   `pdf_file`: (file) The PDF file to convert. (Required)
    *   `dpi`: (integer) Dots Per Inch for the output images. (Optional, default: `150`)
    *   `quality`: (integer) Image quality for JPG output (0-100). (Optional, default: `90`)

*   **Success Response (200 OK):**
    ```json
    {
        "success": true,
        "original_name": "mydocument.pdf",
        "pages": 3,
        "images": [
            "http://localhost:9501/download/image_page_1.jpg",
            "http://localhost:9501/download/image_page_2.jpg",
            "http://localhost:9501/download/image_page_3.jpg"
        ],
        "timestamp": 1678886400
    }
    ```

*   **Error Responses:**
    *   `400 Bad Request`: If no file is uploaded, invalid file type, file too large, or other input validation errors.
        ```json
        {
            "error": "No PDF file uploaded",
            "code": 0,
            "type": "InvalidArgumentException"
        }
        ```
    *   `500 Internal Server Error`: If an error occurs during conversion.
        ```json
        {
            "error": "PDF conversion failed: ...",
            "code": 0,
            "type": "RuntimeException"
        }
        ```

*   **Example Usage (cURL):**
    ```bash
    curl -X POST -F "pdf_file=@/path/to/your/document.pdf" -F "dpi=200" http://localhost:9501/convert
    ```

### 2. Download Generated Image

*   **Endpoint:** `/download/{image_filename}`
*   **Method:** `GET`
*   **Description:** Downloads a previously generated image. The `image_filename` is obtained from the `/convert` endpoint response.

*   **Success Response (200 OK):**
    *   The image file with appropriate `Content-Type` and `Content-Disposition` headers.

*   **Error Responses:**
    *   `404 Not Found`: If the image file does not exist.

*   **Example Usage (Browser or cURL):**
    `http://localhost:9501/download/image_page_1.jpg`

### 3. Health Check

*   **Endpoint:** `/health`
*   **Method:** `GET`
*   **Description:** Provides the health status of the service.

*   **Success Response (200 OK):**
    ```json
    {
        "status": "ok",
        "version": "1.0",
        "timestamp": 1678886400,
        "services": {
            "imagick": true,
            "swoole": true
        }
    }
    ```

## Development Notes

*   **ImageMagick Policy:** The `custom_policy.xml` file is included to explicitly allow ImageMagick to read and write PDF files. This is necessary because many default ImageMagick policies restrict PDF operations for security reasons. This file is copied into the Docker image during the build process.
*   **Dependencies:**
    *   System dependencies (like `ghostscript`, `libmagickwand-dev`) are installed via `apt-get` in the `Dockerfile`.
    *   PHP extensions (`imagick`, `openswoole`) are installed via `pecl` and `docker-php-ext-install`.
*   **Error Handling:** The application provides JSON error responses. In `development` mode (`APP_ENV=development`), stack traces are included in the error response.
*   **Uploads Directory:** The `./uploads` directory on your host machine is mounted to `/var/www/uploads` in the container. This is where uploaded PDFs are temporarily stored and where generated images are saved. Ensure this directory exists and has appropriate write permissions if you are not using Docker Desktop's default volume handling.

## Stopping the Service

```bash 
  docker-compose down
```

To remove the volumes (and thus the uploaded/generated images):


```bash 
  docker-compose down -v
```

## Troubleshooting

*   **`PDF conversion failed: attempt to perform an operation not allowed by the security policy 'PDF'`:** This means ImageMagick's policy is still too restrictive.
    *   Ensure `custom_policy.xml` is correctly copied to the right location in the `Dockerfile`.
    *   Verify the ImageMagick version in your container and adjust the path in the `Dockerfile`'s `COPY` command if necessary (e.g., `/etc/ImageMagick-7/policy.xml` for IM7).
    *   Rebuild the image (`docker-compose build`) after any Dockerfile changes.
*   **Permission Denied errors for `/var/www/uploads`:**
    *   Ensure the `uploads` directory on your host has the correct permissions if you are manually managing it. Docker Compose typically handles this, but SELinux or other security modules might interfere.
    *   The `Dockerfile` attempts to `chown` the directory to `www-data`.
*   **Connection Refused:**
    *   Ensure the `pdf-converter` service is running: `docker-compose ps`.
    *   Check the port mapping in `docker-compose.yml` and ensure you're accessing the correct host port.
    *   Check service logs: `docker-compose logs pdf-converter`.

## License

This project is licensed under the [MIT License](https://opensource.org/licenses/MIT).