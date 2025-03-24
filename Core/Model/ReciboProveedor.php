<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2019-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Core\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaProveedor as DinFacturaProveedor;
use FacturaScripts\Dinamic\Model\PagoProveedor as DinPagoProveedor;
use FacturaScripts\Dinamic\Model\Proveedor as DinProveedor;

/**
 * Description of ReciboProveedor
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ReciboProveedor extends Base\Receipt
{
    use Base\ModelTrait;

    /** @var string */
    public $codproveedor;

    /** @var string */
    public $codcuentabanco;

    public function getInvoice(): DinFacturaProveedor
    {
        $invoice = new DinFacturaProveedor();
        $invoice->loadFromCode($this->idfactura);
        return $invoice;
    }

    /**
     * Returns all payment history for this receipt
     *
     * @return DinPagoProveedor[]
     */
    public function getPayments(): array
    {
        $payModel = new DinPagoProveedor();
        $where = [new DataBaseWhere('idrecibo', $this->idrecibo)];
        $orderBy = ['fecha' => 'DESC', 'hora' => 'DESC', 'idpago' => 'DESC'];
        return $payModel->all($where, $orderBy, 0, 0);
    }

    public function getSubject(): DinProveedor
    {
        $proveedor = new DinProveedor();
        $proveedor->loadFromCode($this->codproveedor);
        return $proveedor;
    }

    public function install(): string
    {
        // needed dependencies
        new Proveedor();

        return parent::install();
    }

    public static function tableName(): string
    {
        return 'recibospagosprov';
    }

    public function url(string $type = 'auto', string $list = 'ListFacturaProveedor?activetab=List'): string
    {
        if ('list' === $type && !empty($this->idfactura)) {
            return $this->getInvoice()->url() . '&activetab=List' . $this->modelClassName();
        }

        return parent::url($type, $list);
    }

    /**
     * Creates a new payment for this receipt.
     *
     * @return bool
     */
    protected function newPayment(): bool
    {
        if ($this->disablePaymentGeneration) {
            return false;
        }

        $pago = new DinPagoProveedor();
        $pago->codpago = $this->codpago;
        $pago->fecha = $this->fechapago ?? $pago->fecha;
        $pago->idrecibo = $this->idrecibo;
        $pago->importe = $this->pagado ? $this->importe : 0 - $this->importe;
        $pago->nick = $this->nick;
        return $pago->save();
    }

    /**
     * Returns the bank account associated with this receipt
     * 
     * @return CuentaBanco|null
     */
    public function getBankAccount(): ?CuentaBanco
    {
        if (empty($this->codcuentabanco)) {
            return null;
        }

        $bank = new CuentaBanco();
        $bank->loadFromCode($this->codcuentabanco);
        return $bank;
    }

    /**
     * Returns the bank account subaccount for this receipt
     * 
     * @param string $codejercicio
     * @param bool $create
     * @return Subcuenta
     */
    public function getBankSubaccount(string $codejercicio, bool $create = false): Subcuenta
    {
        $bank = $this->getBankAccount();
        if ($bank === null) {
            return new Subcuenta();
        }

        return $bank->getSubcuenta($codejercicio, $create);
    }

    /**
     * Returns the bank account expenses subaccount for this receipt
     * 
     * @param string $codejercicio
     * @param bool $create
     * @return Subcuenta
     */
    public function getBankExpensesSubaccount(string $codejercicio, bool $create = false): Subcuenta
    {
        $bank = $this->getBankAccount();
        if ($bank === null) {
            return new Subcuenta();
        }

        return $bank->getSubcuentaGastos($codejercicio, $create);
    }

    public function test(): bool
    {
        if (empty($this->idempresa)) {
            $this->idempresa = Tools::settings('default', 'idempresa');
        }

        $this->codproveedor = Tools::noHtml($this->codproveedor);
        $this->codcuentabanco = Tools::noHtml($this->codcuentabanco);

        return parent::test();
    }
}
