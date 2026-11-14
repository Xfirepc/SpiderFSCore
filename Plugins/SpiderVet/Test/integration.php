<?php

require __DIR__ . '/bootstrap.php';

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Session;
use FacturaScripts\Plugins\SpiderVet\Controller\EditSVPaciente;
use FacturaScripts\Plugins\SpiderVet\Controller\PrintSVFicha;
use FacturaScripts\Plugins\SpiderVet\Init;
use FacturaScripts\Plugins\SpiderVet\Lib\PatientSearch;
use FacturaScripts\Plugins\SpiderVet\Model\SVCita;
use FacturaScripts\Plugins\SpiderVet\Model\SVConsulta;
use FacturaScripts\Plugins\SpiderVet\Model\SVPaciente;
use FacturaScripts\Plugins\SpiderVet\Model\SVPeluqueria;
use FacturaScripts\Plugins\SpiderVet\Model\SVPrueba;
use FacturaScripts\Plugins\SpiderVet\Model\SVRecepcion;
use FacturaScripts\Plugins\SpiderVet\Model\SVTratamiento;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$checks = 0;
function vetCheck(bool $value, string $message): void
{
    global $checks;
    ++$checks;
    if (!$value) {
        $logs = (new MiniLog())->read('', ['warning', 'error', 'critical']);
        throw new RuntimeException($message . '\n' . implode('\n', array_column($logs, 'message')));
    }
    MiniLog::clear();
}

$init = new Init();
$init->update();
$init->init();
$roles = $database->select('SELECT * FROM roles_access WHERE codrole = \'SpiderVet\'');
vetCheck(count($roles) === 16, 'The plugin role was not installed.');
$database->exec("UPDATE roles_access SET allowdelete = 0 WHERE codrole = 'SpiderVet' AND pagename = 'EditSVConsulta'");
$init->update();
vetCheck(count($database->select("SELECT * FROM roles_access WHERE codrole = 'SpiderVet'")) === 16,
    'Updating the plugin duplicated permissions.');
vetCheck((int) $database->select("SELECT allowdelete FROM roles_access WHERE codrole = 'SpiderVet' AND pagename = 'EditSVConsulta'")[0]['allowdelete'] === 0,
    'Updating the plugin changed existing permissions.');

$patient = new SVPaciente();
$patient->codcliente = 'CLI001';
$patient->nombre = 'Luna';
$patient->idempresa = 1;
$patient->nick = 'doctor';
$patient->raza = 'Mestiza';
$patient->sexo = 'hembra';
$patient->indicacionesbano = "Usar agua tibia.\nEvitar mojar los oídos y hablarle con calma.";
$patient->alergias = 'Champú perfumado';
vetCheck($patient->save(), 'Cannot create the first patient.');
vetCheck($patient->historia === 'SV-000001', 'History numbering is not automatic.');
$second = new SVPaciente();
$second->codcliente = 'CLI001';
$second->nombre = 'Simón';
$second->idempresa = 1;
$second->nick = 'doctor';
$second->especie = 'felino';
vetCheck($second->save(), 'A client cannot have multiple pets.');
$foreign = new SVPaciente();
$foreign->codcliente = 'CLI002';
$foreign->nombre = 'Paciente ajeno';
$foreign->idempresa = 1;
$foreign->nick = 'other';
vetCheck($foreign->save(), 'Cannot create another owner\'s patient.');
$collation = $database->select("SHOW FULL COLUMNS FROM sv_pacientes WHERE Field = 'codcliente'");
vetCheck($collation[0]['Collation'] === 'utf8mb4_unicode_ci', 'Customer key collation was not preserved.');

$appointment = new SVCita();
$appointment->idpaciente = $patient->id;
$appointment->servicio = 'ambos';
$appointment->inicio = date('Y-m-d') . 'T09:00';
$appointment->fin = date('Y-m-d') . 'T10:00';
$appointment->motivo = 'Control general y baño';
$appointment->responsable = 'doctor';
vetCheck($appointment->save(), 'Cannot schedule a combined appointment.');
$invalidAppointment = new SVCita();
$invalidAppointment->idpaciente = $second->id;
$invalidAppointment->inicio = '2026-10-02T10:00';
$invalidAppointment->fin = '2026-10-02T09:00';
vetCheck(!$invalidAppointment->save(), 'A backwards appointment was accepted.');

