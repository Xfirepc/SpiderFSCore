<?php

namespace FacturaScripts\Core\Lib\Inventory;

use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Core\Model\Stock;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use ReflectionObject;
use RuntimeException;
use Throwable;

/** One transaction for the source, balances and optional stock journal. */
final class Inventory
{
    private static $depth = 0;
    private static $contexts = [];
    private static $events = [];
    private static $recorder;
    private static $batchRecorder;
    private static $prepare;
    private static $schemaPrepared = false;
    private static $preparingSchema = false;
    private static $catalogReads = [];
    private static $stockReads = [];
    private static $stockRows = [];
    private static $operation;
    private static $failed = false;
    private static $finishing = false;
    private static $snapshots = [];
    private static $afterCommit = [];
    private static $afterRollback = [];
    private static $committedCallbacks = [];
    private static $checks = [];
    private static $insertedSources = [];

    public static function checkBeforeCommit(string $key, callable $check): void
    {
        self::$checks[$key] = $check;
    }

    public static function transactionStarted(): void
    {
        StockTotals::clear();
        self::$catalogReads = [];
        self::$stockReads = [];
        self::$stockRows = [];
        self::$insertedSources = [];
        self::$snapshots = [];
        self::$afterCommit = [];
        self::$afterRollback = [];
        WorkQueue::transactionStarted();
    }

    public static function transactionEnded(bool $committed): void
    {
        StockTotals::clear();
        self::$catalogReads = [];
        self::$stockReads = [];
        self::$stockRows = [];
        self::$insertedSources = [];
        WorkQueue::transactionEnded($committed);
        if (!$committed) {
            foreach (self::$snapshots as $entry) {
                list($model, $copy) = $entry;
                $reflection = new ReflectionObject($copy);
                do {
                    foreach ($reflection->getProperties() as $property) {
                        if ($property->isStatic()) {
                            continue;
                        }

                        $property->setAccessible(true);
                        // PHP 7.3 has no typed properties or isInitialized().
                        if (method_exists($property, 'isInitialized') && !$property->isInitialized($copy)) {
                            continue;
                        }

                        $property->setValue($model, $property->getValue($copy));
                    }
                } while ($reflection = $reflection->getParentClass());
                foreach (get_object_vars($copy) as $name => $value) {
                    $model->{$name} = $value;
                }
            }
            // Undo dependent in-memory results in the reverse order of registration.
            foreach (array_reverse(self::$afterRollback) as $callback) {
                $callback();
            }
        } else {
            self::$committedCallbacks = array_merge(self::$committedCallbacks, self::$afterCommit);
        }

        self::$snapshots = self::$afterCommit = self::$afterRollback = [];
        if (!self::active()) {
            self::dispatchCommitted();
        }
    }

    public static function afterCommit(callable $callback): void
    {
        if (self::active()) {
            self::$afterCommit[] = $callback;
        } else {
            $callback();
        }
    }

    public static function afterRollback(callable $callback): void
    {
        self::requireSuccess(self::active(), 'La restauración requiere una operación de inventario activa.');
        self::$afterRollback[] = $callback;
    }

