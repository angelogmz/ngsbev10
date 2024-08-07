<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\guarantor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GuarantorController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    public function addGuarantor(Request $request){

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:5',
            'name' => 'required|string|max:200',
            'contract_no' => 'required|string',
            'nic' => 'required|string|max:12',
            'date_of_birth' => 'required|string',
            'contact_no' => 'required|digits:10',
            'address'=> 'required|string|max:200',
            'email'=> 'nullable|email|max:50'
        ]);


        if($validator->fails()){
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }
        else{
           $customer = Guarantor::create([
                'title' => $request->title,
                'name' => $request->name,
                'contract_no' => $request->contract_no,
                'nic' => $request->nic,
                'date_of_birth' => $request->date_of_birth,
                'contact_no' => $request->contact_no,
                'address'=> $request->address,
                'email'=> $request->email
            ]);

            if($customer){
                return response()->json([
                    'status' => 200,
                    'message' => 'Guarantor Added succesfully!'
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

    public function searchByContract($contract_no){
        $contrctSearch = Guarantor::where('contract_no', $contract_no)->get();
        if($contrctSearch){
            return response()->json([
                'status' => 200,
                'contract' => $contrctSearch
            ], 200);
        }
        else{
            return response()->json([
                'status' => 404,
                'message' => 'No such Guarantor found'
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\guarantor  $guarantor
     * @return \Illuminate\Http\Response
     */
    public function show(guarantor $guarantor)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\guarantor  $guarantor
     * @return \Illuminate\Http\Response
     */
    public function edit(guarantor $guarantor)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\guarantor  $guarantor
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, guarantor $guarantor)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\guarantor  $guarantor
     * @return \Illuminate\Http\Response
     */
    public function destroy(guarantor $guarantor)
    {
        //
    }
}
