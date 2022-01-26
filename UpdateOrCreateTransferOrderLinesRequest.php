<?php

namespace SomePath\BackOffice\Http\Requests\TransferOrder;

use Illuminate\Foundation\Http\FormRequest;
use SomePath\BackOffice\Models\DB\ProductModel;
use SomePath\BackOffice\SomePath\TransferFlow\Rules\ChangeTransferOrderRule;
use SomePath\BackOffice\SomePath\TransferFlow\Rules\DeleteTransferOrderLineRule;
use SomePath\BackOffice\SomePath\TransferFlow\Rules\UpdateTransferOrderLineRule;
use TransferLineModel;
use TransferOrderModel;

class UpdateOrCreateTransferOrderLinesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'digits_between:1,99999999999', 'exists:' . TransferOrderModel::getTableName() . ',id', new ChangeTransferOrderRule],
            'requested_delivery_date' => 'nullable|date_format:Y-m-d|after_or_equal:today',

            'transfer_order_lines.exist-lines' => 'present',
            'transfer_order_lines.exist-lines.*.id' => ['sometimes', 'required', 'integer', 'digits_between:1,99999999999', 'exists:' . TransferLineModel::getTableName() . ',id', new UpdateTransferOrderLineRule($this->all())],
            'transfer_order_lines.exist-lines.*.quantity' => 'sometimes|present|integer|digits_between:0,99999999999|nullable',
            'transfer_order_lines.exist-lines.*.quantity_received' => 'sometimes|present|integer|digits_between:0,99999999999|nullable',

            'transfer_order_lines.new-lines' => 'present',
            'transfer_order_lines.new-lines.*.pid' => 'sometimes|required|integer|digits_between:1,99999999999|exists:' . ProductModel::getTableName() . ',pid',
            'transfer_order_lines.new-lines.*.quantity' => 'sometimes|present|integer|digits_between:0,99999999999|nullable',
            'transfer_order_lines.new-lines.*.quantity_received' => 'sometimes|present|integer|digits_between:0,99999999999|nullable',

            'transfer_order_lines.delete-lines' => 'present',
            'transfer_order_lines.delete-lines.*.id' => ['sometimes', 'required', 'integer', 'digits_between:1,9999999999', 'exists:' . TransferLineModel::getTableName() . ',id', new DeleteTransferOrderLineRule],
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    public function prepareForValidation()
    {
        $data = $this->toArray();
        data_set($data, 'id', (int)$this->id);
        $this->merge($data);
    }
}