    private static function dispatchCommitted(): void
    {
        $callbacks = self::$committedCallbacks;
        self::$committedCallbacks = [];
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $error) {
                Tools::log()->warning($error->getMessage());
            }
        }
    }

    public static function finishing(): bool
    {
        return self::$finishing;
    }

    public static function registerRecorder(callable $recorder, callable $prepare, ?callable $batchRecorder = null): void
    {
        self::$recorder = $recorder;
        self::$prepare = $prepare;
        self::$batchRecorder = $batchRecorder;
        self::$schemaPrepared = false;
    }

    /** Prepare before even an external transaction starts; DDL can commit it. */
    public static function prepareSchema(): void
    {
        if (!self::$prepare || self::$schemaPrepared || self::$preparingSchema) {
            return;
        }

        self::$preparingSchema = true;
        try {
            call_user_func(self::$prepare);
            self::$schemaPrepared = true;
        } finally {
            self::$preparingSchema = false;
        }
    }

    /** Only rows already read FOR UPDATE in this transaction may be reused. */
    public static function rememberCatalogRead(string $table, array $row): void
    {
        self::requireSuccess(self::active(), 'La reutilización requiere una operación de inventario activa.');
        if ($table === 'productos' && isset($row['idproducto'])) {
            self::$catalogReads[$table][(string)$row['idproducto']] = $row;
        } elseif ($table === 'variantes' && isset($row['referencia'])) {
            self::$catalogReads[$table][$row['referencia']] = $row;
        }
    }

    public static function catalogRead(string $table, $key): ?array
    {
        return self::$catalogReads[$table][(string)$key] ?? null;
    }

    /** Any write, including raw SQL and nested hooks, expires these read hints. */
    public static function forgetCatalogReads(): void
    {
        self::$catalogReads = [];
        self::$stockReads = [];
        self::$stockRows = [];
    }

    /** Only a complete, unambiguous row already read FOR UPDATE is reusable. */
    public static function rememberStockRow(array $row): void
    {
        self::requireSuccess(self::active(), 'La reutilización requiere una operación de inventario activa.');
        self::$stockRows[$row['codalmacen']][$row['referencia']] = $row;
    }

    public static function stockRow(string $reference, string $warehouse): ?array
    {
        return self::active() ? (self::$stockRows[$warehouse][$reference] ?? null) : null;
    }

    public static function rememberStockRead(array $row): void
    {
        if (isset($row['idstock'])) {
            self::$stockReads[(string)$row['idstock']] = true;
        }
    }

    public static function stockWasRead($id): bool
    {
        return self::active() && isset(self::$stockReads[(string)$id]);
    }

    /** Keep the header used to calculate this line's effect with its context. */
    public static function setContextDocument(BusinessDocumentLine $line, $document): void
    {
        foreach (self::$contexts as $index => $context) {
            if ($context[0] === $line) {
                self::$contexts[$index][2] = $document;
            }
        }
    }

    /** Persistence notifications precede hooks, including nested stock writes. */
    public static function modelPersisted($model, array $values = [], bool $deleted = false, bool $autoInserted = false): void
    {
        if ($deleted) {
            unset(self::$insertedSources[$model::tableName()][(string)$model->primaryColumnValue()]);
        }
        if ($autoInserted && self::active()) {
            self::$insertedSources[$model::tableName()][(string) $model->primaryColumnValue()] = true;
        }
        if ($model instanceof Stock) {
            StockTotals::record($model, $values, $deleted);
        } elseif (in_array($model::tableName(), ['productos', 'variantes'], true)) {
            // Foreign-key cascades can rename, move or remove cached stock rows.
            StockTotals::clear();
        }
    }

    public static function sourceWasInserted($source): bool
    {
        return isset(self::$insertedSources[$source::tableName()][(string) $source->primaryColumnValue()]);
    }

    public static function modelIdentityChanged(): void
    {
        StockTotals::clear();
        self::$catalogReads = [];
        self::$stockReads = [];
        self::$stockRows = [];
        self::$insertedSources = [];
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function operationId(): ?string
    {
        return self::$operation;
    }

    public static function applies($model): bool
    {
        return $model instanceof BusinessDocument || $model instanceof BusinessDocumentLine
            || in_array($model::tableName(), ['stocks', 'stocks_conteos', 'stocks_lineasconteos',
                'stocks_transferencias', 'stocks_lineastransferencias', 'serviciosat_trabajos', 'serviciosat',
                'gym_recargos', 'productos', 'variantes'], true);
    }

    public static function model($db, $model, callable $action, string $kind): bool
    {
        if (!self::active() && !self::applies($model)) {
            return $action();
        }

        return self::run($db, function () use ($model, $action): bool {
            if (self::applies($model) && !($model instanceof Stock)) {
                $model->lockInventorySource();
            }

            return $action();
        }, $model, $kind);
    }

    /** Nested failures poison the enclosing transaction, even if a legacy hook ignores false. */
    public static function run($db, callable $action, $source = null, string $kind = 'save'): bool
    {
        $canRetry = !self::active() && !$db->inTransaction();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $done = self::attempt($db, $action, $source, $kind);
            if ($done || !$canRetry || !method_exists($db, 'inventoryRetryable') || !$db->inventoryRetryable()) {
                return $done;
            }

            usleep(10000 * ($attempt + 1));
        }

        return false;
    }

    private static function attempt($db, callable $action, $source, string $kind): bool
    {
        $root = !self::active();
        $owner = !$db->inTransaction();
        if ($root) {
            self::prepareSchema();

            self::$events = [];
            self::$checks = [];
            self::$failed = false;
            self::$operation = bin2hex(random_bytes(16));
        }

        if ($owner && !$db->beginTransaction()) {
            return false;
        }

        if (is_object($source) && !isset(self::$snapshots[spl_object_hash($source)])) {
            self::$snapshots[spl_object_hash($source)] = [$source, clone $source];
        }

        self::$depth++;
        self::$contexts[] = [$source, $kind];
        $done = false;
        try {
            $done = $action() !== false && !self::$failed;
            if ($done && $root) {
                foreach (self::$checks as $check) {
                    self::requireSuccess($check() !== false);
                }
            }

            if ($done && $root && self::$recorder) {
                foreach (array_keys(self::$events) as $index) {
                    self::$events[$index]['operation'] = self::$operation;
                    self::$events[$index]['effect'] = $index + 1;
                }
                if (self::$batchRecorder) {
                    self::requireSuccess(
                        false !== call_user_func(self::$batchRecorder, self::$events),
                        'No se pudo guardar el movimiento de inventario.'
                    );
                } else {
                    foreach (self::$events as $event) {
                        self::requireSuccess(
                            false !== call_user_func(self::$recorder, $event),
                            'No se pudo guardar el movimiento de inventario.'
                        );
                    }
                }
            }

            if ($done && $owner) {
                self::$finishing = true;
                try {
                    $done = $db->commit();
                } finally {
                    self::$finishing = false;
                }
            }

            return $done;
        } catch (Throwable $error) {
            $done = false;
            Tools::log()->warning($error->getMessage());
            return false;
        } finally {
            if (!$done) {
                self::$failed = true;
                if ($owner) {
                    self::$finishing = true;
                    try {
                        $db->rollback();
                    } finally {
                        self::$finishing = false;
                    }
                } elseif (method_exists($db, 'markRollbackOnly')) {
                    $db->markRollbackOnly();
                }
            }

            array_pop(self::$contexts);
            self::$depth--;
            if ($root) {
                self::$events = [];
                self::$checks = [];
                self::dispatchCommitted();
            }
        }
    }

    public static function record(Stock $stock, array $before, array $after): void
    {
        if ($before == $after || !self::$recorder) {
            return;
        }

        $source = $stock;
        $kind = 'adjustment';
        $document = null;
        foreach (self::$contexts as $context) {
            list($contextSource, $contextKind) = $context;
            if ($contextSource === null && $contextKind !== 'save') {
                $kind = $contextKind;
            }

            if ($contextSource instanceof Stock) {
                if ($source instanceof Stock && $contextKind !== 'save') {
                    $kind = $contextKind;
                }
                continue;
            }

            if ($contextSource !== null) {
                $source = $contextSource;
                $kind = $contextKind;
                $document = $context[2] ?? null;
            }
        }

        self::$events[] = [
            'source' => $source, 'kind' => $kind, 'idproducto' => $stock->idproducto,
            'document' => $source instanceof BusinessDocumentLine ? ($document ?? $source->getDocument()) : null,
            'referencia' => $stock->referencia, 'codalmacen' => $stock->codalmacen,
            'before' => $before, 'after' => $after,
        ];
    }

    public static function requireSuccess(bool $success, string $message = 'No se pudo actualizar el inventario.'): void
    {
        if (!$success) {
            throw new RuntimeException($message);
        }
    }
}
