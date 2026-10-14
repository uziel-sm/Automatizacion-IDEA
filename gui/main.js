import './style.css';
import Alpine from 'alpinejs';

const today = new Date();
const formatRegistrationDate = () => [
    String(today.getDate()).padStart(2, '0'),
    String(today.getMonth() + 1).padStart(2, '0'),
    today.getFullYear()
].join('/');

const createFormData = () => ({
    coordinador_zona: '',
    fecha_registro: formatRegistrationDate(),
    nombre: '',
    apellido_paterno: '',
    apellido_materno: '',
    curp: '',
    domicilio: '',
    sexo: '',
    fecha_nacimiento: '',
    nacionalidad: 'Mexicana',
    pais_nacimiento: '',
    entidad_nacimiento: '',
    estado_civil: 'Soltero/a',
    hijos: '0',
    habla_espanol: 'Sí',
    lengua_indigena: '',
    considera_indigena: 'No',
    afromexicano: 'No',
    vialidad: '',
    numero_domicilio: '',
    colonia: '',
    cp: '',
    entre_calles: '',
    localidad: '',
    municipio: '',
    estado: '',
    celular: '',
    telefono_fijo: '',
    correo_personal: '',
    acceso_internet: 'Ninguno',
    discapacidad: 'No',
    tipo_discapacidad: [],
    trabaja: 'No',
    situacion_laboral: 'Desempleado/a',
    situacion_laboral_otro: '',
    ocupacion: '',
    antecedentes: 'Sin estudios',
    grado_primaria: '',
    grado_secundaria: '',
    nivel_ingreso: '',
    subproyecto: '',
    dependencia: '',
    documentos_probatorios: [],
    grado_boletas_primaria: '',
    grado_boletas_secundaria: '',
    motivacion: 'Superación personal',
    motivacion_otro: '',
    unidad_operativa: '',
    circulo_estudio: ''
});

