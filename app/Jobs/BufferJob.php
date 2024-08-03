<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BufferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('buffer started' . now());
        try {
            $buffers = DB::table('buffers')->get();
    
            foreach ($buffers as $buffer) {
                $orderItem = DB::table('wp_woocommerce_order_items')
                    ->where('order_id', $buffer->order_id)
                    ->where('order_item_name', $buffer->shipping)
                    ->first();
    
                if ($orderItem) {
                    $orderShipping = DB::table('wp_postmeta')
                        ->where('post_id', $buffer->order_id)
                        ->where('meta_key', '_order_shipping')
                        ->value('meta_value');
    
                    if ($orderShipping === '0') {
                        DB::table('wp_postmeta')
                            ->where('post_id', $buffer->order_id)
                            ->where('meta_key', '_order_shipping')
                            ->update(['meta_value' => '15']);
    
                        DB::table('buffers')
                            ->where('id', $buffer->id)
                            ->delete();
                        Log::info($buffer->order_id.' shipping charges updated');
                    }
                }
            }
        } catch (\Throwable $th) {
            Log::error('Error processing buffers: ' . $th->getMessage());
        }
        Log::info('buffer ended' . now());
    }
}
