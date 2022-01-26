<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\Paginator;
use SomePath\BackOffice\Http\Requests\TransferOrder\AddLineItemRequest;
use SomePath\BackOffice\Http\Requests\TransferOrder\TransferOrderRequest;
use SomePath\BackOffice\Http\Resources\TransferOrderLinesResource;
use SomePath\BackOffice\Http\Resources\TransferOrderWarehouseInfoResource;
use SomePath\BackOffice\Http\Resources\LogMessagesResource;
use SomePath\BackOffice\Http\Resources\TransferOrderDetailsInfoResource;
use SomePath\BackOffice\Http\Resources\TransferOrderShipmentResource;
use SomePath\BackOffice\Http\Requests\TransferOrder\CheckLineItemRequest;
use SomePath\BackOffice\Http\Requests\TransferOrder\UpdateOrCreateTransferOrderLinesRequest;
use SomePath\BackOffice\SomePath\TransferFlow\Repositories\TransferOrderRepositoryInterface;
use SomePath\BackOffice\SomePath\TransferFlow\Services\TransferOrderServiceInterface;
use SomePath\OrdersProcessor\Transfer\Entity\TransferOrder as TO;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use SomePath\BackOffice\SomePath\TransferFlow\Services\TransferOrderService;
use SomePath\OrdersProcessor\Transfer\ERPSync\SyncToERPJob;
use SomePath\OrdersProcessor\Transfer\Events\EDIOrderUpdatedEvent;

class TransferOrderController extends BaseController
{
    /**
     * Transfer Orders list page
     *
     * @return \View
     */
    public function index()
    {
        $this->setActiveMenuItem('transfer_orders_list');

        return View::make('transfer_order/index');
    }

