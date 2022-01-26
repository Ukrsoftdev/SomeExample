<?php

namespace SomePath\BackOffice\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransferOrderLinesResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'pid' => $this->pid,
            'order_no' => $this->order_no,
            'line_no' => $this->line_no,
            'parent_line_no' => $this->parent_line_no,
            'item_no' => $this->item_no,
            'name' => $this->name,
            'manufacturer_item_no' => $this->manufacturer_item_no,
            'quantity_initial' => $this->quantity_initial,
            'quantity' => $this->quantity,
            'quantity_received' => $this->quantity_received,
            'quantity_dispatched' => $this->quantity_dispatched,
            'is_dispatched' => $this->isDispatched(),
            'is_transit'    => $this->is_transit,
            'unit' => $this->unit,
            'created_at' => isset($this->created_at) ? $this->created_at->format('Y-m-d H:i:s') : null,
            'quantity_reserved' => $this->when(
                $this->resource->relationLoaded('outgoingStockQueueEntry') || isset($this->calculated_quantity_reserved),
                $this->outgoingStockQueueEntry->quantity_reserved ?? $this->calculated_quantity_reserved ?? 0),
            'quantity_remaining' => $this->when(
                $this->resource->relationLoaded('outgoingStockQueueEntry') || isset($this->calculated_quantity_remaining),
                $this->outgoingStockQueueEntry->quantity_remaining ?? $this->calculated_quantity_remaining ?? 0),
            'reserved_stock_queue_link' => route('product-stock',
                ['storage_id' => $this->order->from_storage_id, 'item_no' => $this->item_no])
        ];
    }
}