$reception = new SVRecepcion();
$reception->idcita = $appointment->id;
$reception->idpaciente = $patient->id;
$reception->servicio = 'ambos';
$reception->motivo = $appointment->motivo;
vetCheck($reception->save(), 'Cannot check in an appointment.');
$appointment->loadFromCode($appointment->id);
vetCheck($appointment->estado === 'recibida', 'Check-in did not synchronize the appointment.');
$duplicate = new SVRecepcion();
$duplicate->idcita = $appointment->id;
$duplicate->idpaciente = $patient->id;
$duplicate->servicio = 'ambos';
vetCheck(!$duplicate->save(), 'The appointment was checked in twice.');
$reception->estado = 'completada';
vetCheck(!$reception->save(), 'A combined reception completed before its services.');
$reception->loadFromCode($reception->id);

$clinical = new SVConsulta();
$clinical->initializeForPatient($patient);
$clinical->idrecepcion = $reception->id;
$clinical->veterinario = 'Dra. Ana';
$clinical->motivo = 'Control general';
$clinical->historiaproblema = str_repeat("Texto clínico largo con signos y evolución.\n", 90);
$clinical->temperatura = 38.5;
$clinical->peso = 4.25;
$clinical->frecuenciacardiaca = null;
$clinical->estado = 'terminada';
$clinical->cuidados = "Cuidados registrados para esta paciente.\nSegunda línea de instrucciones.";
$clinical->proximocontrol = '2026-10-10T09:00';
vetCheck($clinical->save(), 'Cannot save a consultation.');
$reception->loadFromCode($reception->id);
vetCheck($reception->estado === 'en_atencion', 'One service prematurely completed the combined visit.');
vetCheck($clinical->frecuenciacardiaca === null, 'An unknown measurement was converted to zero.');
vetCheck($clinical->fichapaciente !== null && $clinical->fichatutor !== null, 'The historical context was not saved.');

foreach (['Hemograma', 'Ecografía'] as $name) {
    $test = new SVPrueba();
    $test->idconsulta = $clinical->id;
    $test->examen = $name;
    $test->resultado = 'Resultado de ' . $name;
    vetCheck($test->save(), 'Cannot add a test to an editable finished consultation.');
}
foreach (['Procedimiento A', 'Procedimiento B'] as $name) {
    $treatment = new SVTratamiento();
    $treatment->idconsulta = $clinical->id;
    $treatment->medicamento = $name;
    $treatment->dosis = 'Según registro profesional';
    $treatment->viafrecuencia = 'Según indicación';
    $treatment->duracion = 'Registrada';
    vetCheck($treatment->save(), 'Cannot add multiple treatment rows.');
}
vetCheck(count($clinical->tests()) === 2 && count($clinical->treatments()) === 2, 'Clinical rows were lost.');

$grooming = new SVPeluqueria();
$grooming->initializeForPatient($patient);
$grooming->idrecepcion = $reception->id;
$grooming->solicitado = 'Baño y corte';
$grooming->realizado = 'Baño con champú sin perfume y corte';
$grooming->responsable = 'doctor';
$grooming->estado = 'terminada';
vetCheck($grooming->save(), 'Cannot finish the grooming service.');
$reception->loadFromCode($reception->id);
$appointment->loadFromCode($appointment->id);
vetCheck($reception->estado === 'completada' && $reception->salida !== null && $appointment->estado === 'completada',
    'Combined visit completion was not synchronized.');

$snapshot = $clinical->fichapaciente;
$patient->nombre = 'Luna actualizada';
$patient->indicacionesbano = 'Nuevas indicaciones de baño';
vetCheck($patient->save(), 'Cannot edit the general patient record.');
$clinical->diagnosticodefinitivo = 'Diagnóstico añadido después de terminar';
vetCheck($clinical->save(), 'Finished consultations must remain editable.');
vetCheck($clinical->fichapaciente === $snapshot, 'Editing changed the original patient snapshot.');
$grooming->loadFromCode($grooming->id);
vetCheck(strpos($grooming->indicacionesbano, 'Usar agua tibia') !== false, 'Grooming instructions were overwritten.');
$wrong = new SVConsulta();
$wrong->initializeForPatient($second);
$wrong->idrecepcion = $reception->id;
$wrong->veterinario = 'Doctor';
$wrong->motivo = 'Atención ajena';
vetCheck(!$wrong->save(), 'A visit was associated with a different patient.');
vetCheck(!$clinical->delete() && !$patient->delete(), 'Patient history could be deleted.');
$patient->activo = false;
vetCheck($patient->save(), 'Cannot deactivate a patient while preserving history.');
$newForInactive = new SVConsulta();
$newForInactive->initializeForPatient($patient);
$newForInactive->motivo = 'Nueva atención';
$newForInactive->veterinario = 'Doctor';
vetCheck(!$newForInactive->save(), 'An inactive patient received a new consultation.');

