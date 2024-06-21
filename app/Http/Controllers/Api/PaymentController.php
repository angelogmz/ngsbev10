<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function index(){
        $payments = Payment::all();

        if( $payments->count() > 0 ){
            return response()->json([
                'status' => 200,
                'payments' => $payments
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'no records found'
            ], 200);
        }


    }

    public function findPaymentByContract($contract_no){
        $contrctSearch = Payment::where('contract_no', $contract_no)->get();
        if($contrctSearch){
            return response()->json([
                'status' => 200,
                'contract' => $contrctSearch
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'No such customer found'
            ], 500);
        }
    }

    public function addPayment(Request $request){

        $validator = Validator::make($request->all(), [
            'contract_no' => 'required|string',
            'payment_amount' => 'required|numeric',
            'payment_date' => 'required|date',
        ]);


        if($validator->fails()){
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }
        else{
           $payment = Payment::create([
            'contract_no' => $request->contract_no,
            'payment_amount' => $request->payment_amount,
            'payment_date' => $request->payment_date
            ]);

            if($payment){
                $contractId = DB::table('contracts')->where('contract_no', $request->contract_no)->first();
                $contractTotalP = $contractId->total_payment;
                $upNewTPaidVal = $contractTotalP - $request->payment_amount;

                if($contractId){

                   $contractFind = Contract::find($contractId->id);

                    if($contractFind){
                        $contractFind->update([
                            'total_payment' => $upNewTPaidVal,
                        ]);
                        return response()->json([
                            'status' => 200,
                            'message' => 'Payment added succesfully!'
                        ], 200);
                    }
                    else{
                        return response()->json([
                            'status' => 500,
                            'message' => 'Something went wrong'
                        ], 500);
                    }
                }
                else{

                    return response()->json([
                        'status' => 404,
                        'message' => 'No such Payment found'
                    ], 500);
                }

                return response()->json([
                    'status' => 200,
                    'message' => 'Payment Added succesfully!'
                ], 200);
            }
            else{
                return response()->json([
                    'status' => 500,
                    'message' => 'Something went wrong'
                ], 500);
            }
        }
    }
}
