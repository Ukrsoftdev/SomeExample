<?php

namespace SomePath\BackOffice\SomePath\TransferFlow\Rules;

use Illuminate\Contracts\Validation\Rule;
use SomePath\BackOffice\SomePath\TransferFlow\Repositories\TransferOrderRepositoryInterface;
use TransferOrderModel;

class ChangeTransferOrderRule implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     * @param mixed $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        /** @var TransferOrderModel $transferOrder */
        $transferOrder = app()->make(TransferOrderRepositoryInterface::class)->find($value);

        if (!$transferOrder) {
            return false;
        }

        if ($transferOrder->isDispatched()) {
            return false;
        }

        if ($transferOrder->areAllLinesReceived()) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'This TransferOrder cannot be changed.';
    }
}