$walkIn = new SVRecepcion();
$walkIn->idpaciente = $second->id;
$walkIn->servicio = 'peluqueria';
vetCheck($walkIn->save(), 'Cannot register a walk-in arrival.');
$draft = new SVPeluqueria();
$draft->initializeForPatient($second);
$draft->idrecepcion = $walkIn->id;
$draft->solicitado = 'Baño';
vetCheck($draft->save(), 'Cannot create a grooming draft.');
vetCheck($draft->delete(), 'Cannot remove an empty draft.');
$walkIn->loadFromCode($walkIn->id);
vetCheck($walkIn->estado === 'pendiente', 'Deleting a draft left reception in progress.');
$walkIn->estado = 'cancelada';
vetCheck($walkIn->save(), 'Cannot cancel a walk-in without history.');

$read = new ControllerPermissions($user, 'EditSVPaciente');
vetCheck(count(PatientSearch::find('Elena', $user, $read)) === 2, 'Tutor search failed.');
vetCheck(count(PatientSearch::find($patient->historia, $user, $read)) === 1, 'History number search failed.');
vetCheck(PatientSearch::find("' OR 1 = 1 --", $user, $read) === [], 'Search did not escape input.');

function vetController(string $name, string $url, array $post = [], $actor = null, $permissions = null)
{
    global $user;
    $actor = $actor ?: $user;
    (new \FacturaScripts\Core\Lib\MultiRequestProtection())->clearSeed();
    Session::set('user', $actor);
    $class = 'FacturaScripts\\Dinamic\\Controller\\' . $name;
    $controller = new $class($name, $url);
    $controller->request = Request::create($url, $post ? 'POST' : 'GET', $post);
    $response = new Response();
    $controller->privateCore($response, $actor, $permissions ?: new ControllerPermissions($actor, $name));
    return [$controller, $response];
}

function vetRender($controller): string
{
    $menu = new \FacturaScripts\Core\Base\MenuManager();
    $menu->setUser(Session::user());
    $menu->selectPage($controller->getPageData());
    return Html::render($controller->getTemplate(), ['fsc' => $controller, 'menuManager' => $menu,
        'controllerName' => $controller->getPageData()['name'], 'template' => $controller->getTemplate(),
        'debugBarRender' => false]);
}

[$editor] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente?code=' . $patient->id);
vetCheck(count($editor->views) === 5 && (int) $editor->getModel()->id === (int) $patient->id, 'Patient history tabs did not load.');
foreach (['SVPaciente', 'SVCita', 'SVRecepcion', 'SVConsulta', 'SVPrueba', 'SVTratamiento', 'SVPeluqueria'] as $name) {
    [$list] = vetController('List' . $name, '/vet-test/List' . $name);
    vetCheck(count($list->views) === 1, 'Cannot load native list ' . $name);
    [$editor] = vetController('Edit' . $name, '/vet-test/Edit' . $name);
    vetCheck(isset($editor->views['Edit' . $name]), 'Cannot load native form ' . $name);
}

[$print] = vetController('PrintSVFicha', '/vet-test/PrintSVFicha?consulta=' . $clinical->id);
Html::addPath('SpiderVet', '/app/Plugins/SpiderVet/View');
$printed = Html::render('PrintSVFicha.html.twig', ['fsc' => $print]);
foreach (['1. Datos de la consulta', '2. Responsable / tutor', '3. Datos del paciente', '4. Antecedentes',
    '5. Examen físico', '6. Evaluación clínica', '7. Pruebas complementarias', '8. Tratamiento',
    '9. Indicaciones', '10. Conformidad', 'Hemograma', 'Procedimiento B', 'Firma y sello'] as $text) {
    vetCheck(strpos($printed, $text) !== false, 'Printed form is missing: ' . $text);
}
vetCheck(strpos($printed, '/vet-test/Dinamic/Assets/CSS/SpiderVetPrint.css') !== false, 'Print resources ignore the application route.');
file_put_contents(FS_FOLDER . '/single-consultation.html', $printed);
[$print] = vetController('PrintSVFicha', '/vet-test/PrintSVFicha?code=' . $patient->id);
$printed = Html::render('PrintSVFicha.html.twig', ['fsc' => $print]);
vetCheck(strpos($printed, 'Usar agua tibia') !== false && strpos($printed, 'Nuevas indicaciones de baño') !== false,
    'Full print is missing current or historical grooming instructions.');
