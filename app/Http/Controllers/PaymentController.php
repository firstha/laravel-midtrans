<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function create(Request $request)
    {
        $orderId = (string) Str::uuid();

        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $request->price,
            ],
            'item_details' => [
                [
                    'price' => (int) $request->price,
                    'quantity' => 1,
                    'name' => $request->item_name,
                ]
            ],
            'customer_details' => [
                'first_name' => $request->customer_first_name,
                'email' => $request->customer_email,
            ],
            'enabled_payments' => [
                'credit_card',
                'bca_va',
                'bni_va',
                'bri_va'
            ]
        ];

        $auth = base64_encode(env('MIDTRANS_SERVER_KEY') . ':');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => "Basic $auth",
        ])->post('https://app.sandbox.midtrans.com/snap/v1/transactions', $params);

        if (!$response->successful()) {
            return response()->json([
                'error' => true,
                'message' => $response->json()
            ], 500);
        }

        $result = $response->json();

        Payment::create([
            'order_id' => $orderId,
            'status' => 'pending',
            'price' => $request->price,
            'customer_first_name' => $request->customer_first_name,
            'customer_email' => $request->customer_email,
            'item_name' => $request->item_name,
            'checkout_link' => $result['redirect_url'] ?? null,
        ]);

        return response()->json($result);
    }

    public function webhook(Request $request)
    {
        if (!$request->order_id) {
            return response()->json(['error' => 'Order ID tidak ditemukan'], 400);
        }

        $auth = base64_encode(env('MIDTRANS_SERVER_KEY') . ':');

        $response = Http::withHeaders([
            'Authorization' => "Basic $auth",
        ])->get("https://api.sandbox.midtrans.com/v2/{$request->order_id}/status");

        if (!$response->successful()) {
            return response()->json(['error' => 'Gagal memverifikasi transaksi'], 500);
        }

        $result = $response->json();

        $payment = Payment::where('order_id', $result['order_id'])->first();

        if (!$payment) {
            return response()->json(['error' => 'Data pembayaran tidak ditemukan'], 404);
        }

        if (in_array($payment->status, ['settlement', 'capture'])) {
            return response()->json('Transaksi sudah diproses sebelumnya');
        }

        switch ($result['transaction_status']) {
            case 'capture':
                $payment->status = 'capture';
                break;
            case 'settlement':
                $payment->status = 'settlement';
                break;
            case 'pending':
                $payment->status = 'pending';
                break;
            case 'deny':
                $payment->status = 'deny';
                break;
            case 'expire':
                $payment->status = 'expire';
                break;
            case 'cancel':
                $payment->status = 'cancel';
                break;
            default:
                $payment->status = $result['transaction_status'];
                break;
        }

        $payment->save();

        return response()->json('Status pembayaran berhasil diperbarui');
    }
}