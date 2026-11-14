<?php

namespace FacturaScripts\Plugins\SpiderVet\Test;

use FacturaScripts\Plugins\SpiderVet\Lib\FieldCatalog;
use FacturaScripts\Plugins\SpiderVet\Lib\Validation;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    /** @dataProvider invalidDates */
    public function testImpossibleDatesAreRejected(string $value, bool $time): void
    {
        $this->assertNull(Validation::date($value, $time));
    }

    public static function invalidDates(): array
    {
        return [['2026-02-30', false], ['31-04-2026', false], ['2026-13-01', false],
            ['2026-10-02T25:15', true], ['2026-10-02T12:75', true], ['yesterday', false], ['0', false]];
    }

    public function testNativeAndBrowserDatesShareTheSameStoredFormat(): void
    {
        $this->assertSame('02-10-2026 09:30:00', Validation::date('2026-10-02T09:30', true));
        $this->assertSame('02-10-2026 09:30:00', Validation::date('02-10-2026 09:30:00', true));
        $this->assertNull(Validation::date(''));
        $this->assertNull(Validation::date(null));
    }

    public function testUnknownOrInvalidMeasurementsAreNotZero(): void
    {
        foreach (['', null, 'abc', INF, NAN, -1] as $value) {
            $this->assertFalse(Validation::number($value));
        }
        $this->assertTrue(Validation::number(0));
        $this->assertTrue(Validation::number('3.25'));
        $this->assertFalse(Validation::number(10, 1, 9));
        $this->assertFalse(Validation::number(101, 0, 100));
    }

    public function testTheSuppliedClinicalFormHasAllPhysicalAndFollowUpFields(): void
    {
        $keys = [];
        foreach (FieldCatalog::get('SVConsulta')['groups'] as $group) {
            $keys = array_merge($keys, array_column($group['fields'], 'key'));
        }
        foreach (['fecha', 'tipo', 'veterinario', 'motivo', 'historiaproblema', 'vacunacion', 'ultimavacuna',
            'desparasitacion', 'ultimadesparasitacion', 'alimentacion', 'alergias', 'medicamentos', 'antecedentes',
            'temperatura', 'frecuenciacardiaca', 'frecuenciarespiratoria', 'peso', 'mucosas', 'tllc', 'hidratacion',
            'bcs', 'estadogeneral', 'pielpelaje', 'ojosoidosboca', 'respiratorio', 'cardiovascular', 'digestivo',
            'urinariogenital', 'neurologico', 'problemas', 'diagnosticopresuntivo', 'diagnosticodefinitivo',
            'cuidados', 'proximocontrol', 'ayuno', 'alarmas', 'llamadaseguimiento', 'fechaseguimiento',
            'conformidad'] as $key) {
            $this->assertContains($key, $keys, 'Missing DOCX field: ' . $key);
        }
    }
}
