<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$id_recibo = (int) ($_GET['id'] ?? 0);

if (!$id_recibo) {
    die("ID de recibo no válido");
}

// Obtener datos del recibo
$sql = "
SELECT 
    f.*,
    c.id_contrato,
    cl.id_cliente,
    cl.nombre_completo as cliente_nombre,
    cl.codigo_cliente,
    cl.email as cliente_email,
    cl.telefono as cliente_telefono,
    s.nombre_servicio,
    s.descripcion as servicio_descripcion,
    d.calle,
    d.colonia,
    d.numero_exterior,
    d.numero_interior,
    d.referencia,
    COALESCE(SUM(p.monto_pagado), 0) as total_pagado
FROM facturas f
JOIN contratos c ON f.id_contrato = c.id_contrato
JOIN clientes cl ON c.id_cliente = cl.id_cliente
JOIN servicios s ON c.id_servicio = s.id_servicio
LEFT JOIN direcciones d ON cl.id_cliente = d.id_cliente AND d.es_principal = true
LEFT JOIN pagos p ON f.id_factura = p.id_factura
WHERE f.id_factura = ?
GROUP BY 
    f.id_factura, c.id_contrato, cl.id_cliente, cl.nombre_completo, cl.codigo_cliente, 
    cl.email, cl.telefono, s.nombre_servicio, s.descripcion,
    d.calle, d.colonia, d.numero_exterior, d.numero_interior, d.referencia
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_recibo]);
    $recibo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recibo) {
        die("Recibo no encontrado");
    }
} catch (Exception $e) {
    die("Error al cargar el recibo: " . $e->getMessage());
}

// Crear PDF
require_once __DIR__ . '/vendor/autoload.php'; // Necesitas instalar TCPDF: composer require tecnickcom/tcpdf

use TCPDF as TCPDF;

class MYPDF extends TCPDF
{
    // Page header
    public function Header()
    {
        // Logo
        $image_file = __DIR__ . '/img/logo.jpg';
        if (file_exists($image_file)) {
            $this->Image($image_file, 15, 10, 25, 0, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }

        // Company info
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 10, 'AYONET', 0, 1, 'C');
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 5, 'Sistema de Gestión de Servicios', 0, 1, 'C');
        $this->Cell(0, 5, 'Tel: (123) 456-7890 | Email: info@ayonet.com', 0, 1, 'C');

        // Line break
        $this->Ln(5);
        $this->Line(10, 35, 200, 35);
    }

    // Page footer
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Página ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// Crear nuevo PDF
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Información del documento
$pdf->SetCreator('AYONET');
$pdf->SetAuthor('AYONET');
$pdf->SetTitle('Recibo #' . $recibo['id_factura']);
$pdf->SetSubject('Recibo de Servicio');

// Margenes
$pdf->SetMargins(15, 40, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Añadir página
$pdf->AddPage();

// Contenido del recibo
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'RECIBO DE PAGO', 0, 1, 'C');
$pdf->Ln(5);

// Información del recibo
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(40, 6, 'Recibo #:', 0, 0);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 6, $recibo['id_factura'], 0, 1);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(40, 6, 'Fecha Emisión:', 0, 0);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 6, date('d/m/Y', strtotime($recibo['fecha_emision'])), 0, 1);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(40, 6, 'Vencimiento:', 0, 0);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 6, date('d/m/Y', strtotime($recibo['fecha_vencimiento'])), 0, 1);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(40, 6, 'Estado:', 0, 0);
$pdf->SetFont('helvetica', '', 12);
$estado_texto = [
    'pagada' => 'PAGADO',
    'pendiente' => 'PENDIENTE',
    'vencida' => 'VENCIDO',
    'cancelada' => 'CANCELADO'
];
$pdf->Cell(0, 6, $estado_texto[$recibo['estado']] ?? $recibo['estado'], 0, 1);

$pdf->Ln(10);

// Información del cliente
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'INFORMACIÓN DEL CLIENTE', 0, 1);
$pdf->SetLineWidth(0.3);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(5);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(40, 6, 'Cliente:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, $recibo['cliente_nombre'], 0, 1);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(40, 6, 'Código:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, $recibo['codigo_cliente'], 0, 1);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(40, 6, 'Dirección:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$direccion = [];
if ($recibo['calle'])
    $direccion[] = $recibo['calle'];
if ($recibo['colonia'])
    $direccion[] = $recibo['colonia'];
if ($recibo['numero_exterior'])
    $direccion[] = '#' . $recibo['numero_exterior'];
$pdf->Cell(0, 6, $direccion ? implode(', ', $direccion) : 'No disponible', 0, 1);

$pdf->Ln(8);

// Detalles del servicio
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'DETALLES DEL SERVICIO', 0, 1);
$pdf->SetLineWidth(0.3);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(5);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(40, 6, 'Servicio:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, $recibo['nombre_servicio'], 0, 1);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(40, 6, 'Descripción:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 6, $recibo['servicio_descripcion'] ?? 'Servicio de internet', 0, 1);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(40, 6, 'Periodo:', 0, 0);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, $recibo['periodo_pagado'] ?? 'Mensual', 0, 1);

$pdf->Ln(8);

// Información de pago
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'INFORMACIÓN DE PAGO', 0, 1);
$pdf->SetLineWidth(0.3);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(5);

// Tabla de montos
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(140, 8, 'Concepto', 1, 0, 'L');
$pdf->Cell(40, 8, 'Monto', 1, 1, 'R');

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(140, 8, 'Servicio ' . $recibo['nombre_servicio'], 1, 0, 'L');
$pdf->Cell(40, 8, '$' . number_format($recibo['monto'], 2), 1, 1, 'R');

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(140, 8, 'TOTAL', 1, 0, 'L');
$pdf->Cell(40, 8, '$' . number_format($recibo['monto'], 2), 1, 1, 'R');

$pdf->Ln(10);

// Resumen de pagos
if ($recibo['total_pagado'] > 0) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'RESUMEN DE PAGOS', 0, 1);

    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(100, 6, 'Total Pagado:', 0, 0);
    $pdf->Cell(0, 6, '$' . number_format($recibo['total_pagado'], 2), 0, 1);

    $pdf->Cell(100, 6, 'Saldo Pendiente:', 0, 0);
    $saldo = $recibo['monto'] - $recibo['total_pagado'];
    $pdf->Cell(0, 6, '$' . number_format($saldo, 2), 0, 1);

    $pdf->Ln(8);
}

// Notas importantes
$pdf->SetFont('helvetica', 'I', 10);
$pdf->MultiCell(0, 6, 'Notas importantes:', 0, 'L');
$pdf->MultiCell(0, 5, '• Este recibo es un documento oficial de AYONET.', 0, 'L');
$pdf->MultiCell(0, 5, '• Para cualquier aclaración, contactar al departamento de cobranza.', 0, 'L');
$pdf->MultiCell(0, 5, '• Los pagos vencidos pueden generar cargos por mora.', 0, 'L');
$pdf->MultiCell(0, 5, '• Horario de atención: Lunes a Viernes 9:00 - 18:00 hrs.', 0, 'L');

$pdf->Ln(15);

// Firma
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, '_________________________', 0, 1, 'C');
$pdf->Cell(0, 6, 'Firma y Sello', 0, 1, 'C');

// Generar PDF
$pdf->Output('recibo_' . $recibo['id_factura'] . '.pdf', 'I');
?>