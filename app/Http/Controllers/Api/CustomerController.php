<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;



class CustomerController extends Controller
{
    public function index(){
        $customers = Customer::all();

        if( $customers->count() > 0 ){
            return response()->json([
                'status' => 200,
                'customers' => $customers
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'no records found'
            ], 200);
        }
    }

    public function addCustomer(Request $request){

        //dd($request);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:5',
            'name' => 'required|string|max:200',
            'contract_no' => 'required|string',
            'nic' => 'required|string|max:12',
            'date_of_birth' => 'required|string',
            'civil_status' => 'required|string|max:12',
            'contact_no' => 'required|digits:10',
            'address'=> 'required|string|max:200',
            'email'=> 'nullable|email|max:50',
            'centre'=> 'required|string|max:200',
            'remarks' => 'nullable|string|max:300'
        ]);


        if($validator->fails()){
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }
        else{
           $customer = Customer::create([
                'title' => $request->title,
                'name' => $request->name,
                'contract_no' => $request->contract_no,
                'nic' => $request->nic,
                'date_of_birth' => $request->date_of_birth,
                'civil_status' => $request->civil_status,
                'contact_no' => $request->contact_no,
                'address'=> $request->address,
                'email'=> $request->email,
                'centre'=>$request->centre,
                'remarks' => $request->remarks,
            ]);

            if($customer){
                return response()->json([
                    'status' => 200,
                    'message' => 'Customer Added succesfully!',
                    'customer_id' => $customer->id
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

    public function findCustomer($id){
        $customer = Customer::find($id);
        if($customer){
            return response()->json([
                'status' => 200,
                'customer' => $customer
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'No such customer found'
            ], 500);
        }
    }

    public function searchCustomer($nic){
        $cusSearch = Customer::where('nic', $nic)->get();
        if($cusSearch){
            return response()->json([
                'status' => 200,
                'customer' => $cusSearch
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'No such customer found'
            ], 500);
        }
    }

    public function searchByContract($contract_no){
        $contrctSearch = Customer::where('contract_no', $contract_no)->get();
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

    public function editCustomer($id){
        $customer = Customer::find($id);
        if($customer){
            return response()->json([
                'status' => 200,
                'customer' => $customer
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'No such customer found'
            ], 404);
        }
    }

    public function updateCustomer(Request $request, int $id){

        // Check all request values
        $requestData = $request->all();
        //dd($requestData);


        $validator = Validator::make($requestData, [
            'title' => 'required|string|max:5',
            'name' => 'required|string|max:200',
            'contract_no' => 'required|string',
            'nic' => 'required|string|max:12',
            'date_of_birth' => 'required|string',
            'civil_status' => 'required|string',
            'contact_no' => 'required|digits:10',
            'address'=> 'required|string|max:200',
            'email'=> 'nullable|email|max:50',
            'centre'=> 'required|string|max:200',
            'remarks' => 'nullable|string|max:300'
        ]);


        if($validator->fails()){
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }
        else{
            $customer = Customer::find($id);


            if($customer){
                $customer->update([
                    'title' => $request->title,
                    'name' => $request->name,
                    'contract_no' => $request->contract_no,
                    'nic' => $request->nic,
                    'date_of_birth' => $request->date_of_birth,
                    'civil_status' => $request->civil_status,
                    'contact_no' => $request->contact_no,
                    'address'=> $request->address,
                    'email'=> $request->email,
                    'centre'=>$request->centre,
                    'remarks' => $request->remarks,
                ]);
                return response()->json([
                    'status' => 200,
                    'message' => 'Customer Details Updated succesfully!'
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

    public function updateCustomerContracts(Request $request, int $id){

        // Check all request values
        $requestData = $request->all();
        //dd($requestData);

        $validator = Validator::make($requestData, [
            'contract_no' => 'required|string',
        ]);

        if($validator->fails()){
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        } else {
            $customer = Customer::find($id);

            if($customer){
                // Fetch the existing contract numbers and append the new contract number
                $existingContracts = $customer->contract_no ? explode(',', $customer->contract_no) : [];
                $existingContracts[] = $request->contract_no;
                $updatedContracts = implode(',', $existingContracts);

                // Update the customer record with the updated contract numbers
                $customer->update([
                    'contract_no' => $updatedContracts
                ]);

                return response()->json([
                    'status' => 200,
                    'message' => 'Customer Details Updated successfully!'
                ], 200);
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => 'Customer not found'
                ], 500);
            }
        }
    }


    //Multiple Contracts per Customer

    public function addContractToCustomer(Request $request, $customerId)
    {
        $validator = Validator::make($request->all(), [
            'contract_ids' => 'required|array',
            'contract_ids.*' => 'exists:contracts,id', // Validate each contract ID exists in contracts table
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        $customer = Customer::findOrFail($customerId);

        // Attach contracts to the customer
        $customer->contracts()->attach($request->input('contract_ids'));

        return response()->json([
            'status' => 200,
            'message' => 'Contracts added to customer successfully'
        ], 200);
    }

    public function removeContractFromCustomer(Request $request, $customerId)
    {
        $validator = Validator::make($request->all(), [
            'contract_ids' => 'required|array',
            'contract_ids.*' => 'exists:contracts,id', // Validate each contract ID exists in contracts table
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        $customer = Customer::findOrFail($customerId);

        // Detach contracts from the customer
        $customer->contracts()->detach($request->input('contract_ids'));

        return response()->json([
            'status' => 200,
            'message' => 'Contracts removed from customer successfully'
        ], 200);
    }
}
