<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2018-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Core\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\Base\ModelCore;
use FacturaScripts\Core\Model\SecuenciaDocumento;
use FacturaScripts\Dinamic\Model\SecuenciaDocumento as DinSecuenciaDocumento;
use RuntimeException;

/**
 * Description of BusinessDocumentCode
 *
 * @author Carlos García Gómez      <carlos@facturascripts.com>
 * @author Juan José Prieto Dzul    <juanjoseprieto88@gmail.com>
 */
class BusinessDocumentCode
{
    const GAP_LIMIT = 1000;

    public static function getNewCode(SecuenciaDocumento &$sequence, BusinessDocument &$document): string
    {
        return CodePatterns::trans($sequence->patron, $document, ['long' => $sequence->longnumero]);
    }

    public static function getOtherExercises(SecuenciaDocumento $sequence): array
    {
        $other = [];

        // find other exercises from equivalent sequences
        $where = [
            new DataBaseWhere('codejercicio', null, 'IS NOT'),
            new DataBaseWhere('codserie', $sequence->codserie),
            new DataBaseWhere('idsecuencia', $sequence->idsecuencia, '<>'),
            new DataBaseWhere('idempresa', $sequence->idempresa),
            new DataBaseWhere('tipodoc', $sequence->tipodoc)
        ];
        foreach ($sequence->all($where) as $item) {
            $other[] = $item->codejercicio;
        }

        return $other;
    }

    public static function getSequence(BusinessDocument $document): SecuenciaDocumento
    {
        $selectedSequence = new DinSecuenciaDocumento();
        $patron = substr(strtoupper($document->modelClassName()), 0, 3) . '{EJE}{SERIE}{NUM}';
        $long = $selectedSequence->longnumero;

        // find sequence for this document and serie
        $sequence = new DinSecuenciaDocumento();
        $where = [
            new DataBaseWhere('codserie', $document->codserie),
            new DataBaseWhere('idempresa', $document->idempresa),
            new DataBaseWhere('tipodoc', $document->modelClassName())
        ];
        $database = new DataBase();
        $sequences = DocumentNumberingSchema::available($document) && $database->inTransaction()
            ? $sequence::allForUpdate($where)
            : $sequence->all($where);
        if ($database->lastError() !== '') {
            throw new RuntimeException('No se pudo leer la secuencia del documento.');
        }
        foreach ($sequences as $seq) {
            if (empty($seq->codejercicio)) {
                // sequence for all exercises
                $selectedSequence = $seq;
            } elseif ($seq->codejercicio == $document->codejercicio) {
                // sequence for this exercise
                return $seq;
            }

            // use old pattern for the new sequence
            $patron = $seq->patron;
            $long = $seq->longnumero;
        }

        // sequence not found? Then create
        if (false === $selectedSequence->exists()) {
            $selectedSequence->codejercicio = $document->codejercicio;
            $selectedSequence->codserie = $document->codserie;
            $selectedSequence->idempresa = $document->idempresa;
            $selectedSequence->patron = $patron;
            $selectedSequence->longnumero = $long;
            $selectedSequence->tipodoc = $document->modelClassName();
            $selectedSequence->usarhuecos = ('FacturaCliente' === $document->modelClassName());
            if (!$selectedSequence->save()) {
                throw new RuntimeException('No se pudo crear la secuencia del documento.');
            }
        }

        return $selectedSequence;
    }

    public static function setNewCode(BusinessDocument &$document, bool $newNumber = true): void
    {
        $sequence = static::getSequence($document);
        if ($newNumber) {
            $document->numero = static::getNewNumber($sequence, $document);
        }

        $document->codigo = static::getNewCode($sequence, $document);
    }

    public static function setNewNumber(BusinessDocument &$document): void
    {
        $sequence = static::getSequence($document);
        $document->numero = static::getNewNumber($sequence, $document);
    }

