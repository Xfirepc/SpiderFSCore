<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Test\Core\Lib;

use FacturaScripts\Core\Lib\Accounting\Ledger;
use FacturaScripts\Core\Model\Asiento;
use FacturaScripts\Core\Tools;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    use DefaultSettingsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testLedger()
    {
        // creamos el asiento
        $asiento = new Asiento();
        $asiento->concepto = 'Test';
        $this->assertTrue($asiento->save(), 'asiento-cant-save-1');
        $this->assertNotNull($asiento->primaryColumnValue(), 'asiento-not-stored');
        $this->assertTrue($asiento->exists(), 'asiento-cant-persist');

        // añadimos una línea
        $firstLine = $asiento->getNewLine();
        $firstLine->codsubcuenta = '1000000000';
        $firstLine->concepto = 'Test linea 1';
        $firstLine->debe = 100;
        $this->assertTrue($firstLine->save(), 'linea-cant-save-1');

        // añadimos otra línea
        $secondLine = $asiento->getNewLine();
        $secondLine->codsubcuenta = '5700000000';
        $secondLine->concepto = 'Test linea 2';
        $secondLine->haber = 100;
        $this->assertTrue($secondLine->save(), 'linea-cant-save-2');

        // obtenemos el mayor
        $ejercicio = $asiento->getExercise();
        $ledger = new Ledger();
        $pages = $ledger->generate($ejercicio->idempresa, $ejercicio->fechainicio, $ejercicio->fechafin);
        $this->assertNotEmpty($pages, 'ledger-empty');

        // ahora lo agrupamos por cuenta
        $pages = $ledger->generate($ejercicio->idempresa, $ejercicio->fechainicio, $ejercicio->fechafin, ['grouped' => 'C']);
        $this->assertNotEmpty($pages, 'ledger-empty');

        // ahora lo agrupamos por subcuenta
        $pages = $ledger->generate($ejercicio->idempresa, $ejercicio->fechainicio, $ejercicio->fechafin, ['grouped' => 'S']);
        $this->assertNotEmpty($pages, 'ledger-empty');

        // eliminamos
        $this->assertTrue($asiento->delete(), 'asiento-cant-delete');
    }

    public function testGroupedSubaccountCarriesOpeningBalance()
    {
        $exercise = new \FacturaScripts\Dinamic\Model\Ejercicio();
        $exercise->idempresa = Tools::settings('default', 'idempresa');
        $this->assertTrue($exercise->loadFromDate(Tools::date(), true, false), 'exercise-not-found');
        $openingDate = $exercise->fechainicio;
        $currentDate = date('d-m-Y', strtotime($openingDate . ' +1 day'));

        $opening = new Asiento();
        $opening->concepto = 'Saldo anterior mayor';
        $opening->fecha = $openingDate;
        $this->assertTrue($opening->save(), 'opening-entry-cant-save');

        $line = $opening->getNewLine();
        $line->codsubcuenta = '1000000000';
        $line->concepto = 'Saldo anterior';
        $line->debe = 75;
        $this->assertTrue($line->save(), 'opening-line-cant-save');

        $counterpart = $opening->getNewLine();
        $counterpart->codsubcuenta = '5700000000';
        $counterpart->concepto = 'Contrapartida';
        $counterpart->haber = 75;
        $this->assertTrue($counterpart->save(), 'opening-counterpart-cant-save');

        $current = new Asiento();
        $current->concepto = 'Movimiento actual mayor';
        $current->fecha = $currentDate;
        $this->assertTrue($current->save(), 'current-entry-cant-save');

        $line = $current->getNewLine();
        $line->codsubcuenta = '1000000000';
        $line->concepto = 'Movimiento actual';
        $line->debe = 25;
        $this->assertTrue($line->save(), 'current-line-cant-save');

        $counterpart = $current->getNewLine();
        $counterpart->codsubcuenta = '5700000000';
        $counterpart->concepto = 'Contrapartida actual';
        $counterpart->haber = 25;
        $this->assertTrue($counterpart->save(), 'current-counterpart-cant-save');

        $ledger = new Ledger();
        $pages = $ledger->generate($exercise->idempresa, $currentDate, $currentDate, [
            'grouped' => 'S',
            'subaccount-from' => '1000000000',
            'subaccount-to' => '1000000000',
        ]);

        $this->assertSame('75.00', $pages['1000000000'][0]['saldo']);
        $this->assertSame('100.00', $pages['1000000000'][1]['saldo']);

        $this->assertTrue($current->delete(), 'current-entry-cant-delete');
        $this->assertTrue($opening->delete(), 'opening-entry-cant-delete');
    }
}
