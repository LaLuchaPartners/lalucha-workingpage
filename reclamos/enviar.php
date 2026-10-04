<?php
/* public/reclamos/enviar.php
 *
 * Recibe el formulario del Libro de Reclamaciones, asigna el correlativo (LR-XXXX),
 * guarda el reclamo y envía:
 *   1. el reclamo a los correos de Lucha Partners (DESTINATARIOS)
 *   2. una constancia al correo del consumidor
 *
 * Ambos correos van con copia oculta a ARCHIVO, cuya bandeja funciona como archivo del libro.
 *
 * La configuración SMTP vive fuera del repositorio, en reclamos-config.php
 * (ver server/reclamos-config.example.php).
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const PREFIJO = 'LR';
const PRIMER_NUMERO = 2779;
const DESTINATARIOS = ['admin@lalucha.com.pe', 'atencionclientes@lalucha.com.pe'];
const ARCHIVO = 'librodereclamaciones@lalucha.com.pe';
const MAX_IMAGEN_BYTES = 5 * 1024 * 1024;
const TIPOS_IMAGEN = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

// Campos del formulario agrupados como en la hoja de reclamación.
const SECCIONES = [
    'Datos del local' => [
        'marca' => 'Marca',
        'ubicacion' => 'Ubicación',
        'direccion' => 'Dirección',
    ],
    'Datos del consumidor' => [
        'nombres' => 'Nombres',
        'apellidos' => 'Apellidos',
        'distrito' => 'Distrito',
        'direccion_consumidor' => 'Dirección',
        'email' => 'Email',
        'telefono' => 'Teléfono',
        'tipo_documento' => 'Documento de identidad',
        'numero_documento' => 'Número de documento',
    ],
    'Identificación del bien contratado' => [
        'bien_contratado' => 'Bien contratado',
        'monto' => 'Monto reclamado (S/)',
        'descripcion_bien' => 'Descripción del bien contratado',
    ],
    'Detalle del reclamo' => [
        'tipo' => 'Tipo',
        'fecha' => 'Fecha',
        'medio_atencion' => '¿Por qué medio fue atendido?',
        'detalle' => 'Detalle',
        'pedido' => 'Pedido del cliente',
    ],
];
const CAMPOS_LARGOS = ['detalle', 'pedido'];
const OPCIONES = [
    'tipo_documento' => ['DNI', 'CE', 'Pasaporte', 'RUC', 'Otro'],
    'bien_contratado' => ['Producto', 'Servicio'],
    'tipo' => ['Queja', 'Reclamo'],
    'medio_atencion' => ['Salón', 'Para llevar'],
];

function responder(int $codigo, array $cuerpo): void
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

function registrarError(string $dir, string $mensaje): void
{
    @file_put_contents($dir . '/errores.log', '[' . date('c') . '] ' . $mensaje . PHP_EOL, FILE_APPEND);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

// ---------- Configuración ----------

$raiz = dirname(__DIR__, 2); // carpeta que contiene public_html
$rutaConfig = getenv('RECLAMOS_CONFIG') ?: $raiz . '/reclamos-config.php';
$config = is_file($rutaConfig) ? require $rutaConfig : [];
$dirDatos = $config['data_dir'] ?? $raiz . '/reclamos-data';

foreach ([$dirDatos, $dirDatos . '/reclamos', $dirDatos . '/imagenes'] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        responder(500, ['ok' => false, 'mensaje' => 'No se pudo registrar el reclamo. Inténtalo más tarde.']);
    }
}

// ---------- Validación ----------

// Campo trampa para bots: los usuarios no lo ven.
if (!empty($_POST['sitio_web'])) {
    responder(400, ['ok' => false, 'mensaje' => 'Solicitud no válida.']);
}

$datos = [];
$errores = [];
foreach (SECCIONES as $campos) {
    foreach ($campos as $campo => $etiqueta) {
        $valor = trim((string) ($_POST[$campo] ?? ''));
        $max = in_array($campo, CAMPOS_LARGOS, true) ? 5000 : 200;
        if ($valor === '') {
            $errores[] = $etiqueta;
        } elseif (mb_strlen($valor) > $max) {
            $errores[] = $etiqueta . ' (demasiado largo)';
        }
        $datos[$campo] = $valor;
    }
}

if ($errores) {
    responder(422, ['ok' => false, 'mensaje' => 'Revisa los siguientes campos: ' . implode(', ', $errores) . '.']);
}

foreach (OPCIONES as $campo => $permitidos) {
    if (!in_array($datos[$campo], $permitidos, true)) {
        $errores[] = SECCIONES['Datos del consumidor'][$campo]
            ?? SECCIONES['Identificación del bien contratado'][$campo]
            ?? SECCIONES['Detalle del reclamo'][$campo];
    }
}

if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'Email';
}

if (!is_numeric($datos['monto']) || (float) $datos['monto'] < 0) {
    $errores[] = 'Monto reclamado';
} else {
    $datos['monto'] = number_format((float) $datos['monto'], 2, '.', '');
}

$fecha = DateTime::createFromFormat('!Y-m-d', $datos['fecha']);
if (!$fecha || $fecha->format('Y-m-d') !== $datos['fecha'] || $fecha > new DateTime('today')) {
    $errores[] = 'Fecha';
}

$tiendas = json_decode((string) @file_get_contents(__DIR__ . '/tiendas.json'), true) ?: [];
$localValido = false;
foreach ($tiendas as $marca) {
    if ($marca['nombre'] !== $datos['marca']) continue;
    foreach ($marca['ubicaciones'] as $ubicacion) {
        if ($ubicacion['nombre'] === $datos['ubicacion'] && in_array($datos['direccion'], $ubicacion['direcciones'], true)) {
            $localValido = true;
        }
    }
}
if (!$localValido) {
    $errores[] = 'Datos del local';
}

if ($errores) {
    responder(422, ['ok' => false, 'mensaje' => 'Revisa los siguientes campos: ' . implode(', ', $errores) . '.']);
}

// Imagen opcional
$imagen = null;
$archivo = $_FILES['imagen'] ?? null;
if ($archivo && $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($archivo['error'] !== UPLOAD_ERR_OK || $archivo['size'] > MAX_IMAGEN_BYTES) {
        responder(422, ['ok' => false, 'mensaje' => 'La imagen no debe superar los 5 MB.']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if (!isset(TIPOS_IMAGEN[$mime])) {
        responder(422, ['ok' => false, 'mensaje' => 'La imagen debe ser JPG, PNG, WEBP, GIF o HEIC.']);
    }
    $imagen = ['tmp' => $archivo['tmp_name'], 'extension' => TIPOS_IMAGEN[$mime]];
}

// ---------- Correlativo y registro ----------

$contador = fopen($dirDatos . '/contador.txt', 'c+');
if (!$contador || !flock($contador, LOCK_EX)) {
    responder(500, ['ok' => false, 'mensaje' => 'No se pudo registrar el reclamo. Inténtalo más tarde.']);
}

$ultimo = (int) stream_get_contents($contador);
$numero = max(PRIMER_NUMERO, $ultimo + 1);
$codigo = PREFIJO . '-' . $numero;
$registrado = date('Y-m-d H:i:s');

if ($imagen) {
    $imagen['ruta'] = $dirDatos . '/imagenes/' . $codigo . '.' . $imagen['extension'];
    if (!move_uploaded_file($imagen['tmp'], $imagen['ruta'])) {
        flock($contador, LOCK_UN);
        responder(500, ['ok' => false, 'mensaje' => 'No se pudo guardar la imagen. Inténtalo nuevamente.']);
    }
}

$registro = [
    'numero' => $codigo,
    'registrado' => $registrado,
    'datos' => $datos,
    'imagen' => $imagen ? basename($imagen['ruta']) : null,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
];
$guardado = file_put_contents(
    $dirDatos . '/reclamos/' . $codigo . '.json',
    json_encode($registro, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

if ($guardado === false) {
    flock($contador, LOCK_UN);
    responder(500, ['ok' => false, 'mensaje' => 'No se pudo registrar el reclamo. Inténtalo más tarde.']);
}

ftruncate($contador, 0);
rewind($contador);
fwrite($contador, (string) $numero);
fflush($contador);
flock($contador, LOCK_UN);
fclose($contador);

// ---------- Correos ----------

function e(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function cuerpoReclamo(string $codigo, string $registrado, array $datos, string $intro): array
{
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#333;max-width:640px">';
    $html .= '<h2 style="margin:0 0 4px">Libro de Reclamaciones – Lucha Partners</h2>';
    $html .= '<p style="margin:0 0 16px;color:#666">Hoja de reclamación N° <strong style="color:#d7282f">' . e($codigo) . '</strong> · Registrada el ' . e($registrado) . '</p>';
    $html .= $intro !== '' ? '<p>' . $intro . '</p>' : '';
    $texto = "Libro de Reclamaciones – Lucha Partners\nHoja de reclamación N° $codigo · Registrada el $registrado\n\n";
    $texto .= $intro !== '' ? strip_tags($intro) . "\n\n" : '';

    foreach (SECCIONES as $seccion => $campos) {
        $html .= '<h3 style="margin:20px 0 8px;color:#d7282f;font-size:14px;text-transform:uppercase">' . e($seccion) . '</h3>';
        $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px">';
        $texto .= mb_strtoupper($seccion) . "\n";
        foreach ($campos as $campo => $etiqueta) {
            $valor = in_array($campo, CAMPOS_LARGOS, true) ? nl2br(e($datos[$campo])) : e($datos[$campo]);
            $html .= '<tr><td style="border-bottom:1px solid #eee;width:38%;color:#666;vertical-align:top">' . e($etiqueta) . '</td>'
                . '<td style="border-bottom:1px solid #eee">' . $valor . '</td></tr>';
            $texto .= "$etiqueta: {$datos[$campo]}\n";
        }
        $html .= '</table>';
        $texto .= "\n";
    }

    $html .= '<h3 style="margin:20px 0 8px;color:#d7282f;font-size:14px;text-transform:uppercase">Observaciones y acciones adoptadas por el proveedor</h3>';
    $html .= '<p style="font-size:14px;color:#666">Pendiente.</p></div>';
    $texto .= "OBSERVACIONES Y ACCIONES ADOPTADAS POR EL PROVEEDOR\nPendiente.\n";

    return [$html, $texto];
}

function crearCorreo(array $config): PHPMailer
{
    $correo = new PHPMailer(true);
    $correo->CharSet = PHPMailer::CHARSET_UTF8;
    if (!empty($config['smtp_host'])) {
        $correo->isSMTP();
        $correo->Host = $config['smtp_host'];
        $correo->Port = (int) ($config['smtp_port'] ?? 465);
        $correo->SMTPSecure = $config['smtp_secure'] ?? PHPMailer::ENCRYPTION_SMTPS;
        $correo->SMTPAuth = true;
        $correo->Username = $config['smtp_user'] ?? '';
        $correo->Password = $config['smtp_pass'] ?? '';
    }
    $correo->setFrom($config['from_email'] ?? ARCHIVO, $config['from_name'] ?? 'Lucha Partners');
    $correo->isHTML(true);
    return $correo;
}

$consumidor = $datos['nombres'] . ' ' . $datos['apellidos'];
$enviados = ['empresa' => false, 'consumidor' => false];

// 1. Aviso a Lucha Partners
try {
    [$html, $texto] = cuerpoReclamo($codigo, $registrado, $datos, '');
    $correo = crearCorreo($config);
    foreach (DESTINATARIOS as $destinatario) {
        $correo->addAddress($destinatario);
    }
    $correo->addBCC(ARCHIVO);
    $correo->addReplyTo($datos['email'], $consumidor);
    $correo->Subject = "[$codigo] Nueva {$datos['tipo']} – {$datos['marca']} · {$datos['direccion']}";
    $correo->Body = $html;
    $correo->AltBody = $texto;
    if ($imagen) $correo->addAttachment($imagen['ruta']);
    $correo->send();
    $enviados['empresa'] = true;
} catch (Throwable $error) {
    registrarError($dirDatos, "$codigo empresa: " . $error->getMessage());
}

// 2. Constancia al consumidor
try {
    $intro = 'Hola ' . e($datos['nombres']) . ', hemos registrado tu ' . e(mb_strtolower($datos['tipo']))
        . ' con el N° <strong>' . e($codigo) . '</strong>. Te enviaremos una respuesta a este correo en un plazo máximo de 15 días hábiles.'
        . ' A continuación encontrarás una copia de tu hoja de reclamación.';
    [$html, $texto] = cuerpoReclamo($codigo, $registrado, $datos, $intro);
    $correo = crearCorreo($config);
    $correo->addAddress($datos['email'], $consumidor);
    $correo->addBCC(ARCHIVO);
    $correo->addReplyTo(DESTINATARIOS[1], 'Atención al Cliente – Lucha Partners');
    $correo->Subject = "Constancia de tu {$datos['tipo']} $codigo – Lucha Partners";
    $correo->Body = $html;
    $correo->AltBody = $texto;
    if ($imagen) $correo->addAttachment($imagen['ruta']);
    $correo->send();
    $enviados['consumidor'] = true;
} catch (Throwable $error) {
    registrarError($dirDatos, "$codigo consumidor: " . $error->getMessage());
}

responder(200, [
    'ok' => true,
    'numero' => $codigo,
    'constanciaEnviada' => $enviados['consumidor'],
]);