const optimizeDocumentImage = async file => {
    const image = await createImageBitmap(file);
    try {
        const scale = Math.min(1, 1800 / Math.max(image.width, image.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.width * scale));
        canvas.height = Math.max(1, Math.round(image.height * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('No se pudo preparar la foto para enviarla.');
        context.imageSmoothingQuality = 'high';
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
        if (!blob) throw new Error('No se pudo comprimir la foto.');
        return new File([blob], `${file.name.replace(/\.[^.]+$/, '') || 'documento'}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now()
        });
    } finally {
        image.close();
    }
};

document.addEventListener('alpine:init', () => {
    Alpine.data('ideaUploader', () => ({
        showValidationForm: false,
        showPreview: false,
        step: 1,
        previewPhase: 1,
        isGenerating: false,
        editingPreviewKey: '',
        previewEditOriginal: null,
        formData: createFormData(),
        documentStatus: {
            ine: { state: 'idle', message: '' },
            address: { state: 'idle', message: '' }
        },

        getPreviewFields() {
            return [
                { key: 'coordinador_zona', label: 'Coordinador(a) de zona' },
                { key: 'fecha_registro', label: 'Fecha de registro' },
                { key: 'nombre', label: 'Nombre(s)' },
                { key: 'apellido_paterno', label: 'Apellido paterno' },
                { key: 'apellido_materno', label: 'Apellido materno' },
                { key: 'curp', label: 'CURP' },
                { key: 'fecha_nacimiento', label: 'Fecha de nacimiento' },
                { key: 'nacionalidad', label: 'Nacionalidad' },
                { key: 'pais_nacimiento', label: 'País de nacimiento' },
                { key: 'entidad_nacimiento', label: 'Entidad de nacimiento' },
                { key: 'sexo', label: 'Sexo', options: ['Mujer', 'Hombre', 'No binario'] },
                { key: 'estado_civil', label: 'Estado civil', options: ['Soltero/a', 'Casado/a', 'Concubino/a', 'Separado/a', 'Divorciado/a', 'Viudo/a'] },
                { key: 'hijos', label: 'Número de hijos', inputType: 'number' },
                { key: 'habla_espanol', label: '¿Habla español?', options: ['Sí', 'No'] },
                { key: 'lengua_indigena', label: 'Lengua indígena' },
                { key: 'considera_indigena', label: '¿Se considera indígena?', options: ['Sí', 'No'] },
                { key: 'afromexicano', label: '¿Se considera afromexicano(a)?', options: ['Sí', 'No'] },
                { key: 'vialidad', label: 'Vialidad' },
                { key: 'numero_domicilio', label: 'Número de domicilio' },
                { key: 'colonia', label: 'Colonia' },
                { key: 'cp', label: 'Código postal' },
                { key: 'entre_calles', label: 'Entre calles' },
                { key: 'localidad', label: 'Localidad' },
                { key: 'municipio', label: 'Municipio' },
                { key: 'estado', label: 'Estado' },
                { key: 'telefono_fijo', label: 'Teléfono fijo' },
                { key: 'celular', label: 'Celular' },
                { key: 'correo_personal', label: 'Correo electrónico', inputType: 'email' },
                { key: 'acceso_internet', label: 'Acceso a internet/cómputo', options: ['Ambos', 'Solo Internet', 'Solo Cómputo', 'Ninguno'] },
                { key: 'discapacidad', label: 'Discapacidad', options: ['No', 'Sí'] },
                { key: 'tipo_discapacidad', label: 'Tipo de discapacidad', multiple: true, options: ['Física', 'Intelectual', 'Psicosocial', 'Visual (Baja visión)', 'Visual (Ciega)', 'Auditiva (Leve/Severa)', 'Auditiva (Sorda)'] },
                { key: 'trabaja', label: '¿Tiene trabajo activo?', options: ['No', 'Sí'] },
                { key: 'situacion_laboral', label: 'Situación laboral', options: ['Jubilado/a o pensionado/a', 'Desempleado/a', 'Estudiante', 'Otro'] },
                { key: 'situacion_laboral_otro', label: 'Otra situación laboral' },
                { key: 'ocupacion', label: 'Ocupación', options: ['Trabajador/a agropecuario', 'Inspector/a', 'Artesano/a', 'Obrero/a', 'Ayudante', 'Empleado/a de gobierno', 'Operador/a de transporte', 'Comerciante', 'Trabajador/a del hogar', 'Vigilancia', 'Quehaceres del hogar', 'Trabajador/a ambulante', 'Deportista'] },
                { key: 'antecedentes', label: 'Antecedentes escolares', options: ['Sin estudios', 'Primaria', 'Secundaria'] },
                { key: 'grado_primaria', label: 'Grado de primaria', options: ['', '1', '2', '3', '4', '5', '6'] },
                { key: 'grado_secundaria', label: 'Grado de secundaria', options: ['', '1', '2', '3'] },
                { key: 'nivel_ingreso', label: 'Nivel al que ingresa', options: ['', 'Alfabetización', 'Primaria', 'Secundaria', 'Alfabetización indígena'] },
                { key: 'subproyecto', label: 'Subproyecto' },
                { key: 'dependencia', label: 'Dependencia' },
                { key: 'documentos_probatorios', label: 'Documentación y probatorios', multiple: true, options: ['Fotografía', 'Documento legal equivalente (extranjeros)', 'Ficha signalética (CERESO)', 'Certificado de primaria', 'Boletas de primaria', 'Boletas de secundaria'] },
                { key: 'grado_boletas_primaria', label: 'Grado de boletas de primaria', options: ['', '1', '2', '3', '4', '5', '6'] },
                { key: 'grado_boletas_secundaria', label: 'Grado de boletas de secundaria', options: ['', '1', '2', '3'] },
                { key: 'motivacion', label: 'Motivación', options: ['Obtener certificado', 'Continuar educación', 'Empleo', 'Mejorar condiciones', 'Ayudar a familia', 'Superación personal', 'Otro'] },
                { key: 'motivacion_otro', label: 'Otra motivación' },
                { key: 'unidad_operativa', label: 'Unidad operativa' },
                { key: 'circulo_estudio', label: 'Círculo de estudio' }
            ];
        },

        startForm() {
            this.showValidationForm = true;
            this.showPreview = false;
            this.step = 1;
        },

        openPreview() {
            this.previewPhase = 1;
            this.showPreview = true;
        },

        getPreviewPhases() {
            return [
                {
                    title: 'Datos generales',
                    fields: [
                        'coordinador_zona', 'fecha_registro', 'nombre', 'apellido_paterno',
                        'apellido_materno', 'curp', 'fecha_nacimiento', 'nacionalidad',
                        'pais_nacimiento', 'entidad_nacimiento', 'sexo', 'estado_civil',
                        'hijos', 'habla_espanol', 'lengua_indigena', 'considera_indigena',
                        'afromexicano', 'correo_personal'
                    ]
                },
                {
                    title: 'Domicilio y datos de contacto',
                    fields: [
                        'vialidad', 'numero_domicilio', 'colonia', 'cp', 'entre_calles',
                        'localidad', 'municipio', 'estado', 'telefono_fijo', 'celular',
                        'acceso_internet'
                    ]
                },
                {
                    title: 'Salud',
                    fields: ['discapacidad', 'tipo_discapacidad']
                },
                {
                    title: 'Ocupación',
                    fields: [
                        'trabaja', 'situacion_laboral', 'situacion_laboral_otro', 'ocupacion'
                    ]
                },
                {
                    title: 'Información académica',
                    fields: [
                        'antecedentes', 'grado_primaria', 'grado_secundaria', 'nivel_ingreso',
                        'subproyecto', 'dependencia', 'documentos_probatorios',
                        'grado_boletas_primaria', 'grado_boletas_secundaria', 'motivacion',
                        'motivacion_otro', 'unidad_operativa', 'circulo_estudio'
                    ]
                }
            ];
        },

        getPreviewPhaseTitle() {
            return this.getPreviewPhases()[this.previewPhase - 1].title;
        },

        getPreviewPhaseFields() {
            const phaseFields = this.getPreviewPhases()[this.previewPhase - 1].fields;
            return this.getPreviewFields().filter(field => phaseFields.includes(field.key));
        },

        changePreviewPhase(direction) {
            const nextPhase = this.previewPhase + direction;
            if (nextPhase >= 1 && nextPhase <= this.getPreviewPhases().length) {
                this.previewPhase = nextPhase;
            }
        },

        closePreview() {
            this.showPreview = false;
            this.previewPhase = 1;
            this.editingPreviewKey = '';
            this.previewEditOriginal = null;
        },

        startPreviewEdit(field) {
            const value = this.formData[field.key];
            this.previewEditOriginal = Array.isArray(value) ? [...value] : value;
            this.editingPreviewKey = field.key;
        },

        cancelPreviewEdit(field) {
            this.formData[field.key] = Array.isArray(this.previewEditOriginal)
                ? [...this.previewEditOriginal]
                : this.previewEditOriginal;
            this.editingPreviewKey = '';
            this.previewEditOriginal = null;
        },

        savePreviewEdit() {
            this.editingPreviewKey = '';
            this.previewEditOriginal = null;
        },

        formatPreviewValue(value) {
            if (Array.isArray(value)) return value.join(', ') || '—';
            return value === '' || value === null || value === undefined ? '—' : value;
        },

        confirmPreview() {
            this.closePreview();
            this.submitForm();
        },

        getGenerationPayload() {
            return {
                ...this.formData,
                domicilio: [
                    this.formData.vialidad,
                    this.formData.numero_domicilio,
                    this.formData.colonia,
                    this.formData.cp,
                    this.formData.localidad,
                    this.formData.municipio,
                    this.formData.estado
                ].filter(Boolean).join(', ') || this.formData.domicilio
            };
        },

        async extractDocument(type, event) {
            const file = event.target.files[0];
            if (!file) return;

            try {
                await this.processDocument(type, file);
            } finally {
                event.target.value = '';
            }
        },

        async processDocument(type, file) {
            const status = this.documentStatus[type];
            status.state = 'processing';
            try {
                status.message = 'Optimizando foto para lectura...';
                const optimizedFile = await optimizeDocumentImage(file);
                status.message = 'Leyendo documento con IA...';
                const payload = new FormData();
                payload.append('document', optimizedFile);
                payload.append('document_type', type);

                const response = await fetch('/api/idea/extract', {
                    method: 'POST',
                    body: payload
                });
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (error) {
                    console.error('Respuesta inválida del controlador PHP:', responseText);
                    const detail = responseText.trim() ? 'no válida' : 'vacía';
                    throw new Error(`El controlador PHP devolvió una respuesta ${detail} (HTTP ${response.status}).`);
                }

                if (!response.ok || result.status !== 'success' || !result.data) {
                    throw new Error(result.message || 'No se pudieron extraer los datos.');
                }

                const extractedData = Object.fromEntries(
                    Object.entries(result.data).filter(([key, value]) =>
                        Object.hasOwn(this.formData, key) &&
                        typeof value === 'string' &&
                        value.trim() !== ''
                    )
                );
                this.formData = { ...this.formData, ...extractedData };
                status.state = 'success';
                status.message = 'Datos extraídos. Revisa y corrige los campos si es necesario.';
            } catch (error) {
                console.error(`Error al procesar documento (${type}):`, error);
                status.state = 'error';
                status.message = error.message || 'Ocurrió un error al conectar con el servidor.';
            }
        },

        resetUpload() {
            this.showValidationForm = false;
            this.closePreview();
            this.step = 1;
            this.isGenerating = false;
            this.formData = createFormData();
            this.documentStatus = {
                ine: { state: 'idle', message: '' },
                address: { state: 'idle', message: '' }
            };
            this.$refs.ineInput.value = '';
            this.$refs.addressInput.value = '';
        },
        
        async submitForm() {
            if (this.isGenerating) return;

            this.isGenerating = true;

            try {
                const response = await fetch('/api/idea/generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.getGenerationPayload())
                });

                const contentType = response.headers.get('content-type') || '';
                if (response.ok && contentType.toLowerCase().includes('application/pdf')) {
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `Registro_INEA_${this.formData.curp || 'Nuevo'}.pdf`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                    
                    alert("¡PDF planchado y descargado con éxito!");
                    this.resetUpload();
                } else {
                    const responseText = await response.text();
                    let message = 'El servidor no devolvió un PDF válido.';

                    try {
                        const result = JSON.parse(responseText);
                        message = result.message || message;
                    } catch (error) {
                        console.error('Respuesta inesperada del servidor:', responseText);
                    }

                    alert('Error al generar el PDF: ' + message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ocurrió un error al enviar los datos al servidor.');
            } finally {
                this.isGenerating = false;
            }
        }
    }));
});

window.Alpine = Alpine;
Alpine.start();