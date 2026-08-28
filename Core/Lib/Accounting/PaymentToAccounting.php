<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2019-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Core\Lib\Accounting;

use FacturaScripts\Core\Model\Asiento;
use FacturaScripts\Core\Model\PagoCliente;
use FacturaScripts\Core\Model\PagoProveedor;
use FacturaScripts\Core\Model\ReciboCliente;
use FacturaScripts\Core\Model\ReciboProveedor;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Asiento as DinAsiento;
use FacturaScripts\Dinamic\Model\CuentaBanco as DinCuentaBanco;
use FacturaScripts\Dinamic\Model\CuentaEspecial as DinCuentaEspecial;
use FacturaScripts\Dinamic\Model\Ejercicio;

/**
 * Description of PaymentToAccounting
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class PaymentToAccounting
{
    /** @var Ejercicio */
    protected $exercise;

    /** @var PagoCliente|PagoProveedor */
    protected $payment;

    /** @var ReciboCliente|ReciboProveedor */
    protected $receipt;

    public function __construct()
    {
        $this->exercise = new Ejercicio();
    }

    /**
     * @param PagoCliente|PagoProveedor $payment
     * @return bool
     */
    public function generate($payment): bool
    {
        // comprobaciones iniciales
        switch ($payment->modelClassName()) {
            case 'PagoCliente':
            case 'PagoProveedor':
                $this->payment = $payment;
                $this->receipt = $payment->getReceipt();
                $this->exercise->idempresa = $this->receipt->idempresa;
                if (false === $this->exercise->loadFromDate($this->payment->fecha)) {
                    Tools::log()->warning('closed-exercise', [
                        '%exerciseName%' => $this->exercise->codejercicio
                    ]);
                    return false;
                }
                if (false === $this->exercise->hasAccountingPlan()) {
                    Tools::log()->warning('exercise-without-accounting-plan', [
                        '%exercise%' => $this->exercise->codejercicio
                    ]);
                    return false;
                }
                $periodClass = '\\FacturaScripts\\Plugins\\SpiderAccounting\\Model\\PeriodoContable';
                if (class_exists($periodClass)
                    && false === $periodClass::isDateOpen((int)$this->receipt->idempresa, $this->payment->fecha)) {
                    Tools::log()->warning('closed-accounting-period');
                    return false;
                }
                break;
        }

        switch ($payment->modelClassName()) {
            case 'PagoCliente':
                return $this->customerPaymentAccountingEntry();

            case 'PagoProveedor':
                return $this->supplierPaymentAccountingEntry();
        }

        return false;
    }

    protected function customerPaymentAccountingEntry(): bool
    {
        // creamos el asiento
        $entry = new DinAsiento();

        $concept = $this->payment->importe > 0 ?
            Tools::lang()->trans('customer-payment-concept', ['%document%' => $this->receipt->getCode()]) :
            Tools::lang()->trans('refund-payment-concept', ['%document%' => $this->receipt->getCode()]);

        $invoice = $this->receipt->getInvoice();
        $concept .= $invoice->numero2 ?
            ' (' . $invoice->numero2 . ') - ' . $invoice->nombrecliente :
            ' - ' . $invoice->nombrecliente;

        $this->setCommonData($entry, $concept, $invoice);
        $entry->importe = $this->customerEntryAmount();
        if (false === $entry->save()) {
            Tools::log()->warning('accounting-entry-error');
            return false;
        }

        // Add lines and save accounting entry relation
        if ($this->customerPaymentLine($entry)
            && $this->customerPaymentBankLine($entry)
            && $this->customerPaymentExpenseLine($entry)
            && $this->paymentExchangeDifferenceLine($entry)
            && $entry->isBalanced()) {
            $this->payment->idasiento = $entry->primaryColumnValue();
            return true;
        }

        Tools::log()->warning('accounting-lines-error');
        $entry->delete();
        return false;
    }

    protected function customerPaymentBankLine(Asiento &$entry): bool
    {
        $account = $this->getTreasuryAccount(false);
        if (false === $account->exists()) {
            return false;
        }

        // In FacturaScripts, customer receipt expenses are charged to the
        // customer and therefore increase the amount received by treasury.
        // Bank fees borne by the company are posted from SpiderBanks instead.
        $amount = $this->functionalAmount($this->payment->importe)
            + abs($this->functionalAmount($this->payment->gastos));

        $newLine = $entry->getNewLine($account);
        $newLine->debe = max($amount, 0);
        $newLine->haber = $amount < 0 ? abs($amount) : 0;
        $this->setCurrencyData($newLine);
        return $newLine->save();
    }

    protected function customerPaymentExpenseLine(Asiento &$entry): bool
    {
        if (empty($this->payment->gastos)) {
            return true;
        }

        $account = $this->getTreasuryAccount(true);
        if (false === $account->exists()) {
            return false;
        }

        $expLine = $entry->getNewLine($account);
        $expLine->concepto = Tools::lang()->trans('receipt-expense-account', ['%document%' => $entry->documento]);
        $expLine->haber = abs($this->functionalAmount($this->payment->gastos));
        $this->setCurrencyData($expLine);
        return $expLine->save();
    }

    protected function customerPaymentLine(Asiento &$entry): bool
    {
        $account = $this->receipt->getSubject()->getSubcuenta($this->exercise->codejercicio, true);
        if (false === $account->exists()) {
            return false;
        }

        $newLine = $entry->getNewLine($account);
        $amount = $this->invoiceFunctionalAmount($this->payment->importe);
        $newLine->debe = $amount < 0 ? abs($amount) : 0;
        $newLine->haber = max($amount, 0);
        $this->setInvoiceCurrencyData($newLine);
        return $newLine->save();
    }

    protected function supplierPaymentAccountingEntry(): bool
    {
        // Create account entry header
        $entry = new DinAsiento();

        $concept = $this->payment->importe > 0 ?
            Tools::lang()->trans('supplier-payment-concept', ['%document%' => $this->receipt->getCode()]) :
            Tools::lang()->trans('refund-payment-concept', ['%document%' => $this->receipt->getCode()]);

        $invoice = $this->receipt->getInvoice();
        $concept .= $invoice->numproveedor ?
            ' (' . $invoice->numproveedor . ') - ' . $invoice->nombre :
            ' - ' . $invoice->nombre;

        $this->setCommonData($entry, $concept, $invoice);
        if (false === $entry->save()) {
            Tools::log()->warning('accounting-entry-error');
            return false;
        }

        // Add lines and save accounting entry relation
        if ($this->supplierPaymentLine($entry)
            && $this->supplierPaymentBankLine($entry)
            && $this->paymentExchangeDifferenceLine($entry)
            && $entry->isBalanced()) {
            $this->payment->idasiento = $entry->primaryColumnValue();
            return true;
        }

        Tools::log()->warning('accounting-lines-error');
        $entry->delete();
        return false;
    }

    protected function supplierPaymentBankLine(Asiento &$entry): bool
    {
        $account = $this->getTreasuryAccount(false);
        if (false === $account->exists()) {
            return false;
        }

        $newLine = $entry->getNewLine($account);
        $amount = $this->functionalAmount($this->payment->importe);
        $newLine->debe = $amount < 0 ? abs($amount) : 0;
        $newLine->haber = max($amount, 0);
        $this->setCurrencyData($newLine);
        return $newLine->save();
    }

    protected function supplierPaymentLine(Asiento &$entry): bool
    {
        $account = $this->receipt->getSubject()->getSubcuenta($this->exercise->codejercicio, true);
        if (false === $account->exists()) {
            return false;
        }

        $newLine = $entry->getNewLine($account);
        $amount = $this->invoiceFunctionalAmount($this->payment->importe);
        $newLine->debe = max($amount, 0);
        $newLine->haber = $amount < 0 ? abs($amount) : 0;
        $this->setInvoiceCurrencyData($newLine);
        return $newLine->save();
    }

    protected function setCommonData(Asiento &$entry, string $concept, $invoice): void
    {
        $entry->codejercicio = $this->exercise->codejercicio;
        $entry->concepto = $concept;
        $entry->documento = $invoice->codigo;
        $entry->canal = $invoice->getSerie()->canal;
        $entry->fecha = $this->payment->fecha;
        $entry->idempresa = $this->exercise->idempresa;
        $entry->importe = max(
            abs($this->functionalAmount($this->payment->importe)),
            abs($this->invoiceFunctionalAmount($this->payment->importe))
        );
    }

    protected function customerEntryAmount(): float
    {
        $bankAmount = $this->functionalAmount($this->payment->importe)
            + abs($this->functionalAmount($this->payment->gastos));
        $subjectAmount = $this->invoiceFunctionalAmount($this->payment->importe);
        $expenses = abs($this->functionalAmount($this->payment->gastos));

        $debit = max($bankAmount, 0.0) + ($subjectAmount < 0.0 ? abs($subjectAmount) : 0.0);
        $credit = ($bankAmount < 0.0 ? abs($bankAmount) : 0.0)
            + max($subjectAmount, 0.0)
            + $expenses;
        return max($debit, $credit);
    }

    protected function functionalAmount($amount): float
    {
        $rate = property_exists($this->payment, 'tasaconv') ? (float)$this->payment->tasaconv : 1.0;
        if ($rate <= 0) {
            $rate = 1.0;
        }
        return round((float)$amount / $rate, FS_NF0);
    }

    protected function invoiceFunctionalAmount($amount): float
    {
        $invoice = $this->receipt->getInvoice();
        $rate = property_exists($invoice, 'tasaconv') ? (float)$invoice->tasaconv : 1.0;
        if ($rate <= 0) {
            $rate = 1.0;
        }
        return round((float)$amount / $rate, FS_NF0);
    }

    protected function paymentExchangeDifferenceLine(Asiento &$entry): bool
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($entry->getLines() as $line) {
            $debit += (float)$line->debe;
            $credit += (float)$line->haber;
        }

        $difference = round($debit - $credit, FS_NF0);
        if (abs($difference) < 0.005) {
            return true;
        }

        // Excess debit is an exchange gain (credit); excess credit is an
        // exchange loss (debit). This also works for refunds and supplier
        // collections because it balances the economic direction already
        // represented by the subject and bank lines.
        $specialCode = $difference > 0 ? 'CAMPOS' : 'CAMNEG';
        $special = new DinCuentaEspecial();
        if (false === $special->loadFromCode($specialCode)) {
            Tools::log()->warning('exchange-difference-account-not-found', ['%account%' => $specialCode]);
            return false;
        }
        $account = $special->getSubcuenta($this->exercise->codejercicio);
        if (false === $account->exists()) {
            Tools::log()->warning('exchange-difference-account-not-found', ['%account%' => $specialCode]);
            return false;
        }

        $line = $entry->getNewLine($account);
        $line->concepto = Tools::lang()->trans('realized-exchange-difference');
        $line->debe = $difference < 0 ? abs($difference) : 0.0;
        $line->haber = $difference > 0 ? $difference : 0.0;
        return $line->save();
    }

    protected function getTreasuryAccount(bool $expenses)
    {
        $method = $this->payment->getPaymentMethod();
        $bankCode = property_exists($this->payment, 'codcuentabanco')
            ? (string)$this->payment->codcuentabanco
            : '';
        if ('' === $bankCode && property_exists($this->receipt, 'codcuentabanco')) {
            $bankCode = (string)$this->receipt->codcuentabanco;
        }

        if ('' !== $bankCode) {
            $bank = new DinCuentaBanco();
            if (false === $bank->loadFromCode($bankCode) || (int)$bank->idempresa !== (int)$this->receipt->idempresa) {
                Tools::log()->warning('invalid-payment-bank-account');
                return new \FacturaScripts\Dinamic\Model\Subcuenta();
            }
            return $expenses
                ? $bank->getSubcuentaGastos($this->exercise->codejercicio, true)
                : $bank->getSubcuenta($this->exercise->codejercicio, true);
        }

        // Backwards-compatible default. The payment method still owns the
        // default bank; a payment/receipt can override it when SpiderPagos is active.
        return $expenses
            ? $method->getSubcuentaGastos($this->exercise->codejercicio, true)
            : $method->getSubcuenta($this->exercise->codejercicio, true);
    }

    protected function setCurrencyData($line): void
    {
        if (property_exists($this->payment, 'coddivisa') && !empty($this->payment->coddivisa)) {
            $line->coddivisa = $this->payment->coddivisa;
        }
        if (property_exists($this->payment, 'tasaconv') && (float)$this->payment->tasaconv > 0) {
            $line->tasaconv = (float)$this->payment->tasaconv;
        }
    }

    protected function setInvoiceCurrencyData($line): void
    {
        $invoice = $this->receipt->getInvoice();
        if (property_exists($invoice, 'coddivisa') && !empty($invoice->coddivisa)) {
            $line->coddivisa = $invoice->coddivisa;
        }
        if (property_exists($invoice, 'tasaconv') && (float)$invoice->tasaconv > 0) {
            $line->tasaconv = (float)$invoice->tasaconv;
        }
    }
}
