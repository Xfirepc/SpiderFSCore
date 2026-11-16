<?php

/** Flujo real en el fixture desechable; nunca carga una configuración de producción. */
use FacturaScripts\Core\Base\Calculator;
use FacturaScripts\Core\Lib\SalesDocumentAddress;
use FacturaScripts\Core\Session;
use FacturaScripts\Dinamic\Lib\Import\CSVImport;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\SpiderFact\Lib\DocumentLayout\InfoDocument;
use FacturaScripts\Plugins\SpiderFact\Lib\XMLGeneratorFactura;
use FacturaScripts\Plugins\SpiderFact\Model\SFSetting;

if (getenv('SB_DEMO_ISOLATED') !== '1' || FS_FOLDER !== '/work/runtime'
    || FS_DB_NAME !== 'sb_demo_test_host') {
    throw new RuntimeException('La dirección solo se prueba en MariaDB desechable.');
}

$host = demoPdo(FS_DB_NAME);
foreach (['idsfiscales', 'estados_documentos'] as $table) {
    $sql = CSVImport::importTableSQL($table);
    if ($sql !== '') {
        $host->exec($sql);
    }
}
$setting = new Settings();
$setting->name = 'facturacione';
$setting->properties = ['allow_accounting' => false];
demoCheck($setting->save(), 'Desactivar contabilidad en el fixture');
$payment = new FormaPago();
demoCheck($payment->loadFromCode('CASH'), 'Forma de pago del fixture');
$payment->codcuentabanco = null;
demoCheck($payment->save(), 'El pago en efectivo no utiliza el banco ficticio del maestro');
$user = new User();
$user->nick = 'direcciones';
$user->idempresa = 1;
$user->setPassword('Prueba-aislada-direcciones-2026');
demoCheck($user->save(), 'Usuario sintético para generar el XML');
Session::set('user', $user);

foreach ([[null, ''], ['', ''], ['0', '0'], [' Calle corta ', 'Calle corta'],
    [str_repeat('A', 100), str_repeat('A', 100)],
    [str_repeat('A', 101), str_repeat('A', 100)],
    [str_repeat('Ñ', 150), str_repeat('Ñ', 100)],
    ["Calle\r\nNiño\nÁlvarez", 'Calle Niño Álvarez']] as [$input, $expected]) {
    demoCheck(SalesDocumentAddress::normalize($input) === $expected, 'Límites y UTF-8 de la dirección');
}

$address = mb_substr(str_repeat('Av. Simón Bolívar & El Niño / ', 8), 0, 150, 'UTF-8');
$expectedAddress = rtrim(mb_substr($address, 0, 100, 'UTF-8'));
$customer = new Cliente();
$customer->codcliente = 'DIR100';
$customer->nombre = 'Cliente de dirección extensa';
$customer->razonsocial = 'Cliente de dirección extensa';
$customer->cifnif = '9999999999999';
$customer->tipoidfiscal = 'CF';
$customer->codpais = 'ECU';
$customer->direccion = $address;
demoCheck($customer->save(), 'Guardar el cliente con sus 150 caracteres');
$contact = $customer->getDefaultAddress('billing');
$originalContactId = $contact->idcontacto;
$originalContactAddress = $contact->direccion;
$invoice = null;
foreach ([FacturaCliente::class, AlbaranCliente::class, PedidoCliente::class, PresupuestoCliente::class] as $class) {
    $document = new $class();
    demoCheck($document->setSubject($customer), 'Asignar el cliente al documento');
    demoCheck($document->direccion === $expectedAddress, 'Recortar solo la copia del documento');
    demoCheck($document->save(), 'Guardar sin error de longitud SQL');
    $copy = new $class();
    demoCheck($copy->loadFromCode($document->primaryColumnValue()), 'Recuperar el documento guardado');
    demoCheck($copy->direccion === $expectedAddress, 'Persistir la dirección limitada sin recortes adicionales');
    if ($document instanceof FacturaCliente) {
        $invoice = $document;
    }
}
demoCheck($customer->loadFromCode('DIR100') && $customer->direccion === $address, 'Conservar íntegra la ficha del cliente');
$contactLoaded = $contact->loadFromCode($originalContactId);
demoCheck($contactLoaded && $contact->direccion === $originalContactAddress, 'Conservar íntegro el contacto original: '
    . json_encode(['id' => $originalContactId, 'loaded' => $contactLoaded,
        'length' => mb_strlen($contact->direccion ?? '', 'UTF-8')]));