    protected static function getNewNumber(SecuenciaDocumento &$sequence, BusinessDocument &$document): string
    {
        $previous = static::getPrevious($sequence, $document);

        // find maximum number for this sequence data
        foreach ($previous as $lastDoc) {
            $lastNumber = (int)$lastDoc->numero;
            if ($lastNumber >= $sequence->numero || $sequence->usarhuecos) {
                $sequence->numero = $lastNumber + 1;
            }
            break;
        }

        // use gaps?
        if ($sequence->usarhuecos) {
            // we look for holes back
            $expectedNumber = $sequence->numero - 1;
            $preCodejercicio = $document->codejercicio;
            $preDate = $document->fecha;
            $preHour = $document->hora;
            foreach ($previous as $preDoc) {
                if ($expectedNumber != $preDoc->numero &&
                    $expectedNumber >= $sequence->inicio &&
                    $document->codejercicio == $preCodejercicio) {
                    // hole found
                    $document->fecha = $preDate;
                    $document->hora = $preHour;
                    self::saveSequence($sequence);

                    return (string)$expectedNumber;
                }

                $expectedNumber--;
                $preCodejercicio = $preDoc->codejercicio;
                $preDate = $preDoc->fecha;
                $preHour = $preDoc->hora;
            }

            if (empty($previous)) {
                // no previous document, then use initial number
                $sequence->numero = $sequence->inicio;
            } elseif ($expectedNumber >= $sequence->inicio &&
                $expectedNumber >= $sequence->numero - self::GAP_LIMIT &&
                $document->codejercicio == $preCodejercicio) {
                // the gap is in the first positions of the range
                $document->fecha = $preDate;
                $document->hora = $preHour;
                self::saveSequence($sequence);

                return (string)$expectedNumber;
            }
        }

        $newNumber = $sequence->numero;

        // update sequence
        $sequence->numero++;
        self::saveSequence($sequence);

        return (string)$newNumber;
    }

    protected static function getPrevious(SecuenciaDocumento $sequence, BusinessDocument $document): array
    {
        // El constructor de documentos completos no interviene en esta lectura.
        // El ORM proyecta solo los cuatro valores que utiliza getNewNumber().
        $query = $document::table()->select('numero,codejercicio,fecha,hora')
            ->whereEq('codserie', $sequence->codserie)
            ->whereEq('idempresa', $sequence->idempresa);
        if ($sequence->codejercicio) {
            $query->whereEq('codejercicio', $sequence->codejercicio);
        } else {
            $other = static::getOtherExercises($sequence);
            if (!empty($other)) {
                $query->whereNotIn('codejercicio', $other);
            }
        }
        $optimized = DocumentNumberingSchema::available($document);
        if ($optimized) {
            $query->orderBy(DocumentNumberingSchema::COLUMN, 'DESC');
        } else {
            // Conserva el orden anterior mientras no se haya migrado este tenant.
            $cast = strtolower(FS_DB_TYPE) === 'postgresql' ? 'INTEGER' : 'UNSIGNED';
            $query->orderBy = ['CAST(numero AS ' . $cast . ') DESC'];
        }
        $query->limit($sequence->usarhuecos ? self::GAP_LIMIT : 1);
        $database = new DataBase();
        // La lectura vigente evita reutilizar el snapshot de otra caja. La
        // secuencia se bloquea primero y permanece dentro de la misma transacción.
        $rows = $optimized && $database->inTransaction() ? $query->getForUpdate() : $query->get();
        if ($database->lastError() !== '') {
            throw new RuntimeException('No se pudo comprobar la numeración del documento.');
        }
        $previous = [];
        foreach ($rows as $row) {
            $row['fecha'] = empty($row['fecha']) ? null : date(ModelCore::DATE_STYLE, strtotime($row['fecha']));
            $previous[] = (object)$row;
        }
        return $previous;
    }

    private static function saveSequence(SecuenciaDocumento $sequence): void
    {
        $sequence->disablePatternTest(true);
        try {
            if (!$sequence->save()) {
                throw new RuntimeException('No se pudo actualizar la secuencia del documento.');
            }
        } finally {
            $sequence->disablePatternTest(false);
        }
    }
}