    public function show($id)
    {
        $this->setActiveMenuItem('transfer_orders_list');

        try {
            $order = TO::with([
                    'warehouseOutgoingOrder',
                    'warehouseIncomingOrder',
                    'fromStorage',
                    'toStorage',
                    'transferLines',
                    'SomePathLogMessages' => function ($q) {
                        $q->orderBy('created_at', 'desc')->take(25);
                    }])->findOrFail($id);
        } catch (\Exception $e) {
            return 'Transfer order not found!';
        }

        $data = ['order' => $order];

        return View::make('transfer_order/single', $data);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param $id
     * @return JsonResponse
     */
    public function getTransferOrderMessages(\Illuminate\Http\Request $request, $id): JsonResponse
    {
        if (empty($transferOrder = TO::find($id))) {
            Messager::error('Order not found');
            return response()->json(Messager::mergeWithData(['id' => $id]), 404);
        }

        $page = $request->get('page', 1);
        Paginator::currentPageResolver(function () use ($page) {
            return $page;
        });

        $results = $transferOrder->logEntries()->orderBy('id', 'desc')->paginate(10);

        return response()
            ->json([
                'id' => $id,
                'logEntries' => new LogMessagesResource($results)
            ]);
    }

    /**
     * @param int $id
     * @param TransferOrderServiceInterface $transferOrderService
     * @return JsonResponse
     */
    public function getTransferOrderDetails(int $id, TransferOrderServiceInterface $transferOrderService): JsonResponse
    {
        $sortBy = request()->get('sort_by', 'id');
        $sortMethod = request()->get('sort_method', 'asc');
        $filterColumn = request()->get('filter_column');
        $filterValue = request()->get('filter_value');

        if (empty($transferOrder = $transferOrderService->getTransferOrderForDetailsTab(
            $id,
            $filterColumn,
            $filterValue,
            $sortBy,
            $sortMethod
        ))) {
            Messager::error('Order not found');
            return response()->json(Messager::mergeWithData(['id' => $id]), 404);
        }
        return response()->json(new TransferOrderDetailsInfoResource($transferOrder));
    }

    /**
     * @param $id
     * @return JsonResponse
     */
    public function getTransferOrderShipment($id): JsonResponse
    {
        if (empty($transferOrder = TO::find($id))) {
            Messager::error('Order not found');
            return response()->json(Messager::mergeWithData(['id' => $id]), 404);
        }
        return response()->json(!empty($transferOrder->transferDispatch)
            ? ['id' => $id, 'shipments' => TransferOrderShipmentResource::collection($transferOrder->transferDispatch)]
            : ['id' => $id, 'shipments' => []]);
    }

    /**
     * Datatable query
     *
     * @return JSON data
     */
    public function getOrders()
    {
        $ordersTable = TransferOrderModel::getTableName();
        $storageTable = StorageModel::getTableName();
        $storageTableFrom = 'from_storages';
        $storageTableTo = 'to_storages';

        $query = TransferOrderModel::leftJoin(
            $storageTable . ' AS ' . $storageTableFrom,
            $storageTableFrom . '.id',
            '=',
            $ordersTable . '.from_storage_id'
        )->leftJoin(
            $storageTable . ' AS ' . $storageTableTo,
            $storageTableTo . '.id',
            '=',
            $ordersTable . '.to_storage_id'
        )->select(
            $ordersTable . '.id',
            $ordersTable . '.order_no',
            $storageTableFrom . '.name AS from_storage_name',
            $storageTableTo . '.name AS to_storage_name',
            $ordersTable . '.shipment_service_code',
            $ordersTable . '.shipment_agent_code',
            $ordersTable . '.received'
        )->orderBy($ordersTable . '.id', 'desc');

        return SDatatable::query($query)
            ->showColumns(
                'id',
                'order_no',
                'from_storage_name',
                'to_storage_name',
                'shipment_agent_code',
                'shipment_service_code',
                'received'
            )
            ->orderColumns(
                'order_no',
                'shipment_service_code',
                'shipment_agent_code',
                'from_storage_name',
                'to_storage_name'
            )
            ->searchColumns('order_no')
            ->setAliasMapping()
            ->make();
    }

    /**
     * @param $id
     * @return JsonResponse
     */
    public function getTransferOrderWarehouse($id): JsonResponse
    {
        if (empty($transferOrder = TO::find($id))) {
            Messager::error('Order not found');
            return response()->json(Messager::mergeWithData(['id' => $id]), 404);
        }
        return response()->json(['id' => $id, 'warehouse' => TransferOrderWarehouseInfoResource::collection($transferOrder->warehouseOutgoingOrder)]);
    }

    /**
     * Synchronizes order with SomePath
     * @param int                              $id
     * @param TransferOrderRepositoryInterface $transferOrderRepository
     *
     * @return JsonResponse
     */
    public function syncToSomePath(
        $id,
        TransferOrderRepositoryInterface $transferOrderRepository
    ) {
        try {
            $transferOrder = $transferOrderRepository->find($id);
            if (is_null($transferOrder)) {
                throw new Exception('Transfer order was not found');
            }
            $synced = SomePathOrdersProcessor::syncOrder($transferOrder);
            if (!$synced) {
                throw new Exception('No actions where executed on transfer order');
            }
            Messager::success('Order was successfully synchronized with SomePath.');
        } catch (Exception $e) {
            Messager::error($e->getMessage());
        }

        return response()->json(Messager::mergeWithData());
    }

    /**
     * Synchronizes order with SomePath
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncToErp(
        $id,
        TransferOrderRepositoryInterface $transferOrderRepository
    ) {
        try {
            $transferOrder = $transferOrderRepository->find($id);
            if (is_null($transferOrder)) {
                throw new Exception('Transfer order was not found');
            }
            if (dispatch_now(new SyncToERPJob($transferOrder))) {
                Messager::success('Order was successfully synchronized with ERP.');
            } else {
                throw new Exception('Order synchronization with ERP failed.');
            }
        } catch (Exception $e) {
            Messager::error($e->getMessage());
        }

        return response()->json(Messager::mergeWithData());
    }

    /**
     * @param TransferOrderRequest $id
     * @param UpdateOrCreateTransferOrderLinesRequest $request
     * @param TransferOrderServiceInterface $transferOrderService
     * @return JsonResponse
     */
    public function updateOrCreateTransferOrderLines(
        UpdateOrCreateTransferOrderLinesRequest $request,
        TransferOrderServiceInterface           $transferOrderService,
        TransferOrderRepositoryInterface        $transferOrderRepository
    ): JsonResponse
    {
        /** @var TO $transferOrder */
        $transferOrder = $transferOrderRepository->find($request->validated()['id']);

        try {
            $data = $request->validated()['transfer_order_lines'];
            $forceDeliveryDateUpdate = isset($request->validated()['requested_delivery_date']);

            DB::beginTransaction();
            $transferOrderService->updateLines($data['exist-lines'], $transferOrder);
            $transferOrderService->insertLines($data['new-lines'], $transferOrder);
            $transferOrderService->deleteLines($data['delete-lines'], $transferOrder);

            if ($forceDeliveryDateUpdate) {
                $requestedDeliveryDate = empty($request->validated()['requested_delivery_date']) ?
                    null : $request->validated()['requested_delivery_date'];
                $transferOrderRepository->update($transferOrder->id, ['requested_delivery_date' => $requestedDeliveryDate]);
            }

            $transferOrder->load('transferLines');

            if (!count($transferOrder->transferLines)) {
                $result = $transferOrderService->cancelTransferOrder($request->validated()['id'], 'All lines were deleted from order!', auth()->user());

                if (! $result->isSuccess()) {
                    foreach ($result->getErrors() as $error) {
                        Messager::error($error);
                    }
                    DB::rollBack();

                    return response()->json(Messager::mergeWithData(['id' => $request->validated()['id']]), 422);
                }

                Messager::success('Order was cancelled');
            } else {
                event(new EDIOrderUpdatedEvent($transferOrder));
            }

            Event::dispatch('transfer_order.updated', ['order' => $transferOrder]);
            Messager::success('Order was updated');
            DB::commit();
            return response()->json(Messager::mergeWithData(['id' => $transferOrder->id]), 202);
        } catch (Exception $e) {
            Messager::error($e->getMessage());
            DB::rollBack();
            return response()->json(Messager::mergeWithData(['id' => $request->validated()['id']]), 422);
        }
    }

    /**
     * @param TransferOrderRequest $id
     * @param AddLineItemRequest $request
     * @param TransferOrderServiceInterface $transferOrderService
     * @return JsonResponse
     */
    public function addLineItem(
        AddLineItemRequest               $request,
        TransferOrderServiceInterface    $transferOrderService,
        TransferOrderRepositoryInterface $transferOrderRepository
    ): JsonResponse
    {
        $transferOrder = $transferOrderRepository->find($request->validated()['id']);
        $line = $transferOrderService->addLineItemRequest($request->validated(), $transferOrder);
        $line->quantity_dispatched = $line->quantity_dispatched ?? 0; // fix null quantity_dispatched on new model
        $stocks = $transferOrderService->calculateLineStock((int)$request->validated()['quantity'], $line->pid, $transferOrder->from_storage_id);

        $line->calculated_quantity_remaining = $stocks['remaining'];
        $line->calculated_quantity_reserved = $stocks['reserved'];

        return response()->json(['id' => $request->validated()['id'], 'line' => TransferOrderLinesResource::collection([0 => $line])]);
    }

    /**
     * Cancels the TO
     *
     * @param int                  $id
     * @param Request              $request
     * @param TransferOrderService $service
     *
     * @return JsonResponse
     */
    public function cancelOrder($id, Request $request, TransferOrderService $service): JsonResponse
    {
        $result = $service->cancelTransferOrder((int)$id, $request->get('message'), auth()->user());
        if ($result->isSuccess()) {
            Messager::success('The order has been canceled.');

            return response()->json(Messager::mergeWithData(), 202);
        } else {
            return response()->json(['error' => $result->getErrors()], 422);
        }
    }

    /**
     * @param CheckLineItemRequest $request
     * @param TransferOrderServiceInterface $transferOrderService
     * @param TransferOrderRepositoryInterface $transferOrderRepository
     * @return JsonResponse
     */
    public function checkLineItem(
        CheckLineItemRequest             $request,
        TransferOrderServiceInterface    $transferOrderService,
        TransferOrderRepositoryInterface $transferOrderRepository)
    {
        $transferOrder = $transferOrderRepository->find($request->validated()['id']);
        $stocks = $transferOrderService->calculateLineStock((int)$request->validated()['quantity'], (int)$request->validated()['pid'], $transferOrder->from_storage_id);

        return response()->json(['id' => $request->validated()['id'], 'stocks' => $stocks]);
    }
}
