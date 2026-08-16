<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;

class LabelPrinterController extends Controller
{
    /**
     * Render the HTML view for printing the shipping label alongside product images.
     */
    public function print(Order $order)
    {
        // For safety, ensure user has access to this order
        // (Assuming standard auth for now, or you can add policy checks)
        abort_if(!$order->paid_at, 403, 'Order must be paid before printing the label.');

        // This simulates downloading or having the label URL
        $simulator = app(\App\Services\ShippingLabelService::class);
        $status = $simulator->checkLabelStatus($order);

        $labelUrl = $order->label_url ?? ($status['label_url'] ?? null);

        // Update the timeline event if it's the first time printing
        if (!$order->label_printed_at && $labelUrl) {
            $order->update(['label_printed_at' => now(), 'order_processing_status' => 'preparing']);
            \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($order->id, 'label_printed'); // MUL-276
            $order->events()->create([
                'event_type' => 'etiqueta_impressa',
                'description' => 'Etiqueta gerada e impressa.',
                'user_id' => auth()->id()
            ]);
        }

        $order->load(['items.product.media']);

        return view('labels.print', compact('order', 'labelUrl', 'status'));
    }
}
