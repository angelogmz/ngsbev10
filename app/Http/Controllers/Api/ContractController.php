<?php

namespace App\Http\Controllers\Api;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class ContractController extends Controller
{
    public function index(){
        $contracts = Contract::all();

        if( $contracts->count() > 0 ){
            return response()->json([
                'status' => 200,
                'customers' => $contracts
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'no records found'
            ], 200);
        }
    }

    public function findContract($contract_no){
        $contractDetails = Contract::where('contract_no', $contract_no)->first();
        if($contractDetails){
            return response()->json([
                'status' => 200,
                'contract' => $contractDetails
            ], 200);
        }
        else{

            return response()->json([
                'status' => 404,
                'message' => 'No such Contract found'
            ], 500);
        }
    }

    public function addContract(Request $request){

        $validator = Validator::make($request->all(), [
            'contract_no' => 'required|string',
            'customer_id' => 'required|int',
            'loan_type' => 'required|string',
            'loan_amount' => 'required',
            'cost' => 'required',
            'apr' => 'required',
            'term' => 'required',
            'pay_freq' => 'required|string',
            'due_date' => 'required|string',
            'installments' => 'required|numeric',
            'total_payment' => 'required',
            'total_interest' => 'required',
            'def_int_rate' => 'required',
            'compounding' => 'string'
        ]);


        if($validator->fails()){
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }
        else{
           $contract = Contract::create([
            'contract_no' => $request->contract_no,
            'customer_id' => $request->customer_id,
            'loan_type' => $request->loan_type,
            'loan_amount' => $request->loan_amount,
            'cost' => $request->cost,
            'apr' => $request->apr,
            'term' => $request->term,
            'pay_freq' => $request->pay_freq,
            'due_date' => $request->due_date,
            'installments' => $request->installments,
            'total_payment' => $request->total_payment,
            'total_interest' => $request->total_interest,
            'def_int_rate' => $request->def_int_rate,
            'compounding'=> $request->compounding
            ]);

            if($contract){
                return response()->json([
                    'status' => 200,
                    'message' => 'Contract Added succesfully!'
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

    public function editContract($id){
        $contract = Contract::find($id);
        if($contract){
            return response()->json([
                'status' => 200,
                'contract' => $contract
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'No such contract found'
            ], 404);
        }
    }

    public function updateContract(Request $request, $id){

        $validator = Validator::make($request->all(), [
            'contract_no' => 'required|string',
            'customer_id' => 'required|int',
            'loan_type' => 'required|string',
            'loan_amount' => 'required',
            'cost' => 'required',
            'apr' => 'required',
            'term' => 'required',
            'pay_freq' => 'required|string',
            'due_date' => 'required|string',
            'installments' => 'required|numeric',
            'total_payment' => 'required',
            'total_interest' => 'required',
            'def_int_rate' => 'required',
            'compounding' => 'string'
        ]);

        if($validator->fails()){
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }
        else{
            $contract = Contract::where('contract_no', $id)->first();
            if(!$contract){
                return response()->json([
                    'status' => 404,
                    'message' => 'Contract not found'
                ], 404);
            }
            else{
                $contract->update([
                    'contract_no' => $request->contract_no,
                    'customer_id' => $request->customer_id,
                    'loan_type' => $request->loan_type,
                    'loan_amount' => $request->loan_amount,
                    'cost' => $request->cost,
                    'apr' => $request->apr,
                    'term' => $request->term,
                    'pay_freq' => $request->pay_freq,
                    'due_date' => $request->due_date,
                    'installments' => $request->installments,
                    'total_payment' => $request->total_payment,
                    'total_interest' => $request->total_interest,
                    'def_int_rate' => $request->def_int_rate,
                    'compounding' => $request->compounding,
                ]);

                return response()->json([
                    'status' => 200,
                    'message' => 'Contract updated successfully!',
                    'data' => $contract
                ], 200);
            }


        }
    }



    //Multiple Contracts per Customer


    public function addCustomerToContract(Request $request, $contractId)
    {
        $validator = Validator::make($request->all(), [
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'exists:customers,id', // Validate each customer ID exists in customers table
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        $contract = Contract::findOrFail($contractId);

        // Attach customers to the contract
        $contract->customers()->attach($request->input('customer_ids'));

        return response()->json([
            'status' => 200,
            'message' => 'Customers added to contract successfully'
        ], 200);
    }

    public function removeCustomerFromContract(Request $request, $contractId)
    {
        $validator = Validator::make($request->all(), [
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'exists:customers,id', // Validate each customer ID exists in customers table
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        $contract = Contract::findOrFail($contractId);

        // Detach customers from the contract
        $contract->customers()->detach($request->input('customer_ids'));

        return response()->json([
            'status' => 200,
            'message' => 'Customers removed from contract successfully'
        ], 200);
    }
}
