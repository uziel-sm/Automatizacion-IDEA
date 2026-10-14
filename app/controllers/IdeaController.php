<?php
// app/controllers/IdeaController.php

class IdeaController {
    
    public function ping($f3) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Entorno IDEA aislado corriendo correctamente.',
            'php_version' => PHP_VERSION,
            'f3_version' => $f3->get('VERSION')
        ]);
    }

    /**
     * Endpoint POST /api/idea/extract
     */
    public function extractData($f3) {
        header('Content-Type: application/json');

        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No se pudo recibir el documento.']);
            return;
        }

        $documentType = $_POST['document_type'] ?? 'ine';
        if (!in_array($documentType, ['ine', 'address'], true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Tipo de documento no válido.']);
            return;
        }

        $file = $_FILES['document'];
        $uploadDir = __DIR__ . '/../../storage/uploads/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'No se pudo preparar el almacenamiento temporal.']);
            return;
        }

        $tempPath = $uploadDir . bin2hex(random_bytes(16));
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar el archivo temporal.']);
            return;
        }

        try {
            $service = new IdeaAutomationService();
            $extractedData = $service->extractDataFromImage($tempPath, $documentType);

            // Eliminar archivo temporal
            @unlink($tempPath);

            echo json_encode([
                'status' => 'success',
                'data' => $extractedData
            ]);

        } catch (Exception $e) {
            @unlink($tempPath);
            $statusCode = $e->getPrevious() instanceof \GuzzleHttp\Exception\GuzzleException ? 502 : 500;
            http_response_code($statusCode);
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Endpoint POST /api/idea/generate
     */
    public function generatePdf($f3) {
        $data = json_decode($f3->get('BODY'), true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Datos inválidos.']);
            return;
        }

        try {
            $service = new IdeaAutomationService();
            $pdfPath = $service->generatePDF($data);

            // 1. Limpiar cualquier texto o espacio invisible en el buffer de PHP
            if (ob_get_length()) {
                ob_end_clean();
            }

            // 2. Forzar la descarga
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . basename($pdfPath) . '"');
            header('Content-Length: ' . filesize($pdfPath));
            
            readfile($pdfPath);
             
            // 3. MUY IMPORTANTE: Detener la ejecución para evitar que F3 agregue datos extra
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Endpoint POST /api/idea/preview
     */
    public function previewPdf($f3) {
        $data = json_decode($f3->get('BODY'), true);

        if (!$data) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Datos inválidos.']);
            return;
        }

        try {
            $service = new IdeaAutomationService();
            $pdfContents = $service->previewPDF($data);

            if (ob_get_length()) {
                ob_end_clean();
            }

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="vista-previa-desarrollador.pdf"');
            header('Content-Length: ' . strlen($pdfContents));
            echo $pdfContents;
            exit;
        } catch (\Throwable $e) {
            if (ob_get_length()) {
                ob_end_clean();
            }

            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Endpoint GET /api/idea/preview-version
     */
    public function previewVersion($f3) {
        try {
            $service = new IdeaAutomationService();
            header('Content-Type: application/json');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo json_encode([
                'status' => 'success',
                'version' => $service->developerPreviewVersion()
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}