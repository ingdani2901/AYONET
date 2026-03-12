<?php
// test_tcpdf.php - PRUEBA DE TCPDF
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Incluir TCPDF
require_once __DIR__ . '/vendor/autoload.php';

use TCPDF as TCPDF;

try {
    // Crear nuevo PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Información del documento
    $pdf->SetCreator('AYONET');
    $pdf->SetAuthor('AYONET');
    $pdf->SetTitle('Prueba de TCPDF');
    $pdf->SetSubject('Sistema de Recibos');

    // Agregar página
    $pdf->AddPage();

    // Contenido
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, '¡TCPDF INSTALADO CORRECTAMENTE!', 0, 1, 'C');

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Sistema AYONET', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Módulo de Recibos PDF', 0, 1, 'C');
    $pdf->Cell(0, 8, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
    $pdf->Cell(0, 8, 'Directorio: ' . __DIR__, 0, 1, 'C');

    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 6, '¡Ahora puedes generar recibos profesionales!', 0, 1, 'C');

    // Generar PDF en el navegador
    $pdf->Output('prueba_ayonet.pdf', 'I');

} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>