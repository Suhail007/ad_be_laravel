<?php

namespace App\Jobs;

use App\Mail\OrderSuccess;
use Illuminate\Bus\Queueable as BusQueueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, BusQueueable, SerializesModels;

    protected $orderNumber;
    protected $name;
    protected $deliveryDate;
    protected $businessAddress;
    protected $email;

    /**
     * Create a new job instance.
     *
     * @param string $email
     * @param string $orderNumber
     * @param string $name
     * @param string $deliveryDate
     * @param string $businessAddress
     */
    public function __construct($email, $newValue, $username, $deliveryDate, $businessAddress)
    {
        $this->orderNumber = $newValue;
        $this->name = $username;
        $this->deliveryDate = $deliveryDate;
        $this->businessAddress = $businessAddress;
        $this->email = $email;
    }
    

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to($this->email)->send(new OrderSuccess($this->orderNumber,$this->name,$this->deliveryDate,$this->businessAddress));
    }
}
