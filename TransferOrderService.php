<?php

namespace SomePath\BackOffice\SomePath\TransferFlow\Services;

use Illuminate\Database\Eloquent\Relations\HasMany;
use StockModel;
use SomePath\BackOffice\Services\EDI\StockQueue\Repositories\OutgoingStockQueueRepository;
use Psr\Log\LogLevel;
use SomePath\BackOffice\SomePath\TransferFlow\Presenters\TransferLinePresenter;
use SomePath\BackOffice\SomePath\TransferFlow\Validators\WhiteListFilterColumnsValidator;
use SomePath\BackOffice\SomePath\TransferFlow\Repositories\ProductRepositoryInterface;
use SomePath\BackOffice\SomePath\TransferFlow\Repositories\TransferLineRepositoryInterface;
use SomePath\BackOffice\SomePath\TransferFlow\Repositories\TransferOrderRepositoryInterface;
use SomePath\BackOffice\SomePath\TransferFlow\Types\TransferStepType;
use SomePath\BackOffice\SomePath\TransferFlow\Validators\TransferOrderValidator;
use SomePath\Core\Classes\Common\ActionResult;
use SomePath\Log\Messages\Message;
use SomePath\OrdersProcessor\Transfer\Entity\TransferOrder;
use AccountModel;
use DB;
use SomePath\OrdersProcessor\Transfer\Events\EDIOrderUpdatedEvent;
use SomePath\SomePath\DispatchModel;
use TransferLineModel;
use TransferOrderModel;
use Exception;

class TransferOrderService implements TransferOrderServiceInterface
{
    /** @var TransferLineRepositoryInterface */
    private $transferLineRepository;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var TransferOrderRepositoryInterface */
    private $transferOrderRepository;

    /**
     * @param TransferLineRepositoryInterface  $transferLineRepository
     * @param ProductRepositoryInterface       $productRepository
     * @param TransferOrderRepositoryInterface $transferOrderRepository
     */
    public function __construct(
        TransferLineRepositoryInterface $transferLineRepository,
        ProductRepositoryInterface      $productRepository,
        TransferOrderRepositoryInterface $transferOrderRepository
    )
    {
        $this->transferLineRepository = $transferLineRepository;
        $this->productRepository = $productRepository;
        $this->transferOrderRepository = $transferOrderRepository;
    }

    /**
     * @param array $data
     * @return void
     */
    public function updateLines(array $data, TransferOrderModel $transferOrder)
    {
        $insertLines = [];

        foreach ($data as $item) {
            $existLine = $transferOrder->getLines()->find($item['id']);

            if ($existLine->quantity < $item['quantity']) {
                // create new line when quantity increased (avoid stock queue recalculate)
                $item['quantity'] = $item['quantity'] - $existLine->quantity;
                $item['quantity_received'] = 0;
                $insertLines[] = $item;
                continue;
            }

            $this->transferLineRepository->update($item['id'], [
                'quantity' => $item['quantity'],
                'quantity_received' => $item['quantity_received'],
                'quantity_initial' => $existLine->quantity
            ]);
        }

        $this->insertLines($insertLines, $transferOrder);
    }

    /**
     * @param array $data
     * @param TransferOrderModel $transferOrder
     * @return void
     */
    public function insertLines(array $data, TransferOrderModel $transferOrder)
    {
        foreach ($data as $item) {
            $newLine = $this->createTransferOrderLineWithProductInfo($item, $transferOrder)->toArray();
            $this->transferLineRepository->create($newLine);
        }
    }

    /**
     * @param array $data
     * @param TransferOrderModel $transferOrder
     * @return void
     */
    public function deleteLines(array $data, TransferOrderModel $transferOrder)
    {
        $this->transferLineRepository->destroy(array_column($data, 'id'));
    }

    /**
     * @param array $attributes
     * @param TransferOrderModel $transferOrder
     * @return TransferLineModel
     */
    public function addLineItemRequest(
        array              $attributes,
        TransferOrderModel $transferOrder
    ): TransferLineModel
    {
        $product = $this->productRepository->findBy('artno', $attributes['item_no']);
        $attributes['pid'] = $product->pid;
        $attributes['quantity_initial'] = 0;

        return $this->createTransferOrderLineWithProductInfo($attributes, $transferOrder);
    }

    /**
     * @param int $id
     * @param string|null $sortBy
     * @param string|null $sortMethod
     * @param string|null $filterColumn
     * @param string|null $filterValue
     * @return TransferOrderModel|null
     */
    public function getTransferOrderForDetailsTab(
        int     $id,
        ?string $filterColumn,
        ?string $filterValue,
        ?string $sortBy = 'id',
        ?string $sortMethod = 'asc'
    ): ?TransferOrderModel
    {
        return $this->transferOrderRepository->newQuery()
            ->where('id', '=', $id)->with([
                'transferLines' => function ($query) use ($filterColumn, $filterValue, $sortBy, $sortMethod) {
                    $query->with(['outgoingStockQueueEntry']);

                    $this->withFilteringBuilder($query, $filterColumn, $filterValue);

                    $query->orderBy($sortBy, $sortMethod);
                }
            ])->first();
    }