file_put_contents(FS_FOLDER . '/full-record.html', $printed);

$limited = new FacturaScripts\Dinamic\Model\User();
$limited->loadFromCode('limited');
$ownerOnly = new ControllerPermissions();
$ownerOnly->allowAccess = $ownerOnly->allowUpdate = $ownerOnly->allowDelete = $ownerOnly->allowExport = true;
$ownerOnly->onlyOwnerData = true;
[$denied] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente?code=' . $patient->id, [], $limited, $ownerOnly);
vetCheck($denied->getTemplate() === 'Error/AccessDenied.html.twig', 'Direct access bypassed record ownership.');
vetCheck(PatientSearch::find('Luna', $limited, $ownerOnly) === [], 'Search leaked another owner\'s patient.');
[$denied] = vetController('PrintSVFicha', '/vet-test/PrintSVFicha?code=' . $patient->id, [], $limited, $ownerOnly);
vetCheck($denied->getTemplate() === 'Error/AccessDenied.html.twig', 'Printing bypassed record permissions.');

Session::set('user', $user);
[$editor] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente?code=' . $patient->id);
$before = $patient->nombre;
[$invalidPost] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente?code=' . $patient->id,
    ['action' => 'edit', 'code' => $patient->id, 'nombre' => 'Unauthorized edit', 'codcliente' => 'CLI001']);
$patient->loadFromCode($patient->id);
vetCheck($patient->nombre === $before, 'A write without a CSRF token succeeded.');

[$editor] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente');
$token = $editor->multiRequestProtection->newToken();
$payload = ['action' => 'insert', 'nombre' => 'Paciente desde formulario', 'codcliente' => 'CLI001',
    'especie' => 'canino', 'activo' => '1', 'multireqtoken' => $token,
    'nick' => 'other', 'idempresa' => 987, 'id' => $foreign->id, 'peso' => '', 'nacimiento' => ''];
[$saved, $savedResponse] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente', $payload);
$formPatient = new SVPaciente();
vetCheck($formPatient->loadFromCode('', [new DataBaseWhere('nombre', 'Paciente desde formulario')]),
    'A valid native patient form could not be saved.');
vetCheck($formPatient->nick === 'doctor' && (int) $formPatient->idempresa === 1
    && (int) $formPatient->id !== (int) $foreign->id && $formPatient->peso === null,
    'Posted identity/ownership or an empty measurement bypassed server values.');

[$editor] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente');
$payload['multireqtoken'] = $editor->multiRequestProtection->newToken();
$payload['nombre'] = 'Fecha imposible';
$payload['nacimiento'] = '2026-02-30';
vetController('EditSVPaciente', '/vet-test/EditSVPaciente', $payload);
vetCheck(!(new SVPaciente())->count([new DataBaseWhere('nombre', 'Fecha imposible')]),
    'The date widget normalized an impossible birth date instead of rejecting it.');

$readOnly = new \FacturaScripts\Plugins\SpiderVet\Model\SVClientePaciente();
$readOnly->loadFromCode($formPatient->id);
$readOnly->nombre = 'Bypass from customer view';
vetCheck(!$readOnly->save() && !$readOnly->delete(), 'Customer tabs can mutate patient records.');

$roleUser = new \FacturaScripts\Core\Model\RoleUser();
$roleUser->codrole = 'SpiderVet';
$roleUser->nick = 'limited';
vetCheck($roleUser->save(), 'Cannot assign the test veterinary role.');
$database->exec("UPDATE roles_access SET onlyownerdata = 1 WHERE codrole = 'SpiderVet'");
[$restricted, $jsonResponse] = vetController('EditSVCita',
    '/vet-test/EditSVCita?action=autocomplete&source=sv_pacientes&term=Luna', [], $limited);
