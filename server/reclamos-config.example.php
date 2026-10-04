<?php
/*
 * Configuración de envío del Libro de Reclamaciones.
 *
 * Copia este archivo como `reclamos-config.php` en el servidor, en la carpeta
 * que contiene public_html (NO dentro de public_html) y completa los datos.
 * Este archivo nunca debe subirse al repositorio.
 */

return [
    // Servidor SMTP de la cuenta de correo que envía los reclamos.
    // Se usa mail.zoffice.cloud (no mail.lalucha.com.pe) porque el certificado
    // SSL del servidor de correo está emitido para ese nombre.
    'smtp_host'   => 'mail.zoffice.cloud',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl', // 'ssl' para 465, 'tls' para 587
    'smtp_user'   => 'librodereclamaciones@lalucha.com.pe',
    'smtp_pass'   => '',

    // Remitente que verán los destinatarios
    'from_email'  => 'librodereclamaciones@lalucha.com.pe',
    'from_name'   => 'Libro de Reclamaciones – Lucha Partners',

    // Opcional: carpeta donde se guardan los reclamos, imágenes y el contador.
    // Por defecto: ../reclamos-data (fuera de public_html)
    // 'data_dir' => '/home/usuario/reclamos-data',
];