    /**
     * @param int $lineQuantity
     * @param int $pid
     * @param int $storageId
     * @return int[]
     */
    public function calculateLineStock(int $lineQuantity, int $pid, int $storageId) : array
    {
        $quantities = [
            'remaining' => 0,
            'reserved' => 0
        ];
        $stock_queue = OutgoingStockQueueRepository::where('product_id', $pid)
            ->where('storage_id', $storageId)
            ->latest('position')
            ->first();

        $stock = (int) ($stock_queue ? $stock_queue->quantity_remaining : StockModel::getStock($pid, $storageId));
        $quantities['remaining'] = $stock - $lineQuantity;
        $quantities['reserved'] = $quantities['remaining'] >= 0 ? $lineQuantity : ($stock <= 0 ? 0 : $stock);

        return $quantities;
    }

    /**
     * @param string $orderNo
     * @param string $shipmentNo
     *
     * @return string|null
     */
    public function getTrackingCodeForDispatch(string $orderNo, string $shipmentNo): ?string
    {
        $SomePathDispatchModel = DispatchModel::where('order_no', $orderNo)->where('shipment_no', $shipmentNo)->first();
        return $SomePathDispatchModel->tracking_code ?? null;
    }

    /**
     * @param array $attributes
     * @param TransferOrderModel $transferOrder
     * @return TransferLineModel
     */
    private function createTransferOrderLineWithProductInfo(
        array              $attributes,
        TransferOrderModel $transferOrder
    ): TransferLineModel
    {
        $product = $this->productRepository->find($attributes['pid']);

        return $this->transferLineRepository->make([
            'order_id' => $transferOrder->id,
            'order_no' => $transferOrder->order_no,
            'item_no' => $product->itemNo,
            'pid' => $product->pid,
            'name' => $product->name_se,
            'manufacturer_item_no' => $product->manufacturers_artno,
            'unit' => 'Piece',
            'quantity' => $attributes['quantity'] ?? 0,
            'quantity_received' => $attributes['quantity_received'] ?? 0,
            'line_no' => $this->getNewTransferOrderLineNo($transferOrder),
            'created_at' => now(),
            'quantity_initial' => $attributes['quantity_initial'] ?? 0,
        ]);
    }

    /**
     * @param TransferOrderModel $transferOrder
     * @return int
     */
    private function getNewTransferOrderLineNo(TransferOrderModel $transferOrder): int
    {
        return $transferOrder->transferLines()->withTrashed()->max('line_no') + 10000;
    }

    /**
     * Cancels the order
     *
     * @param int               $id      Order id
     * @param string|null       $message Message
     * @param AccountModel|null $user    User account
     *
     * @return ActionResult
     */
    public function cancelTransferOrder(int $id, ?string $message, ?AccountModel $user): ActionResult
    {
        $result = new ActionResult(false);
        /** @var TransferOrder $transferOrder */
        $transferOrder = $this->transferOrderRepository->find($id, ['transferLines.product']);
        if (empty($transferOrder)) {
            $result->setErrors(['Transfer order missing.']);

            return $result;
        }

        $validator = new TransferOrderValidator($transferOrder);
        if ($validator->fails()) {
            $result->setErrors($validator->getErrors()->getMessages());

            return $result;
        }

        try {
            $name    = optional($user)->getName();
            $context = [
                'additional' => [
                    'user'  => optional($user)->getName() ?? '',
                    'email' => optional($user)->email ?? '',
                    'uid'   => optional($user)->id ?? AccountModel::SYSTEM_USER,
                    'lines' => [],
                ]
            ];
            DB::transaction(function () use ($transferOrder, $context, $message) {
                /** @var TransferLineModel $line */
                foreach ($transferOrder->transferLines as $line) {
                    $context['additional']['lines'][] = (new TransferLinePresenter($line))->present();
                    $line->delete();
                }
                $this->transferOrderRepository->update($transferOrder->id, ['step' => TransferStepType::CANCEL]);

                $message = new Message(LogLevel::INFO, 'The order has been canceled. ' . $message, $context);
                $message
                    ->addLabels(['TO', 'CANCEL_TO'])
                    ->attachEntities([$transferOrder])
                    ->addWriteDriver('legacy_order');
                logMe($message);
            });
            $transferOrder->load('transferLines');
            event(new EDIOrderUpdatedEvent($transferOrder));
            $result->setSuccess(true);
        } catch (Exception $e) {
            $result->setErrors(['An error occurred while canceling an order.']);
            report($e);
        }

        return $result;
    }

    /**
     * Calculate Quantity Received from all documents related to a TransferOrderLine
     * @param TransferReceiveLine $receiveLines
     * @return int
     */
    public function calculateQuantityReceived(TransferReceiveLine $receiveLines): int
    {
        return $receiveLines->newQuery()
            ->where('Transfer Order No_', $receiveLines->{'Transfer Order No_'})
            ->where('Line No_', $receiveLines->{'Line No_'})
            ->sum('Quantity');
    }


    /**
     * @param string $receivedDocumentNo
     * @return TransferOrder|null
     */
    public function findTransferOrderByReceiveDocumentNoFromERP(string $receivedDocumentNo): ?TransferOrder
    {
        $importingTransferReceiveLine = TransferReceiveLine::where('Document No_', $receivedDocumentNo)->first();
        return $this->transferOrderRepository->findBy('order_no', $importingTransferReceiveLine->{'Transfer Order No_'});
    }

    /**
     * @param $query
     * @param $filterColumn
     * @param $filterValue
     * @return HasMany|null
     */
    private function withFilteringBuilder(?HasMany $query, ?string $filterColumn, ?string $filterValue): ?HasMany
    {
        if ($filterColumn === null || $filterValue === null || (new WhiteListFilterColumnsValidator($filterColumn))->validate()) {
            return null;
        }

        return $query->where($filterColumn, 'like', '%' . $filterValue . '%');
    }
}
