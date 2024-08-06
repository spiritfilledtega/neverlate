<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Cashfree\Payment\Gateway as Cashfree;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Jobs\SendPushNotification;
use App\Models\UserWallet;
use App\Models\UserWalletHistory;
use App\Models\DriverWallet;
use App\Models\DriverWalletHistory;
use App\Models\OwnerWallet;
use App\Models\OwnerWalletHistory;
use App\Models\RequestModel;
use App\Transformers\WalletTransformer;
use App\Transformers\DriverWalletTransformer;
use App\Transformers\OwnerWalletTransformer;
use App\Enums\WalletRemarks;
use App\Enums\Role;
use App\Http\Controllers\Controller;

class CashfreeController extends Controller
{
public function index(Request $request)
{
    $amount = $request->input('amount');
    $payment_for = $request->input('payment_for');
    $user_id = (integer)$request->input('user_id');
    $request_id = $request->input('request_id');

    $user = User::find($user_id);
    $currency = $user->countryDetail->currency_code ?? "INR";
    $phone_mobile = $user->mobile; // Assuming 'mobile' is the field name in your User model

    $public_key = config('services.cashfree.public_key'); // Your public key from Cashfree

    // Generate a unique order ID
    $orderId = 'order_' . time();

    // Create a payment session and get the session ID
    try {
        $paymentSessionId = $this->createPaymentSession($amount, $currency, $orderId);
    } catch (\Exception $e) {
        // Handle the error, log it, and return an error response if necessary
        return response()->json(['error' => $e->getMessage()], 500);
    }

    return view('cashfree.cashfree', compact('amount', 'payment_for', 'currency', 'user_id', 'user', 'request_id', 'public_key', 'paymentSessionId', 'phone_mobile'));
}
private function createPaymentSession($amount, $currency, $orderId)
{
    $url = 'https://api.cashfree.com/api/v2/checkout/orders'; // Adjust URL as needed
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . config('services.cashfree.secret_key') // Your Cashfree secret key
    ];

    $data = [
        'orderId' => $orderId,
        'orderAmount' => $amount,
        'orderCurrency' => $currency,
        'customerEmail' => 'customer@example.com', // Replace with actual email
        'customerPhone' => '1234567890', // Replace with actual phone number
        'orderNote' => 'Payment for ' . $orderId,
        // Add additional required parameters as per Cashfree documentation
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $errorMessage = curl_error($ch);
        curl_close($ch);
        throw new \Exception('Request Error: ' . $errorMessage);
    }
    curl_close($ch);

    $responseData = json_decode($response, true);

    if (isset($responseData['status']) && $responseData['status'] == 'SUCCESS') {
        return $responseData['paymentSessionId']; // Extract and return the payment session ID
    } else {
        // Log the detailed error response for debugging
        $errorMessage = isset($responseData['message']) ? $responseData['message'] : 'Unknown error';
        throw new \Exception('Failed to create payment session: ' . $errorMessage);
    }
}


    private function handleWalletPayment($amount, $user_id, $request)
    {
        $user = User::find($user_id);

        if ($user->hasRole('user')) {
            $wallet_model = new UserWallet();
            $wallet_add_history_model = new UserWalletHistory();
            $user_id = $user->id;
        } elseif ($user->hasRole('driver')) {
            $wallet_model = new DriverWallet();
            $wallet_add_history_model = new DriverWalletHistory();
            $user_id = $user->driver->id;
        } else {
            $wallet_model = new OwnerWallet();
            $wallet_add_history_model = new OwnerWalletHistory();
            $user_id = $user->owner->id;
        }

        $user_wallet = $wallet_model::firstOrCreate(['user_id' => $user_id]);
        $user_wallet->amount_added += $amount;
        $user_wallet->amount_balance += $amount;
        $user_wallet->save();
        $user_wallet->fresh();

        $wallet_add_history_model::create([
            'user_id' => $user_id,
            'amount' => $amount,
            'transaction_id' => $request->PayerID,
            'remarks' => WalletRemarks::MONEY_DEPOSITED_TO_E_WALLET,
            'is_credit' => true
        ]);

        $title = trans('push_notifications.amount_credited_to_your_wallet_title', [], $user->lang);
        $body = trans('push_notifications.amount_credited_to_your_wallet_body', [], $user->lang);

        dispatch(new SendPushNotification($user, $title, $body));

        if ($user->hasRole(Role::USER)) {
            $result = fractal($user_wallet, new WalletTransformer());
        } elseif ($user->hasRole(Role::DRIVER)) {
            $result = fractal($user_wallet, new DriverWalletTransformer());
        } else {
            $result = fractal($user_wallet, new OwnerWalletTransformer());
        }
    }

    private function handleRequestPayment($amount, $request_id, $request)
    {
        $request_detail = RequestModel::where('id', $request_id)->first();

        $web_booking_value = $request_detail->web_booking;
        $request_detail->update(['is_paid' => true]);
        $driver_commission = $request_detail->requestBill->driver_commision;

        $wallet_model = new DriverWallet();
        $wallet_add_history_model = new DriverWalletHistory();
        $user_id = $request_detail->driver_id;

        $user_wallet = $wallet_model::firstOrCreate(['user_id' => $user_id]);
        $user_wallet->amount_added += $amount;
        $user_wallet->amount_balance += $amount;
        $user_wallet->save();
        $user_wallet->fresh();

        $wallet_add_history_model::create([
            'user_id' => $user_id,
            'amount' => $amount,
            'transaction_id' => $request->PayerID,
            'remarks' => WalletRemarks::TRIP_COMMISSION_FOR_DRIVER,
            'is_credit' => true
        ]);

        $title = trans('push_notifications.amount_credited_to_your_wallet_title', [], $request_detail->driverDetail->user->lang);
        $body = trans('push_notifications.amount_credited_to_your_wallet_body', [], $request_detail->driverDetail->user->lang);

        dispatch(new SendPushNotification($request_detail->driverDetail->user, $title, $body));

        $this->database->getReference('requests/' . $request_detail->id)->update(['is_paid' => 1]);
    }
}
