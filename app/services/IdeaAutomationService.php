<?php
// app/services/IdeaAutomationService.php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use setasign\Fpdi\Tcpdf\Fpdi;

class IdeaAutomationService {

    private $client;

    public function __construct() {
        $this->client = new Client([
            'base_uri' => 'https://api.groq.com/openai/v1/',
            'timeout' => 90.0,
            'connect_timeout' => 5.0,
        ]);
    }

    private function normalizeSex(string $value): string {
        $normalized = mb_strtolower(trim($value), 'UTF-8');

        if (in_array($normalized, ['mujer', 'femenino', 'f', 'm', 'female'], true)) {
            return 'Mujer';
        }

        if (in_array($normalized, ['hombre', 'masculino', 'h', 'male'], true)) {
            return 'Hombre';
        }

        if (in_array($normalized, ['no binario', 'no-binario', 'no binaria', 'nb'], true)) {
            return 'No binario';
        }

        return trim($value);
    }

    public function extractDataFromImage(string $imagePath, string $documentType = 'ine'): array {
        if (!in_array($documentType, ['ine', 'address'], true)) {
            throw new InvalidArgumentException("Tipo de documento no válido.");
        }
        if (!is_file($imagePath)) {
            throw new RuntimeException("El archivo de imagen no existe.");
        }
        $imageSize = filesize($imagePath);
        if ($imageSize === false || $imageSize > 15 * 1024 * 1024) {
            throw new RuntimeException("La imagen excede el límite de 15 MB.");
        }

        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false || !in_array($imageInfo['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException("El archivo debe ser una imagen JPG, PNG o WEBP.");
        }

        $apiKey = getenv('GROQ_API_KEY');
        if ($apiKey === false || trim($apiKey) === '') {
            $apiKey = \Base::instance()->get('GROQ_API_KEY');
        }

        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException("No está configurada la clave de la API de Groq.");
        }

        $imageContents = file_get_contents($imagePath);
        if ($imageContents === false) {
            throw new RuntimeException("No se pudo leer la imagen para enviarla a la API de IA.");
        }

        $sourceImage = imagecreatefromstring($imageContents);
        if ($sourceImage === false) {
            throw new RuntimeException("No se pudo preparar la imagen para enviarla a la API de IA.");
        }

        $scale = min(1, 2400 / max(imagesx($sourceImage), imagesy($sourceImage)));
        $width = max(1, (int) round(imagesx($sourceImage) * $scale));
        $height = max(1, (int) round(imagesy($sourceImage) * $scale));
        $preparedImage = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($preparedImage, 255, 255, 255);
        imagefill($preparedImage, 0, 0, $background);
        imagecopyresampled(
            $preparedImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($sourceImage),
            imagesy($sourceImage)
        );
        ob_start();
        $encodedSuccessfully = imagejpeg($preparedImage, null, 85);
        $preparedImageContents = ob_get_clean();
        imagedestroy($sourceImage);
        imagedestroy($preparedImage);

        if (!$encodedSuccessfully || $preparedImageContents === false || $preparedImageContents === '') {
            throw new RuntimeException("No se pudo optimizar la imagen para la API de IA.");
        }

        $fields = [
            'nombre',
            'apellido_paterno',
            'apellido_materno',
            'curp',
            'domicilio',
            'sexo',
            'fecha_nacimiento',
            'vialidad',
            'numero_domicilio',
            'colonia',
            'cp',
            'entre_calles',
            'localidad',
            'municipio',
            'estado',
        ];
        $fieldList = implode(', ', $fields);
        $documentDescription = $documentType === 'ine'
            ? 'solo la cara frontal de una credencial para votar mexicana (INE)'
            : 'un comprobante de domicilio mexicano';
        $prompt = "/no_think Lee {$documentDescription}. Extrae solo datos claramente visibles; no inventes ni corrijas texto dudoso. Responde únicamente como JSON con estas claves: {$fieldList}. Usa cadenas vacías si un dato no es visible. Conserva nombres y apellidos; fecha DD/MM/AAAA. No infieras información que estaría en el reverso.";

        try {
            $response = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . trim($apiKey),
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'model' => 'qwen/qwen3.8-27b',
                    'temperature' => 0,
                    'max_completion_tokens' => 500,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:image/jpeg;base64,' . base64_encode($preparedImageContents),
                                ],
                            ],
                        ],
                    ]],
                ],
            ]);

            $apiResponse = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $content = $apiResponse['choices'][0]['message']['content'] ?? null;
            if (!is_string($content) || trim($content) === '') {
                throw new UnexpectedValueException("La API de Groq devolvió una respuesta vacía.");
            }

            $extractedData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($extractedData)) {
                throw new UnexpectedValueException("La API de Groq no devolvió un objeto JSON.");
            }

            $validatedData = [];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $extractedData) || !is_string($extractedData[$field])) {
                    throw new UnexpectedValueException("La API de Groq devolvió un campo inválido: {$field}.");
                }
                $validatedData[$field] = trim($extractedData[$field]);
            }

            if ($validatedData['sexo'] !== '') {
                $validatedData['sexo'] = $this->normalizeSex($validatedData['sexo']);
            }
            return $validatedData;
        } catch (GuzzleException $e) {
            $response = $e instanceof RequestException ? $e->getResponse() : null;
            $statusCode = $response !== null ? $response->getStatusCode() : null;
            if ($statusCode === 401 || $statusCode === 403) {
                $message = "La API de Groq rechazó la clave de acceso. Verifica su configuración.";
            } elseif ($statusCode === 413) {
                $message = "La API rechazó la imagen por su tamaño. Toma una foto más cercana y enfocada.";
            } elseif ($statusCode === 429) {
                $message = "La API de Groq está ocupada o alcanzó su límite temporal. Espera un momento e inténtalo de nuevo.";
            } elseif ($statusCode !== null) {
                $message = "La API de Groq no pudo procesar la imagen (HTTP {$statusCode}). Inténtalo de nuevo.";
            } elseif (preg_match('/timed?\s*out|timeout|timed out/i', $e->getMessage())) {
                $message = "La API de Groq tardó demasiado en leer la imagen. Toma una foto más cercana y enfocada e inténtalo de nuevo.";
            } else {
                $message = "No se pudo conectar con la API de Groq. Verifica la conexión a Internet e inténtalo de nuevo.";
            }
            throw new RuntimeException($message, 0, $e);
        } catch (JsonException $e) {
            throw new RuntimeException("La API de Groq devolvió una respuesta JSON inválida.", 0, $e);
        }
    }

    public function previewPDF(array $data): string {
        $pdf = $this->buildPDF($data);
        return $pdf->Output('', 'S');
    }

    public function developerPreviewVersion(): string {
        $templatePath = __DIR__ . '/../../storage/templates/formato_inea.pdf';
        $serviceHash = hash_file('sha256', __FILE__);
        $templateHash = hash_file('sha256', $templatePath);

        if ($serviceHash === false || $templateHash === false) {
            throw new Exception("No se pudo comprobar si cambió la plantilla o el mapeo del PDF.");
        }

        return hash('sha256', $serviceHash . $templateHash);
    }

    public function generatePDF(array $data): string {
        $pdf = $this->buildPDF($data);
        $fileName = 'Registro_' . ($data['curp'] ?? time()) . '.pdf';
        $outputDir = __DIR__ . '/../../storage/output/';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new Exception("No se pudo preparar el directorio de salida del PDF.");
        }

        $outputPath = $outputDir . $fileName;
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }

    private function buildPDF(array $data): Fpdi {
        if (isset($data['sexo']) && is_string($data['sexo'])) {
            $data['sexo'] = $this->normalizeSex($data['sexo']);
        }

        $pdf = new Fpdi();
        $templatePath = __DIR__ . '/../../storage/templates/formato_inea.pdf';
        
        if (!file_exists($templatePath)) {
            throw new Exception("No se encontró la plantilla en: " . $templatePath);
        }

        $pageCount = $pdf->setSourceFile($templatePath);
        $pdf->SetAutoPageBreak(false);

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $templateId = $pdf->importPage($pageNumber);
            $templateSize = $pdf->getTemplateSize($templateId);

            $pdf->AddPage(
                $templateSize['orientation'],
                [$templateSize['width'], $templateSize['height']]
            );
            $pdf->useTemplate($templateId);
        }

        $pdf->SetPage(1);

        // ==========================================
        // FUNCIONES AUXILIARES DE INYECCIÓN
        // ==========================================
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $camposNombrePropio = [
            'coordinador_zona',
            'nombre',
            'apellido_paterno',
            'apellido_materno',
            'nacionalidad',
            'pais_nacimiento',
            'entidad_nacimiento',
            'vialidad',
            'entre_calles',
            'colonia',
            'localidad',
            'municipio',
            'estado',
            'situacion_laboral_otro',
            'motivacion_otro',
            'entero_otro_texto',
        ];

        $escribir = function($x, $y, $key) use ($pdf, $data, $camposNombrePropio) {
            if (!empty($data[$key])) {
                $pdf->SetXY($x, $y);
                $valor = $data[$key];
                $texto = in_array($key, ['correo_personal', 'correo_inea'], true)
                    ? $valor
                    : (in_array($key, $camposNombrePropio, true)
                        ? mb_convert_case(mb_strtolower($valor, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
                        : mb_strtoupper($valor, 'UTF-8'));
                $pdf->Write(0, $texto);
            }
        };

        $marcar = function($x, $y, $key, $valorEsperado) use ($pdf, $data) {
            $esperado = mb_strtolower($valorEsperado, 'UTF-8');
            $valor = $data[$key] ?? '';

            if (is_array($valor)) {
                $coincide = false;
                foreach ($valor as $opcion) {
                    if (is_string($opcion) && strpos(mb_strtolower($opcion, 'UTF-8'), $esperado) !== false) {
                        $coincide = true;
                        break;
                    }
                }
            } elseif (is_string($valor)) {
                $coincide = strpos(mb_strtolower($valor, 'UTF-8'), $esperado) !== false;
            } else {
                $coincide = false;
            }

            if ($coincide) {
                $pdf->SetXY($x, $y);
                $pdf->Write(0, 'X');
            }
        };

        $marcarExacto = function($x, $y, $key, $valorEsperado) use ($pdf, $data) {
            $valor = $data[$key] ?? '';
            $coincide = is_string($valor) &&
                mb_strtolower(trim($valor), 'UTF-8') === mb_strtolower(trim($valorEsperado), 'UTF-8');

            if ($coincide) {
                $pdf->SetXY($x, $y);
                $pdf->Write(0, 'X');
            }
        };

        $escribirFecha = function($y, $key, $posicionesX) use ($pdf, $data) {
            if (!empty($data[$key])) {
                $fecha = preg_replace('/[^0-9]/', '', $data[$key]);
                if (strlen($fecha) === 8 && count($posicionesX) === 8) {
                    for ($i = 0; $i < 8; $i++) {
                        $pdf->SetXY($posicionesX[$i], $y);
                        $pdf->Write(0, $fecha[$i]);
                    }
                }
            }
        };

        $escribirCP = function($y, $key) use ($pdf, $data) {
            if (!empty($data[$key])) {
                $cp = preg_replace('/[^0-9]/', '', $data[$key]);
                if (strlen($cp) === 5) {
                    $posX = [162, 169, 177, 184, 192]; 
                    for ($i = 0; $i < 5; $i++) {
                        $pdf->SetXY($posX[$i], $y);
                        $pdf->Write(0, $cp[$i]);
                    }
                } else {
                    $pdf->SetXY(162, $y);
                    $pdf->Write(0, $cp);
                }
            }
        };

        // ==========================================
        // MAPA DE COORDENADAS COMPLETO (HOJA 1)
        // ==========================================
        
        // Coordenadas de mapeo del encabezado.
        $escribir(45, 18, 'coordinador_zona');
        $escribirFecha(18, 'fecha_registro', [139, 146, 154, 161, 169, 177, 184, 192]);

        $escribir(40, 31, 'apellido_paterno');
        $escribir(130, 31, 'apellido_materno');
        $escribir(40, 37, 'nombre');
        $escribirFecha(43, 'fecha_nacimiento', [47, 55, 63, 70, 78, 85, 93, 100]);

        $escribir(40, 55, 'nacionalidad');
        $escribir(99, 55, 'entidad_nacimiento');
        $escribir(45, 60, 'pais_nacimiento'); 
        
        $marcarExacto(140, 55, 'sexo', 'Mujer');
        $marcarExacto(157, 55, 'sexo', 'Hombre');
        $marcarExacto(181, 55, 'sexo', 'No binario');

        $marcar(38, 68, 'estado_civil', 'soltero');
        $marcar(57, 68, 'estado_civil', 'casado');
        $marcar(76, 68, 'estado_civil', 'concubino');
        $marcar(99, 68, 'estado_civil', 'separado');
        $marcar(122, 68, 'estado_civil', 'divorciado');
        $marcar(144, 68, 'estado_civil', 'viudo');
        
        $escribir(188, 68, 'hijos');

        if (mb_strtolower($data['habla_espanol'] ?? '', 'UTF-8') === 'sí') {
            $pdf->SetXY(38, 78); $pdf->Write(0, 'X');
        }
        if (!empty($data['lengua_indigena'])) {
            $pdf->SetXY(68, 78); $pdf->Write(0, 'X'); 
            $escribir(85, 78, 'lengua_indigena');     
        }
        if (mb_strtolower($data['considera_indigena'] ?? '', 'UTF-8') === 'sí') {
            $pdf->SetXY(89, 88); $pdf->Write(0, 'X');
        }
        if (mb_strtolower($data['afromexicano'] ?? '', 'UTF-8') === 'sí') {
            $pdf->SetXY(171, 88); $pdf->Write(0, 'X');
        }

        $escribir(40, 97, 'vialidad');
        $escribir(172, 96, 'numero_domicilio'); 
        $escribir(44, 116, 'colonia');
        $escribir(40, 126, 'entre_calles');
        $escribirCP(126, 'cp'); 
        
        $escribir(30, 133, 'localidad');
        $escribir(88, 133, 'municipio');
        $escribir(160, 133, 'estado');
        
        $escribir(50, 140, 'telefono_fijo');
        $escribir(130, 140, 'celular');

        $acceso = mb_strtolower($data['acceso_internet'] ?? '', 'UTF-8');
        if (strpos($acceso, 'ambos') !== false || strpos($acceso, 'cómputo') !== false) {
            $pdf->SetXY(62, 149.5); $pdf->Write(0, 'X');
        }
        if (strpos($acceso, 'ambos') !== false || strpos($acceso, 'internet') !== false) {
            $pdf->SetXY(62, 155); $pdf->Write(0, 'X');
        }
        $escribir(120, 149, 'correo_personal');
        $escribir(120, 155, 'correo_inea');

        $marcar(60.5, 163, 'discapacidad', 'no');
        $marcar(72, 163, 'discapacidad', 'sí');

        $marcar(18, 168, 'tipo_discapacidad', 'física');
        $marcar(68, 168, 'tipo_discapacidad', 'intelectual');
        $marcar(118, 168, 'tipo_discapacidad', 'psicosocial');
        
        $marcar(53, 174, 'tipo_discapacidad', 'baja visión');
        $marcar(87, 174, 'tipo_discapacidad', 'ciega');
        // if (strpos(json_encode($data['tipo_discapacidad'] ?? []), 'Visual') !== false) {
        //     $pdf->SetXY(20, 196); $pdf->Write(0, 'X'); 
        // }

        $marcar(53, 179, 'tipo_discapacidad', 'leve');
        $marcar(110, 179, 'tipo_discapacidad', 'sorda');
        // if (strpos(json_encode($data['tipo_discapacidad'] ?? []), 'Auditiva') !== false) {
        //     $pdf->SetXY(20, 203); $pdf->Write(0, 'X'); 
        // }

        $marcarExacto(18, 191, 'trabaja', 'No');
        $marcarExacto(18, 202, 'trabaja', 'Sí');

        if (mb_strtolower($data['trabaja'] ?? '', 'UTF-8') === 'no') {
            $marcarExacto(45, 191, 'situacion_laboral', 'Jubilado/a o pensionado/a');
            $marcarExacto(83, 191, 'situacion_laboral', 'Desempleado/a');
            $marcarExacto(114, 191, 'situacion_laboral', 'Estudiante');
            if (mb_strtolower($data['situacion_laboral'] ?? '', 'UTF-8') === 'otro') {
                $escribir(150, 190, 'situacion_laboral_otro');
            }
        }

        if (mb_strtolower($data['trabaja'] ?? '', 'UTF-8') === 'sí') {
            $marcarExacto(42, 202, 'ocupacion', 'Trabajador/a agropecuario');
            $marcarExacto(64, 202, 'ocupacion', 'Inspector/a');
            $marcarExacto(87, 202, 'ocupacion', 'Artesano/a');
            $marcarExacto(106, 202, 'ocupacion', 'Obrero/a');
            $marcarExacto(122, 202, 'ocupacion', 'Ayudante');
            $marcarExacto(140, 202, 'ocupacion', 'Empleado/a de gobierno');
            $marcarExacto(163, 202, 'ocupacion', 'Operador/a de transporte');
            $marcarExacto(42, 211, 'ocupacion', 'Comerciante');
            $marcarExacto(80, 211, 'ocupacion', 'Trabajador/a del hogar');
            $marcarExacto(103, 211, 'ocupacion', 'Vigilancia');
            $marcarExacto(126, 211, 'ocupacion', 'Quehaceres del hogar');
            $marcarExacto(148, 211, 'ocupacion', 'Trabajador/a ambulante');
            $marcarExacto(175, 211, 'ocupacion', 'Deportista');
        }

        if ($pageCount >= 2) {
            $pdf->SetPage(2);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(0, 0, 0);
        }

        $marcar(53, 7, 'antecedentes', 'sin estudios');
        $marcar(76, 7, 'antecedentes', 'primaria');
        $marcar(122, 7, 'antecedentes', 'secundaria');

    // Si capturas el grado de primaria o secundaria, ajusta los nombres de tus variables:
        $escribir(100, 7, 'grado_primaria'); 
        $escribir(150, 7, 'grado_secundaria');

        // --- Proceso o nivel al que ingresa ---
        $marcarExacto(53, 13, 'nivel_ingreso', 'Alfabetización');
        $marcarExacto(76, 13, 'nivel_ingreso', 'Primaria');
        $marcarExacto(99, 13, 'nivel_ingreso', 'Secundaria');
        $marcarExacto(122, 13, 'nivel_ingreso', 'Alfabetización indígena');
        $escribir(185, 13, 'etnia_lengua');

        // --- Servicio educativo seleccionado ---
        $marcar(56, 36, 'servicio_educativo', 'diagnóstico');
        $marcar(95, 36, 'servicio_educativo', 'saberes');
        $marcar(140, 36, 'servicio_educativo', 'atención');

        // --- Subproyecto y Dependencia ---
        $escribir(32, 27, 'subproyecto');
        $escribir(139, 27, 'dependencia');

        // --- Documentos Probatorios ---
        $marcar(20, 39, 'documentos_probatorios', 'fotografía');
        $marcar(44, 39, 'documentos_probatorios', 'documento legal');
        $marcar(20, 47, 'documentos_probatorios', 'ficha'); // CERESO
        $marcar(86, 47, 'documentos_probatorios', 'certificado de primaria');
        $marcar(112, 39, 'documentos_probatorios', 'boletas de primaria');
        $marcar(112, 47, 'documentos_probatorios', 'boletas de secundaria');
        $escribir(140, 39, 'grado_boletas_primaria');
        $escribir(140, 47, 'grado_boletas_secundaria');
        
        // --- Motivación ---
        $marcar(34, 57, 'motivacion', 'obtener certificado');
        $marcar(65, 57, 'motivacion', 'continuar educación');
        $marcar(95, 57, 'motivacion', 'empleo');
        $marcar(110, 57, 'motivacion', 'condiciones');
        $marcar(137, 57, 'motivacion', 'ayudar');
        $marcar(164, 57, 'motivacion', 'superación');
        $marcar(182, 57, 'motivacion', 'otro');
        $escribir(180, 60, 'motivacion_otro');

        // --- ¿Cómo se enteró? ---
        $marcar(63, 100, 'entero', 'internet');
        $marcar(87, 100, 'entero', 'cartel');
        $marcar(118, 100, 'entero', 'invitación');
        $marcar(151, 100, 'entero', 'radio');
        $marcar(171, 100, 'entero', 'otro');
        $escribir(185, 100, 'entero_otro_texto');

        // --- Unidad Operativa ---
        $escribir(40, 78, 'unidad_operativa');
        $escribir(130, 78, 'circulo_estudio');
       
        return $pdf;
    }
}