$second = new Contacto();
$second->codcliente = $customer->codcliente;
$second->nombre = 'Otro domicilio';
$second->descripcion = 'Otro domicilio';
$second->codpais = 'ECU';
$second->direccion = mb_substr(str_repeat('Calle Ñandú & Jardín del Norte / ', 8), 0, 150, 'UTF-8');
demoCheck($second->save(), 'Preparar otro contacto con dirección larga');
$snapshot = rtrim(mb_substr($second->direccion, 0, 100, 'UTF-8'));
$invoice->idcontactofact = $second->idcontacto;
$invoice->direccion = str_repeat('A', 150);
demoCheck($invoice->save(), 'El cambio tardío de contacto también debe recortarse');
demoCheck($invoice->direccion === $snapshot, 'La dirección elegida cabe después de onChange');
$invoice->direccion = str_repeat('Ñ', 150);
demoCheck($invoice->save(), 'Recortar también direcciones asignadas directamente');
demoCheck($invoice->direccion === str_repeat('Ñ', 100), 'El recorte directo no rompe UTF-8');
$invoice->direccion = $snapshot;
demoCheck($invoice->save(), 'Preparar la copia que se emitirá en el XML');
$second->direccion = 'Domicilio modificado después de guardar la factura';
demoCheck($second->save(), 'Modificar el contacto sin alterar la factura histórica');

$company = new Empresa();
demoCheck($company->loadFromCode(1), 'Empresa del fixture');
$company->direccion = 'Quito, dirección de prueba';
$company->cifnif = '9900000000001';
demoCheck($company->save(), 'Dirección del emisor para validar el XSD');
$serie = new Serie();
demoCheck($serie->loadFromCode('A'), 'Serie del fixture');
$serie->establishment = '001';
$serie->terminal = '001';
$serie->address = 'Quito, dirección de prueba';
demoCheck($serie->save(), 'Preparar establecimientos sintéticos');
$tax = new Impuesto();
demoCheck($tax->loadFromCode('IVATEST'), 'Impuesto del fixture');
$tax->codigo = 2;
$tax->codigoPorcentaje = 0;
demoCheck($tax->save(), 'Impuesto válido para el esquema SRI');
$fiscal = new SFSetting();
$fiscal->idempresa = 1;
$fiscal->codalmacen = 'W001';
$fiscal->codserie = 'A';
$fiscal->ambiente = 1;
$fiscal->regimen = 'GENERAL';
$fiscal->obligadoContabilidad = 'NO';
demoCheck($fiscal->save(), 'Configuración fiscal local de prueba');
$product = new Producto();
$product->referencia = 'DIR-XML';
$product->descripcion = 'Producto de dirección';
$product->precio = 10;
$product->nostock = true;
demoCheck($product->save(), 'Producto sintético sin movimientos de stock');
$line = $invoice->getNewProductLine($product->referencia);
$line->cantidad = 1;
demoCheck($line->save(), 'Crear la línea del XML');
$lines = $invoice->getLines();
demoCheck(Calculator::calculate($invoice, $lines, true), 'Conservar los totales de la factura');
$serie->allow_fact = true;
demoCheck($serie->save(), 'Habilitar generación local sin transmitir al SRI');
InfoDocument::clean();
$generator = new XMLGeneratorFactura();
$generator->setDocument($invoice);
$xml = $generator->buildDocumentXML();
$dom = new DOMDocument();
demoCheck($dom->loadXML($xml), 'XML bien formado con ampersand y tildes');
$xpath = new DOMXPath($dom);
demoCheck($xpath->evaluate('string(/factura/infoFactura/direccionComprador)') === $snapshot,
    'El XML utiliza la dirección guardada en la factura, no la ficha modificada');
libxml_use_internal_errors(true);
$valid = $dom->schemaValidate($source . '/Plugins/SpiderFact/XSD/Factura_V_1_0_0.xsd');
$errors = array_map(static function ($error) { return trim($error->message); }, libxml_get_errors());
demoCheck($valid, 'XML compatible con el XSD: ' . implode('; ', $errors));
demoCheck(abs($invoice->total - 10) < 0.001, 'Importe conservado en USD');
demoCheck((int)$host->query('SELECT COUNT(*) FROM asientos')->fetchColumn() === 0, 'No activar contabilidad al recortar direcciones');
echo json_encode(['sales_address_checks' => $GLOBALS['demo_checks'], 'xml_schema_valid' => $valid]), PHP_EOL;