vetCheck(json_decode($jsonResponse->getContent(), true) === [], 'Autocomplete leaked a restricted patient.');
[$restrictedList] = vetController('ListSVPaciente', '/vet-test/ListSVPaciente', [], $limited);
vetCheck($restrictedList->views['ListSVPaciente']->count === 0, 'A list bypassed ownership restrictions.');
[$editor] = vetController('EditSVPaciente', '/vet-test/EditSVPaciente', [], $limited);
$payload = ['action' => 'edit', 'code' => $patient->id, 'nombre' => 'Owner bypass', 'codcliente' => 'CLI001',
    'especie' => 'canino', 'multireqtoken' => $editor->multiRequestProtection->newToken()];
vetController('EditSVPaciente', '/vet-test/EditSVPaciente?code=' . $patient->id, $payload, $limited);
$patient->loadFromCode($patient->id);
vetCheck($patient->nombre === $before, 'A valid token bypassed record ownership on edit.');

Session::set('user', $user);
Html::addPath('SpiderVet', '/app/Plugins/SpiderVet/View');
$waiting = new SVRecepcion();
$waiting->idpaciente = $second->id;
$waiting->motivo = 'Revisión general';
vetCheck($waiting->save(), 'Cannot keep a patient in the waiting room.');
$planned = new SVCita();
$planned->idpaciente = $second->id;
$planned->servicio = 'peluqueria';
$planned->inicio = date('Y-m-d') . 'T15:00';
$planned->fin = date('Y-m-d') . 'T15:30';
$planned->motivo = 'Baño y cepillado';
vetCheck($planned->save(), 'Cannot prepare a waiting appointment.');
[$dashboard] = vetController('SpiderVet', '/vet-test/SpiderVet');
$arrivalToken = $dashboard->multiRequestProtection->newToken();
$arrivalPost = ['action' => 'check-in', 'idcita' => $planned->id, 'multireqtoken' => $arrivalToken];
[$dashboard, $arrivalResponse] = vetController('SpiderVet', '/vet-test/SpiderVet', $arrivalPost);
$planned->loadFromCode($planned->id);
$received = new SVRecepcion();
vetCheck($planned->estado === 'recibida' && $received->loadFromCode('', [new DataBaseWhere('idcita', $planned->id)]),
    'The agenda check-in action did not create and synchronize reception.');
vetCheck(strpos((string) $arrivalResponse->headers->get('Refresh'), 'EditSVRecepcion?code=') !== false,
    'Check-in did not open the reception editor.');
vetController('SpiderVet', '/vet-test/SpiderVet', $arrivalPost);
vetCheck((new SVRecepcion())->count([new DataBaseWhere('idcita', $planned->id)]) === 1,
    'Replaying a check-in form duplicated the arrival.');
foreach (['SpiderVet', 'EditSVPaciente', 'EditSVCita', 'EditSVConsulta', 'EditSVPeluqueria', 'ListSVPaciente'] as $name) {
    $code = $name === 'EditSVPaciente' ? $patient->id : ($name === 'EditSVConsulta' ? $clinical->id :
        ($name === 'EditSVPeluqueria' ? $grooming->id : null));
    [$screen] = vetController($name, '/vet-test/' . $name . ($code ? '?code=' . $code : ''));
    $html = vetRender($screen);
    vetCheck(strpos($html, '<html') !== false, 'Cannot render the native screen: ' . $name);
    file_put_contents(FS_FOLDER . '/' . $name . '.html', $html);
}

// Recheck schemas as on another tenant request without dropping or modifying existing records.
$property = new ReflectionProperty(DbUpdater::class, 'checkedTables');
$property->setAccessible(true);
$tables = $property->getValue();
$property->setValue(null, array_values(array_filter($tables, function ($name) { return strpos($name, 'sv_') !== 0; })));
$init->update();
$patient->loadFromCode($patient->id);
vetCheck($patient->nombre === $before, 'Reinstallation lost existing patient data.');
if (getenv('SPIDERVET_TEST_OUTPUT') === '1' && is_dir('/test-output')) {
    foreach (glob(FS_FOLDER . '/*.html') as $file) {
        copy($file, '/test-output/' . basename($file));
    }
}
echo 'SpiderVet integration: ' . $checks . " checks passed.\n";
